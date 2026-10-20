<?php

declare(strict_types=1);

use App\Enums\FieldType;
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
| M180 (`R-f77aa45d`) — a calculation's Type, decimal places and units are saved, and checked.
|--------------------------------------------------------------------------
| `calculated_type` (number, text, date or duration), `decimal_places` (0-10, a Number's) and `duration_unit` (a
| Duration's) live in the question's `config`, beside `calculated_formula`; absent, the value shows exactly as before.
| Anything else is refused in the words the editor uses, so a mistyped value never reaches the display as a third state.
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
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey')->refresh();
    $this->withHeaders(builderHeaders($this->form));
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @param  array<string, mixed>  $config */
function calculationSave(object $test, array $config): TestResponse
{
    enterTenant($test->tenant->id, $test->admin->id); // a request clears the tenant context, and this runs twice in one test
    $field = app(FormBuilderService::class)->addField($test->form, $test->admin, FieldType::Calculated, null);

    return $test->actingAs($test->admin)->patchJson("http://acme.meridian.test/forms/{$test->form->id}/fields/{$field->id}", [
        'key' => $field->key,
        'label' => 'Total',
        'is_required' => 'optional',
        'config' => ['calculated_formula' => '1 + 1', ...$config],
        'validations' => [],
        'version' => (string) FormBuilderService::rowVersion($field->refresh()),
    ]);
}

it('saves a Type, decimal places and units', function (): void {
    calculationSave($this, ['calculated_type' => 'number', 'decimal_places' => 2])
        ->assertOk()->assertJsonPath('config.calculated_type', 'number')->assertJsonPath('config.decimal_places', 2);
    calculationSave($this, ['calculated_type' => 'duration', 'duration_unit' => 'years'])
        ->assertOk()->assertJsonPath('config.duration_unit', 'years');
});

it('refuses a Type, decimal places or units it does not know, naming each as the editor does', function (array $config, string $path, string $name): void {
    calculationSave($this, $config)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$path => $name]);
})->with([
    'a Type' => [['calculated_type' => 'currency'], 'config.calculated_type', 'result type'],
    'too many places' => [['decimal_places' => 11], 'config.decimal_places', 'decimal places'],
    'negative places' => [['decimal_places' => -1], 'config.decimal_places', 'decimal places'],
    'a unit' => [['duration_unit' => 'seconds'], 'config.duration_unit', 'units'],
]);
