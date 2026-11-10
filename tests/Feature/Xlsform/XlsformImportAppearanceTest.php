<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\User;
use App\Services\Xlsform\XlsformImporter;
use App\Services\Xlsform\XlsformImportParser;
use App\Services\Xlsform\XlsformWorkbookWriter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M219 (`R-1fc57252`): an XLSForm appearance longer than `form_fields.appearance` can hold.
|--------------------------------------------------------------------------
| Before M219 the parser kept any author appearance as it was, and the insert into the `varchar(60)` column
| failed the WHOLE import over one cell. ODK's `search(...)` appearances run long, so a real workbook meets
| this. Now the question is imported without that appearance, and the import says so in a warning.
|
| A file of its own rather than more cases in `XlsformImportTest.php`, which a held pull request (#414) also
| edits. Helpers are prefixed `appearanceImport*` because Pest loads every test file into one process, and a
| second file-scope `xlsxUpload()` would be a fatal redeclaration.
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

/** One survey sheet's rows as a real .xlsx upload, with an empty choices list unless one is given. */
function appearanceImportUpload(array $surveyRows, array $choiceRows = []): UploadedFile
{
    $bytes = app(XlsformWorkbookWriter::class)->writeToString([
        'survey' => ['headers' => ['type', 'name', 'label', 'appearance'], 'rows' => $surveyRows],
        'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => $choiceRows],
        'settings' => ['headers' => ['form_title'], 'rows' => [['form_title' => 'Imported']]],
    ]);
    $path = tempnam(sys_get_temp_dir(), 'm219').'.xlsx';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'form.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** A signed-in owner and an empty draft for the import to fill. @return array{0: User, 1: \App\Models\Form, 2: \App\Models\FormVersion} */
function appearanceImportTarget(): array
{
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = makeForm($owner, 'Appearance Target');
    $draft = makeDraftVersion($form);
    $form->forceFill(['draft_version_id' => $draft->id])->save();

    return [$owner, $form->refresh(), $draft];
}

it('imports a question whose appearance is too long to store without it, and says so in a warning', function (): void {
    [$owner, $form, $draft] = appearanceImportTarget();

    // A real ODK shape: a choice list searched by what the respondent types into another question.
    $search = "search('health_facilities', 'contains', 'facility_name', \${facility_query})";
    expect(mb_strlen($search))->toBeGreaterThan(XlsformImportParser::APPEARANCE_MAX_LENGTH);

    $upload = appearanceImportUpload(
        [
            ['type' => 'text', 'name' => 'facility_query', 'label' => 'Type part of the facility name', 'appearance' => null],
            ['type' => 'select_one facilities', 'name' => 'facility', 'label' => 'Facility', 'appearance' => $search],
            ['type' => 'select_one yn', 'name' => 'visited', 'label' => 'Visited?', 'appearance' => 'minimal'],
        ],
        [
            ['list_name' => 'facilities', 'name' => 'placeholder', 'label' => 'Placeholder'],
            ['list_name' => 'yn', 'name' => 'yes', 'label' => 'Yes'],
            ['list_name' => 'yn', 'name' => 'no', 'label' => 'No'],
        ],
    );

    $result = app(XlsformImporter::class)->import($form, $upload, $owner);

    $fields = FormField::query()->where('form_version_id', $draft->id)->get()->keyBy('key');

    // The whole import landed, not just the rows before the long cell.
    expect($result->fieldCount)->toBe(3)
        ->and($fields)->toHaveKeys(['facility_query', 'facility', 'visited'])
        ->and($fields['facility']->appearance)->toBeNull()
        ->and($fields['facility']->field_type)->toBe(FieldType::SingleSelect);

    expect($result->warnings)->toContain(
        'The appearance of “facility” is longer than 60 characters, so it was not imported; the question was imported without it.'
    );
});

it('keeps an appearance of exactly the column width, so the cap is not one short', function (): void {
    [$owner, $form, $draft] = appearanceImportTarget();

    $exact = str_repeat('a', XlsformImportParser::APPEARANCE_MAX_LENGTH);

    $result = app(XlsformImporter::class)->import($form, appearanceImportUpload([
        ['type' => 'text', 'name' => 'q1', 'label' => 'Q1', 'appearance' => $exact],
    ]), $owner);

    $field = FormField::query()->where('form_version_id', $draft->id)->where('key', 'q1')->sole();

    expect($field->appearance)->toBe($exact)
        ->and(implode(' ', $result->warnings))->not->toContain('appearance');
});

it('caps at the real width of form_fields.appearance, read from the database', function (): void {
    // The constant is a copy of a schema fact. Reading the column here means a migration that widened or
    // narrowed it would fail this case rather than quietly reopen the defect or drop appearances that fit.
    $width = DB::selectOne(
        "select character_maximum_length as width from information_schema.columns
         where table_schema = current_schema() and table_name = 'form_fields' and column_name = 'appearance'"
    );

    expect($width)->not->toBeNull()
        ->and((int) $width->width)->toBe(XlsformImportParser::APPEARANCE_MAX_LENGTH);
});
