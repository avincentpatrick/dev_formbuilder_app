<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\PlanTier;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Submissions\SubmissionRowProjector;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The mappable-column catalog (H16b) — what a spreadsheet column can be bound to.
|
| The invariant worth guarding: these keys must be EXACTLY the keys SubmissionRowProjector will produce at
| delivery time. Offer one it will not produce and the tenant binds a column that is always blank — and a
| blank cell is indistinguishable from an unanswered question, so the mistake never announces itself.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->owner = User::factory()->create(['email' => 'owner@acme.test']);
    $this->tenant = Tenant::create([
        'name' => 'Acme',
        'slug' => 'acme',
        'default_locale' => 'en',
        'owner_user_id' => $this->owner->id,
    ]);
    $this->tenant->domains()->create(['domain' => 'acme']);

    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'admin');
    assignPlanTier(PlanTier::Starter);

    $this->connection = Connection::factory()->googleSheets()->create(['status' => ConnectionStatus::Active]);
    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Intake');
    enterTenant($this->tenant->id, $this->owner->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function columnsUrl(Connection $connection, ?string $formId = null): string
{
    $query = $formId === null ? '' : '?'.http_build_query(['form_id' => $formId]);

    return 'http://acme.meridian.test/integrations/connections/'.$connection->id.'/columns'.$query;
}

it('offers the form’s answer fields alongside the submission metadata', function (): void {
    $response = $this->actingAs($this->owner)->getJson(columnsUrl($this->connection, (string) $this->form->id));

    $response->assertOk()->assertJsonPath('scoped', true);

    $keys = array_column($response->json('columns'), 'key');

    // Every metadata key, from the map whose own docblock names this UI as its reason for existing.
    foreach (array_keys(SubmissionRowProjector::metaLabels()) as $metaKey) {
        expect($keys)->toContain($metaKey);
    }

    // And at least one real answer field, grouped apart from the metadata so the select can separate them.
    $groups = array_unique(array_column($response->json('columns'), 'group'));

    expect($groups)->toContain('Form fields')
        ->and($groups)->toContain('Submission details');
});

it('offers metadata ONLY for an all-forms rule, which is honest rather than a gap', function (): void {
    // `ColumnMapping::project()` writes '' for a key the submission's own version does not define, so an
    // all-forms rule fed from ten forms would produce one populated column per form and blanks everywhere
    // else. Offering those fields would be offering columns most rows can never fill.
    $response = $this->actingAs($this->owner)->getJson(columnsUrl($this->connection));

    $response->assertOk()->assertJsonPath('scoped', false);

    expect(array_unique(array_column($response->json('columns'), 'group')))->toBe(['Submission details']);
});

it('never offers a key the projector will not produce', function (): void {
    // The invariant this whole class exists for, asserted against the projector itself rather than against a
    // list copied out of it — a copy is a second source of truth that agrees exactly once.
    $response = $this->actingAs($this->owner)->getJson(columnsUrl($this->connection, (string) $this->form->id));

    // ⚠️ RE-ENTER THE TENANT AFTER THE REQUEST. The tenancy middleware forgets the GUC in `terminate()`, so
    // a query issued here runs with no tenant context and RLS fails closed — `versions()` comes back EMPTY
    // and the comparison below passes vacuously against an empty expectation. This is the same recipe
    // `GoogleSheetsDeliveryTest` uses after `workOneJob`, and it cost a confusing red run to rediscover:
    // the failure reads as "the catalog invented four keys" when it is the assertion's own side that is blind.
    enterTenant($this->tenant->id, $this->owner->id);

    $versions = $this->form->versions()->orderByDesc('version_number')->get();
    [$projected] = app(SubmissionRowProjector::class)->resolveColumns($versions, 'en');

    // Guard against the vacuous pass this test would otherwise have: an empty expectation matching an empty
    // actual is exactly the shape of the bug above.
    expect($projected)->not->toBeEmpty();

    $offered = array_column($response->json('columns'), 'key');
    $answerKeys = array_values(array_diff($offered, array_keys(SubmissionRowProjector::metaLabels())));

    expect($answerKeys)->toBe(array_keys($projected));
});

it('labels a column by its key when the header would render blank', function (): void {
    // A piped header with nothing to fill from resolves to '' (SubmissionRowProjector::header()), and a blank
    // option is one the tenant cannot tell apart from the next one. The key is always readable.
    $response = $this->actingAs($this->owner)->getJson(columnsUrl($this->connection, (string) $this->form->id));

    foreach ($response->json('columns') as $column) {
        expect(trim((string) $column['label']))->not->toBe('');
    }
});

it('treats another tenant’s form as not found rather than forbidden', function (): void {
    $otherOwner = User::factory()->create(['email' => 'owner@other.test']);
    $other = Tenant::create([
        'name' => 'Other',
        'slug' => 'other',
        'default_locale' => 'en',
        'owner_user_id' => $otherOwner->id,
    ]);
    enterTenant($other->id, $otherOwner->id);
    makeActiveMember($otherOwner, 'admin');
    $foreignForm = publishedInboxForm($other, $otherOwner, 'Foreign');

    enterTenant($this->tenant->id, $this->owner->id);

    // `exists:` runs on the RLS-scoped table, so the row is invisible rather than refused — which discloses
    // nothing about whether it exists at all.
    $this->actingAs($this->owner)
        ->getJson(columnsUrl($this->connection, (string) $foreignForm->id))
        ->assertUnprocessable();
});

it('is gated by the same policy and plan as the rest of the surface', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->getJson(columnsUrl($this->connection))->assertForbidden();
});
