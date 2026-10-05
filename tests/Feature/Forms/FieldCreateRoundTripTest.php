<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M125 — a question fresh from the builder's create route can be saved straight back.
|--------------------------------------------------------------------------
| Found by M125's real-browser probe, measured through the real server: the create response carried `null` for
| `is_pii`, `is_sensitive` and `is_queryable`, because Eloquent never reads back a database default and `FormField`
| declared none. The builder keeps the row it is given and sends it back on the next save, and `UpdateFieldRequest`
| refuses all three as not boolean — so the first edit of EVERY newly added question failed 422 until a reload.
|
| ⛔ THE PATCH BELOW IS BUILT FROM THE CREATE RESPONSE, KEY FOR KEY, the way `fieldPayload()` in the builder's store
| builds it. A test that writes its own booleans into the payload — as every older route test does — cannot see this.
|
| ⚠️ Helpers are prefixed `createRoundTrip*`: Pest loads every test file into one process.
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

/** The builder store's `fieldPayload()`, transcribed: every key it sends, read from the row the server gave it. */
function createRoundTripPayload(array $row, string $label): array
{
    return [
        'key' => $row['key'],
        'label' => $label,
        'hint' => $row['hint'],
        'placeholder' => $row['placeholder'],
        'is_required' => $row['is_required'],
        'relevant_expression' => $row['relevant_expression'],
        'appearance' => $row['appearance'],
        'config' => $row['config'],
        'default_value' => $row['default_value'],
        'default_value_is_expression' => $row['default_value_is_expression'], // M134
        'is_pii' => $row['is_pii'],
        'is_sensitive' => $row['is_sensitive'],
        'is_queryable' => $row['is_queryable'],
        'indexed_data_type' => $row['indexed_data_type'],
        'version' => $row['version'],
        'validations' => $row['validations'],
    ];
}

it('answers a create with real booleans, and takes its own answer straight back', function (string $type): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Fresh Questions');

    $created = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => $type])
        ->assertOk();

    expect($created->json('is_pii'))->toBeFalse()
        ->and($created->json('is_sensitive'))->toBeFalse()
        ->and($created->json('is_queryable'))->toBeFalse()
        ->and($created->json('default_value_is_expression'))->toBeFalse(); // M134 — the builder now sends it back

    test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$created->json('id')}", createRoundTripPayload($created->json(), 'Renamed'))
        ->assertOk()
        ->assertJsonPath('label', 'Renamed');
})->with(['short_text', 'integer', 'note', 'single_select', 'email']);
