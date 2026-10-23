<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormField;
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
| M183 (`R-5033e87e`, `R-1c1d7266`) — a grid's new settings through the real builder save: a matrix column's
| type, own choices and required flag, and a grid's layout. Shape only, like every arm of the request; whether
| the grid can be answered is the publish gate's (`MatrixTypedColumnsTest`).
|--------------------------------------------------------------------------
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
 * An admin on a fresh tenant with one draft form holding one field of $type, ready to save.
 *
 * @return array{0: Tenant, 1: User, 2: Form, 3: string, 4: string}
 */
function matrixSaveAuthor(string $type): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Clinic roster');
    test()->withHeaders(builderHeaders($form));

    $add = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => $type])
        ->assertOk();

    return [$tenant, $admin, $form, $add->json('id'), $add->json('key')];
}

/** @param  array<string, mixed>  $config */
function matrixSavePatch(User $admin, Form $form, string $fieldId, string $key, array $config)
{
    return test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => $key,
            'label' => 'Clinics',
            'is_required' => 'optional',
            'config' => $config,
            'validations' => [],
            'version' => null,
        ]);
}

it('saves a column\'s type, its own choices and its required flag, and the grid\'s layout', function (): void {
    [$tenant, $admin, $form, $fieldId, $key] = matrixSaveAuthor('matrix');

    matrixSavePatch($admin, $form, $fieldId, $key, [
        'rows' => [['value' => 'a', 'label' => 'Clinic A']],
        'columns' => [
            ['value' => 'staff', 'label' => 'Staff', 'type' => 'integer', 'required' => true],
            ['value' => 'svc', 'label' => 'Services', 'type' => 'multi_select', 'options' => [['value' => 'lab', 'label' => 'Lab', 'label_translations' => ['fil' => 'Lab']]]],
        ],
        'cells' => [],
        'layout' => 'stacked',
    ])->assertOk();

    enterTenant($tenant->id, $admin->id);
    $config = FormField::query()->findOrFail($fieldId)->config;
    expect($config['columns'][0])->toMatchArray(['type' => 'integer', 'required' => true])
        ->and($config['columns'][1]['options'][0])->toEqual(['value' => 'lab', 'label' => 'Lab', 'label_translations' => ['fil' => 'Lab']])
        ->and($config['layout'])->toBe('stacked');
});

it('refuses a column type a grid cannot hold', function (string $type): void {
    [, $admin, $form, $fieldId, $key] = matrixSaveAuthor('matrix');

    matrixSavePatch($admin, $form, $fieldId, $key, [
        'rows' => [['value' => 'a', 'label' => 'A']],
        'columns' => [['value' => 'c', 'label' => 'C', 'type' => $type]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['config.columns.0.type']);
})->with(['yes/no, retired by D140' => 'yes_no', 'a signature' => 'signature', 'nothing at all' => 'banana']);

it('refuses a required flag that is not true or false', function (): void {
    [, $admin, $form, $fieldId, $key] = matrixSaveAuthor('matrix');

    matrixSavePatch($admin, $form, $fieldId, $key, [
        'rows' => [['value' => 'a', 'label' => 'A']],
        'columns' => [['value' => 'c', 'label' => 'C', 'required' => 'sometimes']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['config.columns.0.required']);
});

it('refuses a layout other than a table or cards', function (string $type): void {
    [, $admin, $form, $fieldId, $key] = matrixSaveAuthor($type);

    matrixSavePatch($admin, $form, $fieldId, $key, [
        'rows' => [['value' => 'a', 'label' => 'A']],
        'columns' => [['value' => 'c', 'label' => 'C']],
        'layout' => 'carousel',
    ])->assertUnprocessable()->assertJsonValidationErrors(['config.layout']);
})->with(['matrix', 'likert_matrix']);

/*
| M185 (`R-bb79aec0`, `D161`) — where a grid's rows, its shared cells and a column's choices come from: the same shapes a
| choice question's `options_list` and `options_source` take, half-picked included. Whether each can serve is publish's.
*/

it('saves where a grid\'s rows, its cells and a column\'s choices come from, half-picked or picked', function (): void {
    [$tenant, $admin, $form, $fieldId, $key] = matrixSaveAuthor('matrix');
    $source = '9b1d6f4e-2c3a-4f5b-8d7e-1a2b3c4d5e6f';

    matrixSavePatch($admin, $form, $fieldId, $key, [
        'rows' => [],
        'rows_list' => 'barangays',
        'columns' => [
            ['value' => 'svc', 'label' => 'Services', 'type' => 'multi_select', 'options_source' => ['form_id' => $source, 'field_key' => 'service']],
            ['value' => 'grade', 'label' => 'Grade', 'options_list' => 'grades'],
        ],
        'cells' => [],
        'cells_source' => ['form_id' => null, 'field_key' => null],
    ])->assertOk();

    enterTenant($tenant->id, $admin->id);
    $config = FormField::query()->findOrFail($fieldId)->config;
    expect($config['rows_list'])->toBe('barangays')
        ->and($config['cells_source'])->toBe(['form_id' => null, 'field_key' => null])
        ->and($config['columns'][0]['options_source'])->toBe(['form_id' => $source, 'field_key' => 'service'])
        ->and($config['columns'][1]['options_list'])->toBe('grades');
});

it('saves a Likert matrix\'s statements from another form', function (): void {
    [$tenant, $admin, $form, $fieldId, $key] = matrixSaveAuthor('likert_matrix');
    $source = '9b1d6f4e-2c3a-4f5b-8d7e-1a2b3c4d5e6f';

    matrixSavePatch($admin, $form, $fieldId, $key, [
        'rows' => [],
        'rows_source' => ['form_id' => $source, 'field_key' => 'facility'],
        'columns' => [['value' => '1', 'label' => 'Poor'], ['value' => '2', 'label' => 'Good']],
    ])->assertOk();

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->findOrFail($fieldId)->config['rows_source'])->toBe(['form_id' => $source, 'field_key' => 'facility']);
});

it('refuses a grid source in the wrong shape', function (array $config, string $path): void {
    [, $admin, $form, $fieldId, $key] = matrixSaveAuthor('matrix');

    matrixSavePatch($admin, $form, $fieldId, $key, [
        'rows' => [['value' => 'a', 'label' => 'A']],
        'columns' => [['value' => 'c', 'label' => 'C']],
        ...$config,
    ])->assertUnprocessable()->assertJsonValidationErrors([$path]);
})->with([
    'a list name with a space' => [['rows_list' => 'my wards'], 'config.rows_list'],
    'a list name in capitals' => [['cells_list' => 'Wards'], 'config.cells_list'],
    'a form that is no id' => [['rows_source' => ['form_id' => 'not-a-uuid', 'field_key' => 'ward']], 'config.rows_source.form_id'],
    'a source with a stray key' => [['cells_source' => ['form_id' => null, 'field_key' => null, 'kind' => 'form']], 'config.cells_source'],
    'a column list name with a dot' => [['columns' => [['value' => 'c', 'label' => 'C', 'options_list' => 'a.b']]], 'config.columns.0.options_list'],
]);
