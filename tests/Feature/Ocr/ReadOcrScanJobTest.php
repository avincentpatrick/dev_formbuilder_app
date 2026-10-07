<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OcrScanStatus;
use App\Enums\ScanStatus;
use App\Jobs\ReadOcrScanJob;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\OcrScan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Ocr\OcrScanService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ocr\Support\PrintedPageTypesetter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M128 — the reading job: one page per run, a scan that always ends `read` or `failed`.
|--------------------------------------------------------------------------
| The provider answer is a Cloud Vision `images:annotate` body typeset from the form's own print model, so
| the parser, the line builder and the matcher all run for real. `withFakeQueueInteractions()` is what makes
| a release VISIBLE: called bare, `release()` is a silent no-op and a test of it passes over nothing.
|
| ⚠️ Helpers are prefixed `ocrJob*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    Storage::fake('local');
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('ocr.google_vision.key', 'test-key-not-real');

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);

    $form = app(FormService::class)->create($this->tenant, $this->user, 'Patient Intake');
    addFormField($form->draftVersion, $this->user, 'patient_name', FieldType::ShortText, 1, ['label' => 'Patient name']);
    addFormField($form->draftVersion, $this->user, 'age', FieldType::Integer, 2, ['label' => 'Age']);
    addFormField($form->draftVersion, $this->user, 'consent', FieldType::YesNo, 3, ['label' => 'Consent given']);
    $this->version = app(PublishService::class)->publish($form->refresh(), $this->user);
    $form = $form->refresh();
    $form->forceFill(['allow_ocr_single' => true])->save();
    $this->form = $form;
});

/** A real 1x1 PNG (the type is sniffed, not declared). */
function ocrJobPng(string $name = 'page.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

/** Store a scan of `$pages` photos, with each page's virus scan already settled. */
function ocrJobScan(Form $form, User $user, int $pages = 1, ScanStatus $scan = ScanStatus::Skipped): OcrScan
{
    $files = array_map(static fn (int $i): UploadedFile => ocrJobPng("p{$i}.png"), range(1, $pages));
    $stored = app(OcrScanService::class)->store($form, $user, $files);
    Attachment::query()->whereIn('id', array_column($stored->pages, 'attachment_id'))->update(['virus_scan_status' => $scan]);

    return $stored->refresh();
}

/**
 * The Vision answer for a filled copy of the form's printed page.
 *
 * @param  array<string, mixed>  $options  the typesetter's — `layout` null prints a layout-1 sheet
 */
function ocrJobAnswer(Form $form, array $answers, array $options = []): array
{
    $model = app(BlankFormPrintPresenter::class)->present($form, $form->currentPublishedVersion);

    return PrintedPageTypesetter::fromModel($model, $answers, $options)->visionImageAnswer(0.04);
}

function ocrJobRun(OcrScan $scan): ReadOcrScanJob
{
    $job = (new ReadOcrScanJob($scan->id, $scan->tenant_id))->withFakeQueueInteractions();
    $job->handle();
    enterTenant($scan->tenant_id); // the run left the context elsewhere

    return $job;
}

it('reads a photographed page end to end and records the answers, the version and the per-scan confidence', function (): void {
    $scan = ocrJobScan($this->form, $this->user);
    Http::fake(['vision.googleapis.com/*' => Http::response(ocrJobAnswer($this->form, ['patient_name' => 'ANA REYES', 'age' => '41', 'consent' => ['No']]))]);

    ocrJobRun($scan)->assertNotReleased();
    $scan->refresh();

    expect($scan->status)->toBe(OcrScanStatus::Read)
        ->and($scan->form_version_id)->toBe($this->version->id)
        ->and($scan->read_at)->not->toBeNull()
        ->and($scan->extraction['version']['matched_by'])->toBe('stamp')
        // toEqual, not toBe: jsonb keeps no key order (the M111 trap), so the review screen must order fields
        // by the printed layout and never by this map.
        ->and(array_map(static fn (array $f): mixed => $f['value'], $scan->extraction['fields']))->toEqual([
            'patient_name' => 'ANA REYES',
            'age' => '41',
            'consent' => false,
        ])
        // A layout-2 sheet with a legible running head: nothing for the reviewer to be warned about.
        ->and($scan->extraction['warnings'])->toBe([]);

    $page = Attachment::query()->findOrFail($scan->pages[0]['attachment_id']);
    expect((float) $page->ocr_confidence_avg)->toBeGreaterThan(90.0);
    Storage::disk('local')->assertExists($scan->pages[0]['response_path']);
    Http::assertSentCount(1);
});

// ⚠️ ONE PROVIDER ANSWER PER CASE. `Http::fake()` STACKS its stubs and the first one that answers wins, so a
// second fake in the same case is never reached — the second scan would be read off the first case's page, and
// the assertion on it would pass or fail for the wrong reason (measured: it did both).

it('refuses a sheet printed from an older layout, with the reason and the way out (M143, R-d6546409)', function (): void {
    // The stamp names the schema; "Layout 2" beside it names the template. A sheet whose running head was
    // legibly read WITHOUT it is layout-1 paper, whose answers sit in other places than the reader expects.
    $scan = ocrJobScan($this->form, $this->user);
    Http::fake(['vision.googleapis.com/*' => Http::response(ocrJobAnswer($this->form, ['age' => '41'], ['layout' => null]))]);

    ocrJobRun($scan);
    $scan->refresh();

    expect($scan->status)->toBe(OcrScanStatus::Failed)
        ->and($scan->error_code)->toBe('layout_outdated')
        ->and($scan->error_message)->toContain('older layout')
        ->and($scan->error_message)->toContain('Key the response in by hand');
});

it('refuses a sheet whose layout number is below the current one the same way', function (): void {
    $scan = ocrJobScan($this->form, $this->user);
    Http::fake(['vision.googleapis.com/*' => Http::response(ocrJobAnswer($this->form, ['age' => '41'], ['layout' => 1]))]);

    ocrJobRun($scan);

    expect($scan->refresh()->status)->toBe(OcrScanStatus::Failed)
        ->and($scan->error_code)->toBe('layout_outdated');
});

it('warns, rather than refuses, when the layout word is garbled', function (): void {
    // A refusal needs positive evidence. A garbled word says nothing about the paper; the sheet is read and
    // the reviewer is told to check against the paper.
    $scan = ocrJobScan($this->form, $this->user);
    Http::fake(['vision.googleapis.com/*' => Http::response(ocrJobAnswer($this->form, ['age' => '41'], ['layout' => 'Z']))]);

    ocrJobRun($scan);
    $scan->refresh();

    expect($scan->status)->toBe(OcrScanStatus::Read)
        ->and($scan->extraction['warnings'])->toBe(['layout_unconfirmed'])
        ->and($scan->extraction['fields']['age']['value'])->toBe('41');
});

it('warns, rather than refuses, when the running head was not read at all', function (): void {
    // No stamp and no layout word: refusing on absence would refuse every badly photographed NEW sheet. Both
    // uncertainties are reported, and the current version is used as before.
    $scan = ocrJobScan($this->form, $this->user);
    Http::fake(['vision.googleapis.com/*' => Http::response(ocrJobAnswer($this->form, ['age' => '41'], ['layout' => null, 'stamp' => null]))]);

    ocrJobRun($scan);
    $scan->refresh();

    expect($scan->status)->toBe(OcrScanStatus::Read)
        ->and($scan->extraction['version']['matched_by'])->toBe('unconfirmed')
        ->and($scan->extraction['warnings'])->toBe(['version_unconfirmed', 'layout_unconfirmed']);
});

it('reuses a page answer already on disk instead of paying for the page twice', function (): void {
    // A run killed after the call loses its row update to the rollback, but not the file it wrote first.
    $scan = ocrJobScan($this->form, $this->user);
    $attachment = Attachment::query()->findOrFail($scan->pages[0]['attachment_id']);
    $path = dirname((string) $attachment->path)."/{$scan->id}-p0.vision.json";
    Storage::disk('local')->put($path, (string) json_encode(ocrJobAnswer($this->form, ['age' => '7'])));
    Http::fake();

    ocrJobRun($scan);

    expect($scan->refresh()->status)->toBe(OcrScanStatus::Read)
        ->and($scan->extraction['fields']['age']['value'])->toBe('7');
    Http::assertNothingSent();
});

it('reads one page per run and queues the next run, matching only after the last page', function (): void {
    $scan = ocrJobScan($this->form, $this->user, 2);
    Http::fake(['vision.googleapis.com/*' => Http::response(ocrJobAnswer($this->form, ['age' => '12']))]);

    ocrJobRun($scan);
    expect($scan->refresh()->status)->toBe(OcrScanStatus::Reading)
        ->and($scan->pages[0]['response_path'])->not->toBeNull()
        ->and($scan->pages[1]['response_path'])->toBeNull();
    Queue::assertPushed(ReadOcrScanJob::class, fn (ReadOcrScanJob $job): bool => $job->scanId === $scan->id);

    ocrJobRun($scan);
    expect($scan->refresh()->status)->toBe(OcrScanStatus::Read)
        ->and($scan->extraction['pages'])->toBe(2);
    Http::assertSentCount(2);
});

it('ends the scan at once, with the provider\'s own advice, when billing is off', function (): void {
    $scan = ocrJobScan($this->form, $this->user);
    $body = json_decode((string) file_get_contents(base_path('tests/fixtures/ocr/vision-403-billing-disabled.json')), true);
    Http::fake(['vision.googleapis.com/*' => Http::response($body, 403)]);

    ocrJobRun($scan)->assertNotReleased();
    $scan->refresh();

    expect($scan->status)->toBe(OcrScanStatus::Failed)
        ->and($scan->error_code)->toBe('provider_billing_disabled')
        ->and($scan->error_message)->toContain('enable billing')
        ->and($scan->attempts)->toBe(0);
    // The scan is kept whatever the provider said (docs/ocr-pipeline-design.md §6).
    Storage::disk('local')->assertExists(Attachment::query()->findOrFail($scan->pages[0]['attachment_id'])->path);
});

it('retries an outage with a growing delay, and fails the scan once the attempts run out', function (): void {
    config()->set('ocr.max_attempts', 3);
    $scan = ocrJobScan($this->form, $this->user);
    Http::fake(['vision.googleapis.com/*' => Http::response(['error' => ['code' => 503]], 503)]);

    ocrJobRun($scan)->assertReleased(15);
    expect($scan->refresh()->attempts)->toBe(1)->and($scan->status)->toBe(OcrScanStatus::Queued);

    ocrJobRun($scan)->assertReleased(60);
    expect($scan->refresh()->attempts)->toBe(2);

    ocrJobRun($scan)->assertNotReleased();
    expect($scan->refresh()->status)->toBe(OcrScanStatus::Failed)
        ->and($scan->error_code)->toBe('provider_unavailable');
});

it('waits for a page\'s virus check before sending it anywhere', function (): void {
    $scan = ocrJobScan($this->form, $this->user, 1, ScanStatus::Pending);
    Http::fake();

    ocrJobRun($scan)->assertReleased();

    expect($scan->refresh()->attempts)->toBe(1)
        ->and($scan->status)->toBe(OcrScanStatus::Queued);
    Http::assertNothingSent();
});

it('never reads a page that failed its virus check', function (): void {
    $scan = ocrJobScan($this->form, $this->user, 1, ScanStatus::Infected);
    Http::fake();

    ocrJobRun($scan);

    expect($scan->refresh()->status)->toBe(OcrScanStatus::Failed)
        ->and($scan->error_code)->toBe('infected_upload');
    Http::assertNothingSent();
});

it('leaves a finished scan alone', function (): void {
    $scan = ocrJobScan($this->form, $this->user);
    $scan->forceFill(['status' => OcrScanStatus::Failed, 'error_code' => 'provider_unauthorized'])->save();
    Http::fake();

    ocrJobRun($scan);

    expect($scan->refresh()->status)->toBe(OcrScanStatus::Failed);
    Http::assertNothingSent();
});
