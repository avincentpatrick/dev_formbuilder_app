<?php

declare(strict_types=1);

use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M130 (`R-6c76bed2`) — what a builder save may write into `appearance`.
|--------------------------------------------------------------------------
| A layout the stored type offers (`FieldAppearance::for()`), null, or the value the field already holds,
| unchanged. The last arm is what keeps an XLSForm import editable: the builder sends the whole field back on
| every save, so refusing an imported `minimal` would refuse that question's first edit.
|
| Every PATCH is built from the create response, key for key, the way the builder's `fieldPayload()` builds it
| (`FieldCreateRoundTripTest`'s rule). Helpers are prefixed `appearanceSave*`: Pest loads every test file into
| one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A fresh question of the given type, created through the builder's own route.
 *
 * @return array{0: Tenant, 1: User, 2: string, 3: array<string, mixed>}
 */
function appearanceSaveQuestion(string $type): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Layouts');

    $row = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => $type])
        ->assertOk()
        ->json();

    return [$tenant, $admin, $form->id, $row];
}

/** The builder store's `fieldPayload()`, transcribed, with the appearance under test. */
function appearanceSavePayload(array $row, ?string $appearance): array
{
    return [
        'key' => $row['key'],
        'label' => $row['label'],
        'hint' => $row['hint'],
        'placeholder' => $row['placeholder'],
        'is_required' => $row['is_required'],
        'relevant_expression' => $row['relevant_expression'],
        'appearance' => $appearance,
        'config' => $row['config'],
        'default_value' => $row['default_value'],
        'is_pii' => $row['is_pii'],
        'is_sensitive' => $row['is_sensitive'],
        'is_queryable' => $row['is_queryable'],
        'indexed_data_type' => $row['indexed_data_type'],
        'version' => $row['version'],
        'validations' => $row['validations'],
    ];
}

it('saves a layout the type offers, and clears it again', function (): void {
    // The positive control every refusal below leans on: the same route, the same payload, an allowed value.
    [, $admin, $formId, $row] = appearanceSaveQuestion('single_select');
    $url = "http://acme.meridian.test/forms/{$formId}/fields/{$row['id']}";

    $saved = test()->actingAs($admin)->patchJson($url, appearanceSavePayload($row, 'columns'))
        ->assertOk()
        ->assertJsonPath('appearance', 'columns');

    test()->actingAs($admin)->patchJson($url, appearanceSavePayload($saved->json(), null))
        ->assertOk()
        ->assertJsonPath('appearance', null);
});

it('refuses a layout the type does not offer, and anything typed fresh', function (string $type, string $appearance): void {
    [, $admin, $formId, $row] = appearanceSaveQuestion($type);

    test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$formId}/fields/{$row['id']}", appearanceSavePayload($row, $appearance))
        ->assertStatus(422)
        ->assertJsonValidationErrors('appearance');
})->with([
    'a layout on short text' => ['short_text', 'columns'],
    'a layout on a dropdown, whose export forces minimal' => ['dropdown', 'columns-pack'],
    'minimal on a single choice, which would re-import as a Dropdown' => ['single_select', 'minimal'],
    'the old free-text example' => ['multi_select', 'vertical'],
]);

it('keeps an appearance the field already holds, and lets the author replace it', function (): void {
    [$tenant, $admin, $formId, $row] = appearanceSaveQuestion('multi_select');
    $url = "http://acme.meridian.test/forms/{$formId}/fields/{$row['id']}";

    // What an XLSForm import stores for `select_multiple` + `minimal`: the type map consumes `minimal` only on
    // `select_one`, so here it stays in the column. Written raw so `updated_at`, the row's version, is untouched.
    enterTenant($tenant->id, $admin->id);
    DB::table('form_fields')->where('id', $row['id'])->update(['appearance' => 'minimal']);

    $kept = test()->actingAs($admin)->patchJson($url, appearanceSavePayload($row, 'minimal'))
        ->assertOk()
        ->assertJsonPath('appearance', 'minimal');

    // A DIFFERENT value outside the list is still refused: only the stored one is kept. Each later save carries
    // the newest version token, so a refusal here can only be the appearance rule, never a stale-version 409.
    test()->actingAs($admin)->patchJson($url, appearanceSavePayload($kept->json(), 'likert'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('appearance');

    $replaced = test()->actingAs($admin)->patchJson($url, appearanceSavePayload($kept->json(), 'columns-pack'))
        ->assertOk()
        ->assertJsonPath('appearance', 'columns-pack');

    // Once replaced, the import's value is gone for good — it can never be typed back in.
    test()->actingAs($admin)->patchJson($url, appearanceSavePayload($replaced->json(), 'minimal'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('appearance');

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($row['id'])->value('appearance'))->toBe('columns-pack');
});
