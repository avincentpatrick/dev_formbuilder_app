<?php

declare(strict_types=1);

use App\Enums\ConnectorProviderKey;
use App\Enums\PlanTier;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connectors\TabularDestinationDirectory;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H16c — the Airtable setup-time sidecars: the base LISTER behind /channels and the table INSPECTOR behind
| /destinations, both reached through the same routes Sheets and Slack already use.
|
| The interesting cases are the ones where Airtable inverts a Sheets assumption:
|   • it ENUMERATES, which `drive.file` cannot — so a base is picked from a list, not pasted;
|   • it deliberately CANNOT create, and the route that exists for every provider has to say so rather
|     than 500 on a capability the adapter does not claim;
|   • `not_found` needs the OPPOSITE advice to Sheets' — "create one here" is nonsense for a provider we
|     can list but may not write schema to.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Http::preventStrayRequests();
    config()->set('tenancy.central_domain', 'meridian.test');

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    assignPlanTier(PlanTier::Starter);

    $this->connection = Connection::factory()->airtable()->create();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function airtableInspectUrl(Connection $connection): string
{
    return 'http://acme.meridian.test/integrations/connections/'.$connection->id.'/destinations';
}

function airtableBasesUrl(Connection $connection): string
{
    return 'http://acme.meridian.test/integrations/connections/'.$connection->id.'/channels';
}

/** One table in Airtable's schema shape. */
function airtableTable(string $id, string $name, array $fields): array
{
    return [
        'id' => $id,
        'name' => $name,
        'fields' => array_map(static fn (string $f): array => ['id' => 'fld'.md5($f), 'name' => $f, 'type' => 'singleLineText'], $fields),
    ];
}

function fakeAirtableSchema(array $tables, array $bases = [['id' => 'appACME0000000001', 'name' => 'Client Intake CRM', 'permissionLevel' => 'create']]): void
{
    Http::fake([
        'api.airtable.com/v0/meta/bases/*/tables' => Http::response(['tables' => $tables], 200),
        'api.airtable.com/v0/meta/bases' => Http::response(['bases' => $bases], 200),
    ]);
}

it('reduces a base to tabs, field names and a stable table id', function (): void {
    fakeAirtableSchema([
        airtableTable('tblRESPONSES00001', 'Responses', ['Full name', 'Colour', 'Notes']),
        airtableTable('tblARCHIVE0000001', 'Archive', ['Full name']),
    ]);

    $payload = $this->actingAs($this->admin)
        ->getJson(airtableInspectUrl($this->connection).'?reference=appACME0000000001')
        ->assertOk()
        ->json();

    expect($payload['error'])->toBeNull()
        ->and($payload['destination']['spreadsheet_id'])->toBe('appACME0000000001')
        // The base NAME, resolved through the lister — Airtable has no "get one base" endpoint, so the
        // caption costs a second call and is best-effort.
        ->and($payload['destination']['title'])->toBe('Client Intake CRM')
        ->and($payload['destination']['tabs'])->toBe(['Responses', 'Archive'])
        ->and($payload['destination']['sheet_name'])->toBe('Responses')
        ->and($payload['destination']['sheet_id'])->toBe('tblRESPONSES00001')
        // VERBATIM field names, in Airtable's order — the mapping binds positionally and the delivery path
        // writes by name, so a normalised or reordered copy would file answers under the wrong fields.
        ->and($payload['destination']['header_row'])->toBe(['Full name', 'Colour', 'Notes'])
        // Table-scoped: a base-only URL opens whichever table the viewer last had open.
        ->and($payload['destination']['url'])->toBe('https://airtable.com/appACME0000000001/tblRESPONSES00001');
});

it('falls back to the base id when the caption cannot be fetched', function (): void {
    // A failure resolving a display NAME must not refuse an inspection that otherwise worked — the tenant
    // already has the name on screen from the picker they just used.
    Http::fake([
        'api.airtable.com/v0/meta/bases/*/tables' => Http::response(['tables' => [airtableTable('tblA0000000000001', 'Responses', ['A'])]], 200),
        'api.airtable.com/v0/meta/bases' => Http::response(['error' => ['type' => 'RATE_LIMIT_REACHED']], 429),
    ]);

    $payload = $this->actingAs($this->admin)
        ->getJson(airtableInspectUrl($this->connection).'?reference=appACME0000000001')
        ->assertOk()
        ->json();

    expect($payload['error'])->toBeNull()
        ->and($payload['destination']['title'])->toBe('appACME0000000001');
});

it('reads a named table rather than falling through to the first', function (): void {
    fakeAirtableSchema([
        airtableTable('tblRESPONSES00001', 'Responses', ['Full name']),
        airtableTable('tblARCHIVE0000001', 'Archive', ['Ref', 'Closed at']),
    ]);

    $payload = $this->actingAs($this->admin)
        ->getJson(airtableInspectUrl($this->connection).'?reference=appACME0000000001&sheet_name=Archive')
        ->assertOk()
        ->json();

    expect($payload['destination']['sheet_name'])->toBe('Archive')
        ->and($payload['destination']['sheet_id'])->toBe('tblARCHIVE0000001')
        ->and($payload['destination']['header_row'])->toBe(['Ref', 'Closed at']);
});

it('refuses a named table that is no longer in the base', function (): void {
    // Silently retargeting the rule at the FIRST table would point it at the wrong data with nothing said.
    fakeAirtableSchema([airtableTable('tblRESPONSES00001', 'Responses', ['Full name'])]);

    $payload = $this->actingAs($this->admin)
        ->getJson(airtableInspectUrl($this->connection).'?reference=appACME0000000001&sheet_name=Gone')
        ->assertOk()
        ->json();

    expect($payload['destination'])->toBeNull()
        ->and($payload['error'])->toBe('That table isn’t in the base any more. Pick another one.');
});

it('gives Airtable the OPPOSITE not-found advice to Google Sheets', function (): void {
    Http::fake(['api.airtable.com/v0/meta/bases/*/tables' => Http::response(['error' => ['type' => 'NOT_FOUND']], 404)]);

    $payload = $this->actingAs($this->admin)
        ->getJson(airtableInspectUrl($this->connection).'?reference=appACME0000000001')
        ->assertOk()
        ->json();

    // Sheets says "create one here instead", which is the whole H16b argument and is nonsense here: we can
    // LIST a tenant's bases and deliberately may not create anything in them.
    expect($payload['error'])->toBe('We can’t open that base. Check it’s still shared with the Airtable account you connected, then pick it again.')
        ->and($payload['error'])->not->toContain('create one here');
});

it('refuses to create a destination for a provider that cannot provision', function (): void {
    // ⚠️ A REACHABLE GUARD. The Airtable editor renders no create control, but the POST route exists for every
    // provider — so a hand-made request lands on a capability the adapter does not claim, and the honest
    // answer is a sentence rather than a 500.
    $payload = $this->actingAs($this->admin)
        ->postJson(airtableInspectUrl($this->connection), ['title' => 'New table', 'headers' => ['A']])
        ->assertOk()
        ->json();

    expect($payload['destination'])->toBeNull()
        ->and($payload['error'])->toBe('Meridian can’t create a table in Airtable. Make one there, then pick it here.');

    // And nothing was attempted — `schema.bases:write` was never requested, so the call would 403 anyway.
    Http::assertNothingSent();
});

it('parses a base id from a bare id or a pasted URL, and refuses anything else', function (): void {
    expect(TabularDestinationDirectory::documentIdFrom(ConnectorProviderKey::Airtable, 'appACME0000000001'))->toBe('appACME0000000001')
        ->and(TabularDestinationDirectory::documentIdFrom(ConnectorProviderKey::Airtable, '  appACME0000000001  '))->toBe('appACME0000000001')
        ->and(TabularDestinationDirectory::documentIdFrom(ConnectorProviderKey::Airtable, 'https://airtable.com/appACME0000000001/tblRESPONSES00001/viwXYZ'))->toBe('appACME0000000001')
        // A table id is not a base id, and accepting one would 404 later with nothing naming the cause.
        ->and(TabularDestinationDirectory::documentIdFrom(ConnectorProviderKey::Airtable, 'tblRESPONSES00001'))->toBeNull()
        ->and(TabularDestinationDirectory::documentIdFrom(ConnectorProviderKey::Airtable, 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz/edit'))->toBeNull()
        ->and(TabularDestinationDirectory::documentIdFrom(ConnectorProviderKey::Airtable, ''))->toBeNull();
});

it('lists the tenant’s bases through the picker sidecar', function (): void {
    Http::fake(['api.airtable.com/v0/meta/bases' => Http::response(['bases' => [
        ['id' => 'appZZZZZZZZZZZZZ2', 'name' => 'Zebra research', 'permissionLevel' => 'create'],
        ['id' => 'appAAAAAAAAAAAAA1', 'name' => 'Applicant tracker', 'permissionLevel' => 'edit'],
        ['id' => 'appRRRRRRRRRRRRR3', 'name' => 'Read-only reports', 'permissionLevel' => 'read'],
    ]], 200)]);

    $payload = $this->actingAs($this->admin)->getJson(airtableBasesUrl($this->connection))->assertOk()->json();

    expect($payload['error'])->toBeNull()
        ->and($payload['truncated'])->toBeFalse()
        ->and(array_column($payload['channels'], 'label'))->toBe(['Applicant tracker', 'Read-only reports', 'Zebra research'])
        // A base the tenant can only READ is LISTED and marked unavailable, following the Slack precedent for
        // a channel the app has not been invited to: hiding it answers "why isn't my base here?" with silence.
        ->and(array_column($payload['channels'], 'available'))->toBe([true, false, true]);
});

it('reports a missing scope as a re-consent condition, not a dead grant', function (): void {
    // Airtable answers 403 when the token is valid but lacks `schema.bases:read` — distinct from 401 because
    // the remedy differs, and telling someone to reconnect when the grant is fine wastes their time.
    Http::fake(['api.airtable.com/v0/meta/bases' => Http::response(['error' => ['type' => 'INVALID_PERMISSIONS']], 403)]);

    $payload = $this->actingAs($this->admin)->getJson(airtableBasesUrl($this->connection))->assertOk()->json();

    expect($payload['channels'])->toBe([])
        ->and($payload['error'])->toBe('The Airtable app is missing the permission needed to list destinations. Reconnect this account to grant it.');
});

it('follows Airtable’s offset pagination and reports a truncated list', function (): void {
    config()->set('connectors.channel_page_limit', 2);

    $page = 0;
    Http::fake(['api.airtable.com/v0/meta/bases*' => function () use (&$page) {
        $page++;

        // Airtable paginates with an opaque `offset` echoed back rather than Slack's `next_cursor`, and its
        // ABSENCE is what ends the walk — an empty string would loop forever.
        return Http::response([
            'bases' => [['id' => 'app'.str_pad((string) $page, 14, '0', STR_PAD_LEFT), 'name' => 'Base '.$page, 'permissionLevel' => 'create']],
            'offset' => 'itr'.$page.'/app'.$page,
        ], 200);
    }]);

    $payload = $this->actingAs($this->admin)->getJson(airtableBasesUrl($this->connection))->assertOk()->json();

    expect($payload['channels'])->toHaveCount(2)
        // The budget ran out with Airtable still offering an offset — say so rather than implying the bases we
        // never asked for do not exist.
        ->and($payload['truncated'])->toBeTrue();
});
