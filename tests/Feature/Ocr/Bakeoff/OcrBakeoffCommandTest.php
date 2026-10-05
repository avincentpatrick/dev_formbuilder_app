<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Ocr\Bakeoff\OcrBakeoffFolder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Feature\Ocr\Support\PrintedPageTypesetter;
use Tests\Feature\Ocr\Support\ReadScanFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M136 — the OCR bake-off harness, end to end: the layout export, then the folder read and scored.
|--------------------------------------------------------------------------
| Every scan is laid out from the SAME render model the PDF is typeset from (`PrintedPageTypesetter`), and its Cloud
| Vision answer is either cached beside it — the offline path, which is also every re-run — or served by a faked
| endpoint. So the parser, the matcher and the scorer under test are the real ones; only the provider is not.
|
| ⚠️ Helpers are prefixed `ocrBakeoff*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);

    $form = app(FormService::class)->create($this->tenant, $this->user, 'Patient Intake');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'patient_name', FieldType::ShortText, 1, ['label' => 'Patient name']);
    addFormField($draft, $this->user, 'age', FieldType::Integer, 2, ['label' => 'Age']);
    addFormField($draft, $this->user, 'visit_date', FieldType::Date, 3, ['label' => 'Visit date']);
    addFormField($draft, $this->user, 'consent', FieldType::YesNo, 4, ['label' => 'Consent given']);
    addFormField($draft, $this->user, 'sex', FieldType::SingleSelect, 5, ['label' => 'Sex', 'config' => ['options' => [
        ['value' => 'f', 'label' => 'Female'], ['value' => 'm', 'label' => 'Male'],
    ]]]);
    addFormField($draft, $this->user, 'symptoms', FieldType::MultiSelect, 6, ['label' => 'Presenting symptoms', 'config' => ['options' => [
        ['value' => 'fever', 'label' => 'Fever'], ['value' => 'cough', 'label' => 'Cough'], ['value' => 'fatigue', 'label' => 'Fatigue'],
    ]]]);
    addFormField($draft, $this->user, 'notes', FieldType::LongText, 7, ['label' => 'Clinical notes']);

    $this->version = app(PublishService::class)->publish($form->refresh(), $this->user);
    $this->form = $form->refresh();
    $this->model = app(BlankFormPrintPresenter::class)->present($this->form, $this->version);

    $this->dir = storage_path('framework/testing/ocr-bakeoff-'.Str::lower(Str::random(10)));
    File::ensureDirectoryExists($this->dir.'/samples');
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** A sheet filled in and photographed cleanly: every answer right. */
function ocrBakeoffCleanAnswers(): array
{
    return [
        'patient_name' => 'JUAN DELA CRUZ', 'age' => '34', 'visit_date' => ['03', '10', '2026'], 'consent' => ['Yes'],
        'sex' => ['Female'], 'symptoms' => ['Fever', 'Cough'], 'notes' => 'MILD FEVER FOR TWO DAYS',
    ];
}

/** Its correct answers as a person types them — the age column headed by its label, not its key. */
function ocrBakeoffCleanRow(string $file, string $condition): array
{
    return [$file, $condition, 'Juan dela Cruz', '34', '2026-10-03', 'yes', 'Female', 'Fever; Cough', 'Mild fever for two days', 'ignored'];
}

/** A page image and its cached provider answer, laid out from the print model. */
function ocrBakeoffScan(string $path, array $model, array $answers, array $options = []): void
{
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, (string) base64_decode(ReadScanFixture::PNG));
    file_put_contents($path.OcrBakeoffFolder::CACHE_SUFFIX, (string) json_encode(PrintedPageTypesetter::fromModel($model, $answers, $options)->visionImageAnswer()));
}

/** @param  list<list<string>>  $rows */
function ocrBakeoffSheet(string $path, array $rows): string
{
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['file', 'condition', 'patient_name', 'Age', 'visit_date', 'consent', 'sex', 'symptoms', 'notes', 'bogus_column'], ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);

    return $path;
}

/** @return array<string, array<string, string>> fields.csv by "scan/question" */
function ocrBakeoffFieldsCsv(string $path): array
{
    $lines = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), array_values(array_filter(explode("\n", ltrim((string) file_get_contents($path), "\u{FEFF}")))));
    $header = array_shift($lines);
    $out = [];
    foreach ($lines as $line) {
        $row = array_combine($header, $line);
        $out[$row['scan'].'/'.$row['question']] = $row;
    }

    return $out;
}

function ocrBakeoffExportLayout(string $dir, string $formId): string
{
    test()->artisan('ocr:bakeoff-layout', ['tenant' => 'alpha', 'form' => $formId, 'dir' => $dir])->assertExitCode(0);

    return $dir.'/layout.json';
}

it('exports every published and superseded version, and a template with one column per question in printed order', function (): void {
    $draft = $this->form->draftVersion;
    FormField::query()->where('form_version_id', $draft->id)->where('key', 'notes')->delete();
    addFormField($draft, $this->user, 'phone', FieldType::Phone, 8, ['label' => 'Phone']);
    app(PublishService::class)->publish($this->form->refresh(), $this->user);

    $layout = json_decode((string) file_get_contents(ocrBakeoffExportLayout($this->dir.'/out', $this->form->id)), true);

    expect($layout['format'])->toBe('meridian-ocr-bakeoff-layout/1')
        ->and($layout['form']['title'])->toBe('Patient Intake')
        ->and(array_column($layout['versions'], 'version_number'))->toBe([2, 1])
        ->and(array_column($layout['versions'], 'status'))->toBe(['published', 'superseded'])
        ->and($layout['versions'][1]['checksum'])->toBe($this->version->checksum);

    $reader = new XlsxReader;
    $reader->open($this->dir.'/out/answers-template.xlsx');
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();

    // The CURRENT version's questions: notes gone, phone added, in authored order.
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['file', 'condition', 'patient_name', 'age', 'visit_date', 'consent', 'sex', 'symptoms', 'phone'])
        ->and($rows[1][0])->toStartWith('#')
        ->and($rows[1][4])->toBe('# Visit date — a date, typed 2026-10-08')
        ->and($rows[1][6])->toBe('# Sex — one of: Female / Male');
});

it('refuses a form that was never published, a form that is not there, and an id that is not one', function (): void {
    $draftOnly = app(FormService::class)->create($this->tenant, $this->user, 'Never Published');

    $this->artisan('ocr:bakeoff-layout', ['tenant' => 'alpha', 'form' => $draftOnly->id, 'dir' => $this->dir])
        ->expectsOutputToContain('has never been published')->assertExitCode(1);
    $this->artisan('ocr:bakeoff-layout', ['tenant' => 'alpha', 'form' => (string) Str::uuid(), 'dir' => $this->dir])
        ->expectsOutputToContain('No form')->assertExitCode(1);
    $this->artisan('ocr:bakeoff-layout', ['tenant' => 'nobody', 'form' => $this->form->id, 'dir' => $this->dir])
        ->expectsOutputToContain('No workspace')->assertExitCode(1);
    $this->artisan('ocr:bakeoff-layout', ['tenant' => 'alpha', 'form' => 'patient-intake', 'dir' => $this->dir])
        ->expectsOutputToContain('is not a form id')->assertExitCode(1);

    expect(is_file($this->dir.'/layout.json'))->toBeFalse();
});

it('reads a folder offline and scores it: corrections, silent errors and the day/month label, with no query at all', function (): void {
    $layout = ocrBakeoffExportLayout($this->dir.'/layout', $this->form->id);
    $samples = $this->dir.'/samples';

    ocrBakeoffScan("{$samples}/sheet1.png", $this->model, ocrBakeoffCleanAnswers());
    // A phone photo: the age misread with high confidence, the day and month written the wrong way round, and the
    // notes read right but too faintly to be shown.
    ocrBakeoffScan("{$samples}/sheet2.png", $this->model, [
        'patient_name' => 'ANA REYES', 'age' => '35', 'visit_date' => ['10', '03', '2026'], 'consent' => ['No'],
        'sex' => ['Male'], 'symptoms' => ['Cough'], 'notes' => 'SORE THROAT',
    ], ['confidence' => ['age' => 0.95, 'visit_date' => 0.95, 'notes' => 0.50]]);
    ocrBakeoffScan("{$samples}/sheet3.png", $this->model, ocrBakeoffCleanAnswers());
    // Two pages in a folder: the whole form on the first, a blank back on the second.
    ocrBakeoffScan("{$samples}/two-pages/p1.png", $this->model, ocrBakeoffCleanAnswers());
    file_put_contents("{$samples}/two-pages/p2.png", (string) base64_decode(ReadScanFixture::PNG));
    file_put_contents("{$samples}/two-pages/p2.png".OcrBakeoffFolder::CACHE_SUFFIX, (string) json_encode(['responses' => [['fullTextAnnotation' => ['text' => '', 'pages' => [['width' => 1000, 'height' => 1414, 'blocks' => []]]]]]]));
    file_put_contents("{$samples}/notes.txt", 'not a scan');

    $answers = ocrBakeoffSheet("{$this->dir}/answers.csv", [
        ocrBakeoffCleanRow('sheet1', 'clean'),
        ['sheet2.PNG', 'photo', 'Ana Reyes', '34', '2026-10-03', 'no', 'm', 'cough', 'Sore throat', ''],
        ocrBakeoffCleanRow('two-pages', 'clean'),
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    Http::fake();

    $this->artisan('ocr:bakeoff', ['folder' => $samples, '--layout' => $layout, '--answers' => $answers, '--offline' => true])
        ->expectsOutputToContain('G9: 3 of 21 scored fields need correction (14.3%) at auto 90 / review 70 — PASS. Silent errors: 2. Flagged: 0.')
        ->assertExitCode(0);

    expect($queries)->toBe(0);
    Http::assertNothingSent();

    $report = (string) file_get_contents("{$samples}/_bakeoff/report.md");
    expect($report)
        ->toContain('**3 of 21 scored fields (14.3%) across 3 scan(s) needed manual correction — PASS against G9\'s bar of under 15%.**')
        ->toContain('⚠️ **1 wrong date(s) would be right with the day and month swapped.**')
        ->toContain('| photo | 1 | 7 | 3 | 42.9% | FAIL | 2 | 0 |')
        ->toContain('| clean | 2 | 14 | 0 | 0.0% | pass | 0 | 0 |')
        ->toContain('| withheld below the review threshold, though it was right | 1 |')
        ->toContain('| wrong, and NOT flagged (a silent error) | 2 |')
        ->toContain('| two-pages | 2 | clean | read | v1 (stamp) |')
        ->toContain('notes.txt — text/plain is not a type the app takes')
        ->toContain('  - bogus_column')
        ->toContain('- **Scans with no answer row (read, not scored):** 1')
        ->toContain('  - sheet3.png');

    $fields = ocrBakeoffFieldsCsv("{$samples}/_bakeoff/fields.csv");
    expect($fields['sheet2.png/age'])->toMatchArray(['value_read' => '35', 'correct_answer' => '34', 'confidence' => '95', 'verdict' => 'wrong, and NOT flagged (a silent error)'])
        ->and($fields['sheet2.png/visit_date']['verdict'])->toContain('day and month swapped')
        ->and($fields['sheet2.png/notes'])->toMatchArray(['value_read' => 'SORE THROAT', 'confidence' => '50', 'verdict' => 'withheld below the review threshold, though it was right'])
        ->and($fields['sheet1.png/consent'])->toMatchArray(['value_read' => 'yes', 'correct_answer' => 'yes', 'verdict' => 'right'])
        ->and($fields['sheet3.png/age']['verdict'])->toBe('not scored');
});

it('fails, and says why, when an answer cannot be understood or names no scan — and leaves that field unscored', function (): void {
    $layout = ocrBakeoffExportLayout($this->dir.'/layout', $this->form->id);
    ocrBakeoffScan("{$this->dir}/samples/sheet1.png", $this->model, ocrBakeoffCleanAnswers());

    $row = ocrBakeoffCleanRow('sheet1', 'clean');
    $row[3] = 'thirty-four';
    $answers = ocrBakeoffSheet("{$this->dir}/answers.csv", [$row, ocrBakeoffCleanRow('sheet9', 'clean')]);

    $this->artisan('ocr:bakeoff', ['folder' => $this->dir.'/samples', '--layout' => $layout, '--answers' => $answers, '--offline' => true])
        ->expectsOutputToContain('row 2 (sheet1), column Age: "thirty-four" — not a whole number')
        ->expectsOutputToContain('row 3: sheet9')
        ->assertExitCode(1);

    $report = (string) file_get_contents("{$this->dir}/samples/_bakeoff/report.md");
    expect($report)->toContain('**0 of 6 scored fields')
        ->not->toContain('day and month swapped')
        ->and(ocrBakeoffFieldsCsv("{$this->dir}/samples/_bakeoff/fields.csv")['sheet1.png/age']['verdict'])->toBe('not scored');
});

it('calls the provider once per page, caches the answer beside it, and never pays for that page again', function (): void {
    $layout = ocrBakeoffExportLayout($this->dir.'/layout', $this->form->id);
    $samples = $this->dir.'/samples';
    foreach (['a.png', 'b.png'] as $name) {
        file_put_contents("{$samples}/{$name}", (string) base64_decode(ReadScanFixture::PNG));
    }
    config()->set('ocr.google_vision.key', 'test-key-not-real');
    Http::fake(['vision.googleapis.com/*' => Http::response(PrintedPageTypesetter::fromModel($this->model, ocrBakeoffCleanAnswers())->visionImageAnswer())]);

    $this->artisan('ocr:bakeoff', ['folder' => $samples, '--layout' => $layout])->assertExitCode(0);

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => $r->hasHeader('X-Goog-Api-Key', 'test-key-not-real') && ! str_contains($r->url(), 'test-key'));
    expect(is_file("{$samples}/a.png".OcrBakeoffFolder::CACHE_SUFFIX))->toBeTrue()
        ->and(is_file("{$samples}/b.png".OcrBakeoffFolder::CACHE_SUFFIX))->toBeTrue();

    $this->artisan('ocr:bakeoff', ['folder' => $samples, '--layout' => $layout])->assertExitCode(0);
    Http::assertSentCount(2);
});

it('stops calling once the credential is refused, and reads nothing at all offline without a cache', function (): void {
    $layout = ocrBakeoffExportLayout($this->dir.'/layout', $this->form->id);
    $samples = $this->dir.'/samples';
    foreach (['a.png', 'b.png', 'c.png'] as $name) {
        file_put_contents("{$samples}/{$name}", (string) base64_decode(ReadScanFixture::PNG));
    }
    config()->set('ocr.google_vision.key', 'test-key-not-real');
    $billing = json_decode((string) file_get_contents(base_path('tests/fixtures/ocr/vision-403-billing-disabled.json')), true);
    Http::fake(['vision.googleapis.com/*' => Http::response($billing, 403)]);

    $this->artisan('ocr:bakeoff', ['folder' => $samples, '--layout' => $layout])
        ->expectsOutputToContain('No scan was read')
        ->assertExitCode(1);

    Http::assertSentCount(1);
    $report = (string) file_get_contents("{$samples}/_bakeoff/report.md");
    expect($report)->toContain('`a.png` — provider_billing_disabled: The reading service refused the scan because billing is not enabled')
        ->toContain('`b.png` — Not sent: The reading service refused the scan because billing is not enabled')
        ->and(is_file("{$samples}/a.png".OcrBakeoffFolder::CACHE_SUFFIX))->toBeFalse();

    $this->artisan('ocr:bakeoff', ['folder' => $samples, '--layout' => $layout, '--offline' => true])->assertExitCode(1);
    Http::assertSentCount(1);
    expect((string) file_get_contents("{$samples}/_bakeoff/report.md"))->toContain('--offline forbids calling the provider');
});

it('refuses a missing layout file and a folder with nothing the app would take', function (): void {
    File::ensureDirectoryExists($this->dir.'/empty');
    file_put_contents($this->dir.'/empty/readme.txt', 'hello');
    $layout = ocrBakeoffExportLayout($this->dir.'/layout', $this->form->id);

    $this->artisan('ocr:bakeoff', ['folder' => $this->dir.'/empty'])
        ->expectsOutputToContain('No layout file at')->assertExitCode(1);
    $this->artisan('ocr:bakeoff', ['folder' => $this->dir.'/empty', '--layout' => $layout])
        ->expectsOutputToContain('readme.txt — text/plain is not a type the app takes')->assertExitCode(1);
});
