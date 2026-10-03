<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionStatus;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintRenderer;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionExporter;
use App\Services\Submissions\SubmissionPdfRenderer;
use App\Services\Xlsform\XlsformExporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M129 — where a note's content blocks go today, and where they do not (R-6dedc3a9's editor half).
|--------------------------------------------------------------------------
| The editor ships before the renderer (`R-c9f50df2`), so content an author composes today reaches NONE of the
| outputs a respondent or an analyst sees: the printed form, the response PDF, the XLSForm export and the CSV export
| each still show the note by its label, or not at all. `docs/piping-output-encoding-design.md` §5 records it per
| surface; these cases are what hold that record true, and the renderer row is what will change one of them.
|
| ⚠️ EVERY ABSENCE IS PAIRED WITH A PRESENCE FROM THE SAME OUTPUT. "The sentinel is not in it" is also what an empty
| or broken output says, so each case first shows the output carries something it must.
|
| ⚠️ Helpers are prefixed `noteSurfaces*`: Pest loads every test file into one process.
*/

const NOTE_SURFACES_SENTINEL = 'Sentinel-from-a-content-block';

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);

    $form = app(FormService::class)->create($this->tenant, $this->user, 'Clinic Intake');
    // The form's own image, because publish now refuses any other (M129) — this file is about where content goes.
    $image = Attachment::factory()->clean()->create([
        'attachable_type' => 'form',
        'attachable_id' => $form->id,
        'kind' => AttachmentKind::FormContentImage,
        'mime_type' => 'image/png',
    ]);
    addFormField($form->draftVersion, $this->user, 'full_name', FieldType::ShortText, 0, [
        'label' => 'Full name',
        'is_required' => RequiredMode::Required,
    ]);
    addFormField($form->draftVersion, $this->user, 'welcome', FieldType::Note, 1, [
        'label' => 'Welcome to the clinic.',
        'config' => ['content' => [
            ['type' => 'heading', 'level' => 1, 'text' => NOTE_SURFACES_SENTINEL.' heading'],
            ['type' => 'paragraph', 'spans' => [
                ['text' => NOTE_SURFACES_SENTINEL.' paragraph, '],
                ['text' => 'and a link', 'link' => 'https://example.org/'.NOTE_SURFACES_SENTINEL],
            ]],
            ['type' => 'callout', 'tone' => 'warning', 'spans' => [['text' => NOTE_SURFACES_SENTINEL.' callout']]],
            ['type' => 'image', 'attachment_id' => $image->id, 'alt' => NOTE_SURFACES_SENTINEL.' image'],
        ]],
    ]);

    $this->version = app(PublishService::class)->publish($form->refresh(), $this->user);
    $this->form = $form->refresh();
});

it('keeps the content out of the printed form, which still prints the note by its label', function (): void {
    $html = app(BlankFormPrintRenderer::class)->html($this->form, $this->version);

    expect($html)->toContain('Welcome to the clinic.')
        ->and($html)->not->toContain(NOTE_SURFACES_SENTINEL);
});

it('keeps the content out of a response’s PDF, which carries the answers', function (): void {
    $submission = seedInboxSubmission($this->form, $this->user, SubmissionStatus::Submitted, ['full_name' => 'Ana Reyes']);

    $html = app(SubmissionPdfRenderer::class)->html($submission);

    expect($html)->toContain('Ana Reyes')
        ->and($html)->not->toContain(NOTE_SURFACES_SENTINEL);
});

it('keeps the content out of the XLSForm export, which still carries the note by its label', function (): void {
    $workbook = json_encode(app(XlsformExporter::class)->build($this->form, $this->version), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($workbook)->toContain('Welcome to the clinic.')
        ->and($workbook)->not->toContain(NOTE_SURFACES_SENTINEL);
});

it('keeps the content out of the CSV export, which no note ever reaches', function (): void {
    seedInboxSubmission($this->form, $this->user, SubmissionStatus::Submitted, ['full_name' => 'Ana Reyes']);

    ob_start();
    app(SubmissionExporter::class)->stream($this->form, [], 'csv')->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('Ana Reyes')
        ->and($csv)->not->toContain(NOTE_SURFACES_SENTINEL)
        ->and($csv)->not->toContain('Welcome to the clinic.');
});
