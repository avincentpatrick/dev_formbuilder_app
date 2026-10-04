<?php

declare(strict_types=1);

use App\Enums\FieldAppearance;
use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Xlsform\XlsformExporter;
use App\Services\Xlsform\XlsformImporter;
use App\Services\Xlsform\XlsformWorkbookWriter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M130 (`R-6c76bed2`) — a layout survives XLSForm, and so does an imported appearance an author then edits.
|--------------------------------------------------------------------------
| The row called the round trip "the test that matters", and none existed: no test set a real author
| appearance, and none edited one and exported it again. Two halves:
|   · every layout the builder offers comes back from its own export as the same type AND the same value —
|     which is why the vocabulary is ODK's own names and never `minimal`;
|   · an appearance outside the vocabulary that an import stored survives a builder save and goes back out.
|
| Helpers are prefixed `appearanceRoundTrip*`: Pest loads every test file into one process, and the importer
| test's own `xlsxUpload()` lives in that file, so this one cannot lean on it when run alone.
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

/** Write workbook sheets ({name:{headers,rows}}) to a real .xlsx upload the importer can consume. */
function appearanceRoundTripUpload(array $sheets): UploadedFile
{
    $bytes = app(XlsformWorkbookWriter::class)->writeToString($sheets);
    $path = tempnam(sys_get_temp_dir(), 'apr').'.xlsx';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'form.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** An empty-draft form an import can target. */
function appearanceRoundTripTarget(User $owner): array
{
    $form = makeForm($owner, 'Target');
    $draft = makeDraftVersion($form);
    $form->forceFill(['draft_version_id' => $draft->id])->save();

    return [$form->refresh(), $draft];
}

/** The survey row the exporter wrote for one key. */
function appearanceRoundTripSurveyRow(array $sheets, string $key): array
{
    foreach ($sheets['survey']['rows'] as $row) {
        if (($row['name'] ?? null) === $key) {
            return $row;
        }
    }

    throw new RuntimeException("the export wrote no survey row named {$key}");
}

it('brings every offered layout back from its own export as the same type and the same value', function (FieldType $type): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $layouts = FieldAppearance::for($type);
    // Anti-vacuity: a type that stopped offering layouts would make the loop below assert nothing.
    expect($layouts)->not->toBeEmpty();

    $source = makeForm($owner, 'Layouts');
    $version = makeDraftVersion($source);
    $options = ['config' => ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]];

    foreach ($layouts as $i => $layout) {
        addFormField($version, $owner, "q_{$i}", $type, $i, [...$options, 'appearance' => $layout->value]);
    }

    $sheets = app(XlsformExporter::class)->build($source, $version->refresh());

    foreach ($layouts as $i => $layout) {
        expect(appearanceRoundTripSurveyRow($sheets, "q_{$i}")['appearance'])->toBe($layout->value);
    }

    [$target, $draft] = appearanceRoundTripTarget($owner);
    app(XlsformImporter::class)->import($target, appearanceRoundTripUpload($sheets), $owner);

    $imported = FormField::query()->where('form_version_id', $draft->id)->get()->keyBy('key');

    foreach ($layouts as $i => $layout) {
        expect($imported["q_{$i}"]->field_type)->toBe($type)
            ->and($imported["q_{$i}"]->appearance)->toBe($layout->value);
    }
})->with([FieldType::SingleSelect, FieldType::MultiSelect]);

it('keeps an imported appearance outside the vocabulary through a builder save and back out on export', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    // `select_multiple` + `minimal`: the type map consumes `minimal` only on `select_one`, so it stays in the
    // column — the one way a stored `minimal` reaches the builder.
    $workbook = appearanceRoundTripUpload([
        'survey' => [
            'headers' => ['type', 'name', 'label', 'appearance'],
            'rows' => [['type' => 'select_multiple colours', 'name' => 'colours', 'label' => 'Colours', 'appearance' => 'minimal']],
        ],
        'choices' => [
            'headers' => ['list_name', 'name', 'label'],
            'rows' => [
                ['list_name' => 'colours', 'name' => 'red', 'label' => 'Red'],
                ['list_name' => 'colours', 'name' => 'blue', 'label' => 'Blue'],
            ],
        ],
        'settings' => ['headers' => ['form_title'], 'rows' => [['form_title' => 'Imported']]],
    ]);

    [$target, $draft] = appearanceRoundTripTarget($owner);
    app(XlsformImporter::class)->import($target, $workbook, $owner);

    $field = FormField::query()->where('form_version_id', $draft->id)->where('key', 'colours')->firstOrFail();
    expect($field->field_type)->toBe(FieldType::MultiSelect)
        ->and($field->appearance)->toBe('minimal');

    // The builder's save, as `fieldPayload()` sends it: the whole field, appearance untouched, label edited.
    test()->actingAs($owner)
        ->patchJson("http://acme.meridian.test/forms/{$target->id}/fields/{$field->id}", [
            'key' => $field->key,
            'label' => 'Favourite colours',
            'hint' => $field->hint,
            'placeholder' => $field->placeholder,
            'is_required' => $field->is_required->value,
            'relevant_expression' => $field->relevant_expression,
            'appearance' => $field->appearance,
            'config' => $field->config,
            'default_value' => $field->default_value,
            'is_pii' => (bool) $field->is_pii,
            'is_sensitive' => (bool) $field->is_sensitive,
            'is_queryable' => (bool) $field->is_queryable,
            'indexed_data_type' => $field->indexed_data_type?->value,
            'version' => FormBuilderService::rowVersion($field),
            'validations' => [],
        ])
        ->assertOk()
        ->assertJsonPath('appearance', 'minimal');

    enterTenant($tenant->id, $owner->id);
    $sheets = app(XlsformExporter::class)->build($target->refresh(), $draft->refresh());

    expect(appearanceRoundTripSurveyRow($sheets, 'colours')['appearance'])->toBe('minimal')
        ->and(appearanceRoundTripSurveyRow($sheets, 'colours')['label'])->toBe('Favourite colours');
});
