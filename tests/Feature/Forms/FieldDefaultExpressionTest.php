<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M134 (`R-6d7b9ff7`) — a formula default survives the builder's round trip.
|--------------------------------------------------------------------------
| Converting a question to a note clears its default and sets `default_value_is_expression` false; the builder's
| undo then PATCHes the old default back — but the flag was in no presenter key, no request rule and no client
| payload, so an imported `today()` came back as the TEXT "today()". The flag now rides the builder's field row
| both ways: the presenter sends it, the request takes it, the writer stores it when sent and keeps it when not.
|
| ⚠️ Helpers are prefixed `defaultExpression*`: Pest loads every test file into one process.
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
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Imported');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A question as an XLSForm import leaves it: a `calculation` default on a non-calculated row. */
function defaultExpressionField(object $test): FormField
{
    $field = addFormField($test->form->draftVersion, $test->admin, 'visit', FieldType::Date, 0);
    $field->forceFill(['default_value' => 'today()', 'default_value_is_expression' => true])->save();

    return $field->refresh();
}

/** The stored row, re-read under the tenant: a request in this process resets the row-security context (M122). */
function defaultExpressionRow(object $test, FormField $field): FormField
{
    enterTenant($test->tenant->id, $test->admin->id);

    return $field->refresh();
}

/** The builder's PATCH for a field: what `fieldPayload()` sends, from the row the builder holds. */
function defaultExpressionPatch(object $test, FormField $field, array $overrides): TestResponse
{
    return test()->actingAs($test->admin)->patchJson(
        "http://acme.meridian.test/forms/{$test->form->id}/fields/{$field->id}",
        array_merge([
            'key' => $field->key,
            'label' => $field->label,
            'is_required' => $field->is_required->value,
            'config' => $field->config ?? [],
            'default_value' => $field->default_value,
            'version' => FormBuilderService::rowVersion(defaultExpressionRow($test, $field)),
            'validations' => [],
        ], $overrides),
    );
}

it('tells the builder which default is a formula, and a fresh question that its default is not', function (): void {
    $field = defaultExpressionField($this);

    $created = test()->actingAs($this->admin)
        ->postJson("http://acme.meridian.test/forms/{$this->form->id}/fields", ['field_type' => 'short_text'])
        ->assertOk();

    expect($created->json('default_value_is_expression'))->toBeFalse();

    $patched = defaultExpressionPatch($this, $field, ['label' => 'Visit date'])->assertOk();

    expect($patched->json('default_value_is_expression'))->toBeTrue()
        ->and($patched->json('default_value'))->toBe('today()');
});

it('stores the flag when the builder sends it, and keeps the stored one when it does not', function (): void {
    $field = defaultExpressionField($this);

    defaultExpressionPatch($this, $field, ['label' => 'Untouched'])->assertOk();
    expect(defaultExpressionRow($this, $field)->default_value_is_expression)->toBeTrue();

    defaultExpressionPatch($this, $field, ['default_value' => '2026-01-01', 'default_value_is_expression' => false])->assertOk();
    expect(defaultExpressionRow($this, $field))
        ->default_value->toBe('2026-01-01')
        ->default_value_is_expression->toBeFalse();

    // The undo's restoring PATCH: the old default and its flag, together.
    defaultExpressionPatch($this, $field, ['default_value' => 'today()', 'default_value_is_expression' => true])->assertOk();
    expect(defaultExpressionRow($this, $field))
        ->default_value->toBe('today()')
        ->default_value_is_expression->toBeTrue();
});

it('refuses a flag that is not true or false', function (): void {
    $field = defaultExpressionField($this);

    defaultExpressionPatch($this, $field, ['default_value_is_expression' => 'sometimes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('default_value_is_expression');
});
