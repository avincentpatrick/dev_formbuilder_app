<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\FormVersionChoiceList;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\ChoiceListCsvParser;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\RestoreService;
use App\Services\Validation\SemanticValidator;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M141 (`R-f69aab42`, `D92` = A, `D95`) — choice lists from CSV files, one file per cascade level, in Kobo's format.
|--------------------------------------------------------------------------
| A list is parsed at upload and held on the draft; the draft's cascade names its lists and holds no options;
| publishing builds the options into the published field, where every server-side reader finds an ordinary cascade;
| the next draft takes the lists and drops the options again.
|
| ⚠️ Helpers are prefixed `choiceList*`: Pest loads every test file into one process.
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
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Household Visit');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function choiceListFile(string $name, string $content): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm141');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/** @return array{columns: list<string>, rows: list<list<string>>} */
function choiceListParse(string $content): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm141');
    file_put_contents($path, $content);

    return app(ChoiceListCsvParser::class)->parse($path);
}

function choiceListParseError(string $content): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm141');
    file_put_contents($path, $content);

    return choiceListPathError($path);
}

function choiceListPathError(string $path): string
{
    try {
        app(ChoiceListCsvParser::class)->parse($path);
    } catch (ValidationException $e) {
        return (string) ($e->errors()['file'][0] ?? '');
    }

    return 'no error';
}

/**
 * An Excel workbook written by OpenSpout, each sheet a list of rows, each cell typed as given (an int or a float is a
 * NUMBER cell, a string a TEXT cell) — the distinction Excel's "01" turns on.
 *
 * @param  list<list<list<scalar|null>>>  $sheets
 */
function choiceListWorkbook(array $sheets): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm184').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);

    foreach ($sheets as $index => $rows) {
        if ($index > 0) {
            $writer->addNewSheetAndMakeItCurrent();
        }
        foreach ($rows as $cells) {
            $writer->addRow(Row::fromValues($cells));
        }
    }

    $writer->close();

    return $path;
}

/** The three files of a small geography, and a cascade on the draft that names them. */
function choiceListGeography(object $test): FormField
{
    $service = app(FormChoiceListService::class);
    $service->upload($test->form->refresh(), choiceListFile('regions.csv', "name,label\n01,Ilocos Region\n13,NCR\n"));
    $service->upload($test->form->refresh(), choiceListFile('provinces.csv', "name,label,region\n0128,Ilocos Norte,01\n0129,Ilocos Sur,01\n1339,Manila,13\n"));
    $service->upload($test->form->refresh(), choiceListFile('cities.csv', "name,label,province\n012801,Adams,0128\n012802,Bacarra,0128\n133901,Tondo,1339\n"));

    return addFormField($test->form->refresh()->draftVersion, $test->admin, 'address', FieldType::CascadingSelect, 0, [
        'config' => [
            'levels' => [
                ['key' => 'region', 'label' => 'Region', 'list' => 'regions'],
                ['key' => 'province', 'label' => 'Province', 'list' => 'provinces'],
                ['key' => 'city', 'label' => 'City', 'list' => 'cities'],
            ],
            'options' => [],
        ],
    ]);
}

function choiceListPublishError(object $test): string
{
    try {
        app(PublishService::class)->publish($test->form->refresh(), $test->admin);
    } catch (PublishValidationException $e) {
        return $e->getMessage();
    }

    return 'published';
}

// ── The file ──────────────────────────────────────────────────────────────────────────────────────────────

it('reads Kobo’s format, with a comma, a semicolon or a tab between columns', function (string $separator): void {
    $parsed = choiceListParse(implode("\n", [
        implode($separator, ['name', 'label', 'region']),
        implode($separator, ['0128', 'Ilocos Norte', '01']),
    ]));

    expect($parsed)->toBe(['columns' => ['name', 'label', 'region'], 'rows' => [['0128', 'Ilocos Norte', '01']]]);
})->with([',', ';', "\t"]);

it('drops a byte-order mark, and reads a Windows-1252 file as Excel writes one', function (): void {
    $bom = choiceListParse("\u{FEFF}name,label\n1376,Parañaque\n");
    $cp1252 = choiceListParse((string) mb_convert_encoding("name,label\n1376,Parañaque\n", 'Windows-1252', 'UTF-8'));

    expect($bom['columns'])->toBe(['name', 'label'])
        ->and($bom['rows'][0][1])->toBe('Parañaque')
        ->and($cp1252['rows'][0][1])->toBe('Parañaque');
});

it('takes Kobo’s label::Language column, lower-cases the header, fills a blank label, pads a short row and skips a blank one', function (): void {
    $parsed = choiceListParse("Name,Label::English (en),Region\n0128,,01\n\n0129,Ilocos Sur\n");

    expect($parsed['columns'])->toBe(['name', 'label::english (en)', 'region'])
        ->and($parsed['rows'])->toBe([['0128', '0128', '01'], ['0129', 'Ilocos Sur', '']]);
});

it('refuses a repeated name, naming both rows, because both engines key a level by value', function (): void {
    expect(choiceListParseError("name,label,city\nPOB,Poblacion,012801\nPOB,Poblacion,012802\n"))
        ->toBe('“POB” is on rows 2 and 3. Each name must be a code used once in the file.');
});

it('refuses a file without name and label columns, a row without a name, and an empty file', function (): void {
    expect(choiceListParseError("code,title\n01,One\n"))->toContain('“name” and “label”')
        ->and(choiceListParseError("name,label\n,No code\n"))->toBe('Row 2 has no name.')
        ->and(choiceListParseError("name,label\n"))->toBe('The file has no rows under its header.')
        ->and(choiceListParseError(''))->toBe('The file is empty.');
});

// ── Excel workbooks (M184, `R-3e7c81bb`) ──────────────────────────────────────────────────────────────────

it('reads the first sheet of a workbook: a number as the digits typed, a text code as written', function (): void {
    $parsed = app(ChoiceListCsvParser::class)->parse(choiceListWorkbook([
        [['name', 'label', 'region'], ['0128', 'Ilocos Norte', 1], [129, 'Ilocos Sur', 1.0], [12.5, 'Half', '01']],
        [['name', 'label'], ['x', 'The second sheet, never read']],
    ]));

    // ⚠️ The number 1 is "1" — its leading zero, if the sheet showed one, was never stored. Only the text cell keeps "01".
    expect($parsed)->toBe([
        'columns' => ['name', 'label', 'region'],
        'rows' => [['0128', 'Ilocos Norte', '1'], ['129', 'Ilocos Sur', '1'], ['12.5', 'Half', '01']],
    ]);
});

it('counts a workbook\'s rows as Excel shows them, blank rows included', function (): void {
    expect(choiceListPathError(choiceListWorkbook([[['name', 'label'], ['01', 'One'], [], ['', 'No code']]])))
        ->toBe('Row 4 has no name.');
});

it('refuses a zip that is not a workbook, saying what to save the file as', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'm184').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('content.xml', '<office:document/>');
    $zip->close();

    expect(choiceListPathError($path))->toBe('The file could not be read. Save it as a CSV file or an Excel workbook (.xlsx), and try again.');
});

it('refuses a list longer than the cap while reading it', function (): void {
    $rows = array_map(static fn (int $i): string => "c{$i},Choice {$i}", range(1, ChoiceListCsvParser::MAX_ROWS + 1));

    expect(choiceListParseError("name,label\n".implode("\n", $rows)."\n"))->toBe('The file has more than 60,000 rows.');
});

it('uploads a workbook through the route, and refuses an old .xls or an .ods by name', function (): void {
    $workbook = choiceListWorkbook([[['name', 'label'], ['01', 'Ilocos Region']]]);

    $this->actingAs($this->admin)->post("http://acme.meridian.test/forms/{$this->form->id}/choice-lists", [
        'file' => new UploadedFile($workbook, 'regions.xlsx', null, null, true),
    ])->assertCreated()->assertJsonPath('data.name', 'regions')->assertJsonPath('data.row_count', 1);

    foreach (['regions.xls', 'regions.ods'] as $name) {
        $this->actingAs($this->admin)->postJson("http://acme.meridian.test/forms/{$this->form->id}/choice-lists", [
            'file' => new UploadedFile($workbook, $name, null, null, true),
        ])->assertUnprocessable()->assertJsonValidationErrors(['file' => 'A choice list must be a .csv or .xlsx file.']);
    }
});

// ── The draft's lists ─────────────────────────────────────────────────────────────────────────────────────

it('names a list after its file, and replaces a list whose file is uploaded again', function (): void {
    $service = app(FormChoiceListService::class);
    $service->upload($this->form->refresh(), choiceListFile('Provinces 2024.csv', "name,label\n01,One\n"));
    $replaced = $service->upload($this->form->refresh(), choiceListFile('provinces 2024.csv', "name,label\n01,One\n02,Two\n"));

    expect($replaced['name'])->toBe('provinces_2024')
        ->and($replaced['row_count'])->toBe(2)
        ->and(FormVersionChoiceList::query()->count())->toBe(1)
        ->and($service->forAuthor($this->form->refresh()))->toBe([
            ['name' => 'provinces_2024', 'file_name' => 'provinces 2024.csv', 'row_count' => 2, 'columns' => ['name', 'label']],
        ]);
});

it('uploads, lists and removes through the routes, for whoever may edit the form', function (): void {
    $this->actingAs($this->admin)->post("http://acme.meridian.test/forms/{$this->form->id}/choice-lists", [
        'file' => UploadedFile::fake()->createWithContent('regions.csv', "name,label\n01,Ilocos Region\n"),
    ])->assertCreated()->assertJsonPath('data.name', 'regions')->assertJsonPath('data.row_count', 1);

    $this->actingAs($this->admin)->getJson("http://acme.meridian.test/forms/{$this->form->id}/choice-lists")
        ->assertOk()->assertJsonPath('data.0.name', 'regions');

    $this->actingAs($this->admin)->delete("http://acme.meridian.test/forms/{$this->form->id}/choice-lists/regions")->assertNoContent();

    expect(FormVersionChoiceList::query()->count())->toBe(0);
});

it('refuses a file that is not a .csv, and a list it cannot read, with the reason', function (): void {
    $this->actingAs($this->admin)->postJson("http://acme.meridian.test/forms/{$this->form->id}/choice-lists", [
        'file' => UploadedFile::fake()->createWithContent('regions.txt', "name,label\n01,One\n"),
    ])->assertUnprocessable()->assertJsonValidationErrors(['file' => 'A choice list must be a .csv or .xlsx file.']);

    $this->actingAs($this->admin)->postJson("http://acme.meridian.test/forms/{$this->form->id}/choice-lists", [
        'file' => UploadedFile::fake()->createWithContent('regions.csv', "name,label\n,One\n"),
    ])->assertUnprocessable()->assertJsonValidationErrors(['file' => 'Row 2 has no name.']);
});

// ── Publishing ────────────────────────────────────────────────────────────────────────────────────────────

it('builds the cascade’s options from its lists into the published version, and keeps them out of the next draft', function (): void {
    choiceListGeography($this);
    $draftId = (string) $this->form->refresh()->draft_version_id;

    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $form = $this->form->refresh();

    $published = FormField::query()->where('form_version_id', $draftId)->where('key', 'address')->firstOrFail();
    $options = collect($published->config['options']);

    expect($options)->toHaveCount(8)
        ->and($options->firstWhere('value', '01'))->toEqual(['level' => 'region', 'value' => '01', 'label' => 'Ilocos Region', 'parent' => null])
        // ⚠️ `toEqual` (jsonb reorders keys) calls an empty string equal to null, and the runtime shows a first-level
        // choice only when its parent IS null — so that is asserted on its own (a mutant writing '' survived without it).
        ->and($options->firstWhere('value', '01')['parent'])->toBeNull()
        ->and($options->firstWhere('value', '133901'))->toEqual(['level' => 'city', 'value' => '133901', 'label' => 'Tondo', 'parent' => '1339'])
        // The frozen snapshot carries them too, so a version's export labels are its own list's.
        ->and(collect(FormVersion::query()->findOrFail($draftId)->schema_snapshot['fields'])->firstWhere('key', 'address')['config']['options'])->toHaveCount(8);

    $nextDraft = FormField::query()->where('form_version_id', $form->draft_version_id)->where('key', 'address')->firstOrFail();
    expect($nextDraft->config['options'])->toBe([])
        ->and($nextDraft->config['levels'][1]['list'])->toBe('provinces')
        ->and(FormVersionChoiceList::query()->where('form_version_id', $form->draft_version_id)->pluck('name')->sort()->values()->all())
        ->toBe(['cities', 'provinces', 'regions']);
});

it('checks an answer against the published list on the server, for every channel', function (): void {
    choiceListGeography($this);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $version = FormVersion::query()->findOrFail($this->form->refresh()->current_published_version_id);
    $validator = app(SemanticValidator::class);

    $good = $validator->validate($version, ['address' => ['01', '0128', '012801']]);
    $notInList = $validator->validate($version, ['address' => ['01', '0128', '999999']]);
    $wrongParent = $validator->validate($version, ['address' => ['01', '0128', '133901']]);

    expect($good->passed())->toBeTrue()
        ->and($notInList->errorsFor('address')[0]->rule ?? null)->toBe('cascading_choice_invalid')
        ->and($wrongParent->errorsFor('address')[0]->rule ?? null)->toBe('cascading_parent_mismatch');
});

it('refuses to publish when a level names a list the form does not hold', function (): void {
    choiceListGeography($this);
    app(FormChoiceListService::class)->remove($this->form->refresh(), 'cities');

    expect(choiceListPublishError($this))->toContain('level “city” takes its choices from “cities”, which this form does not hold');
});

it('refuses to publish when a list lacks the column named after the level above', function (): void {
    choiceListGeography($this);
    app(FormChoiceListService::class)->upload($this->form->refresh(), choiceListFile('cities.csv', "name,label,prov\n012801,Adams,0128\n"));

    expect(choiceListPublishError($this))->toContain('“cities” has no column named “province”, the level above “city”');
});

it('refuses to publish a row whose parent is not in the list above, naming the file and the likeliest cause (R-1059e9bd)', function (): void {
    choiceListGeography($this);
    app(FormChoiceListService::class)->upload($this->form->refresh(), choiceListFile('cities.csv', "name,label,province\n012801,Adams,128\n"));

    expect(choiceListPublishError($this))->toContain(
        '“012801” in cities.csv names “128” as its province, which the list above does not hold — if the file was saved in Excel, a code like 01 may have become 1: keep codes as text, or use letters'
    );

    app(FormChoiceListService::class)->upload($this->form->refresh(), choiceListFile('cities.csv', "name,label,province\n012801,Adams,\n"));
    expect(choiceListPublishError($this))->toContain('“012801” in cities.csv leaves its “province” column empty');
});

it('refuses a cascade that takes some levels from lists and not others', function (): void {
    $field = choiceListGeography($this);
    $config = $field->config;
    unset($config['levels'][2]['list']);
    $field->forceFill(['config' => $config])->save();

    expect(choiceListPublishError($this))->toContain('every level must take its choices from a list, or none may');
});

it('restores an old version’s lists into the draft, without its options', function (): void {
    choiceListGeography($this);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $source = FormVersion::query()->findOrFail($this->form->refresh()->current_published_version_id);
    app(FormChoiceListService::class)->remove($this->form->refresh(), 'cities');

    app(RestoreService::class)->restore($this->form->refresh(), $source, $this->admin);
    $draftId = (string) $this->form->refresh()->draft_version_id;

    expect(FormVersionChoiceList::query()->where('form_version_id', $draftId)->pluck('name')->sort()->values()->all())->toBe(['cities', 'provinces', 'regions'])
        ->and(FormField::query()->where('form_version_id', $draftId)->where('key', 'address')->firstOrFail()->config['options'])->toBe([]);
});
