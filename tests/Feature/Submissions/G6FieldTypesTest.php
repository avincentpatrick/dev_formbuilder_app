<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ScanStatus;
use App\Enums\SubmissionSource;
use App\Exceptions\Attachments\AttachmentException;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Ocr\OcrException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Jobs\ScanAttachmentJob;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Forms\CapabilityFlags;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPdfPresenter;
use App\Services\Submissions\SubmissionPdfRenderer;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G6 — media capture (file / image / audio / video) end-to-end:
| the AttachmentStorageService write path (content-sniff + size/mime gate +
| server-generated key), the pipeline's Stage-1 coerceMedia + Stage-3
| processMedia + Stage-3.5 AttachmentReferenceValidator, the persist-time
| owner re-point + attachment_refs, and the pre-publish gates. The PHP⇄TS
| count/required parity itself is proven by the shared golden vectors
| (media.json); this exercises the DB-backed pieces the golden suite can't.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    Storage::fake('local');
    Queue::fake(); // don't auto-run the scan job — attachments stay `pending` (which the ref validator accepts)
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->pipeline = app(SubmissionPipeline::class);
    $this->storage = app(AttachmentStorageService::class);
});

/**
 * A minimal valid 1×1 PNG as an uploaded file. `createWithContent` writes REAL bytes (unlike `fake()->image()`,
 * which needs the GD extension), so `getMimeType()` content-sniffs `image/png` and `getimagesize()` reads 1×1 —
 * both via core fileinfo, no GD required.
 */
function g6FakeImage(string $name = 'site.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent($name, $png);
}

/** Build a form, populate its draft via $build, publish it, and return the published version. */
function g6Publish(Tenant $tenant, User $user, Closure $build): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, 'G6 Survey');
    $build($form->draftVersion, $user);

    return app(PublishService::class)->publish($form->refresh(), $user);
}

/** A published single-photo form (optional image_capture unless overridden). */
function g6PhotoVersion(Tenant $tenant, User $user, array $config = [], RequiredMode $required = RequiredMode::Optional): FormVersion
{
    return g6Publish($tenant, $user, function (FormVersion $draft, User $u) use ($config, $required): void {
        addFormField($draft, $u, 'photos', FieldType::ImageCapture, 0, ['is_required' => $required, 'config' => $config]);
    });
}

it('stores an uploaded image, staged against its form_field with a sniffed mime + a tenant-namespaced key', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user);

    $attachment = $this->storage->store(g6FakeImage('site.png'), $version, 'photos', $this->user->id);

    expect($attachment->attachable_type)->toBe('form_field')
        ->and($attachment->mime_type)->toBe('image/png')     // content-sniffed, not the client header
        ->and($attachment->width)->toBe(1)
        ->and($attachment->height)->toBe(1)
        ->and($attachment->virus_scan_status)->toBe(ScanStatus::Pending)
        ->and($attachment->path)->toStartWith("tenants/{$this->tenant->id}/field_media_sample/")
        ->and($attachment->checksum_sha256)->not->toBeNull();
    Storage::disk('local')->assertExists($attachment->path);
});

it('content-sniffs the mime and rejects a type outside the field allowlist', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user, ['accepted_types' => ['application/pdf']]);

    expect(fn () => $this->storage->store(g6FakeImage('x.png'), $version, 'photos', $this->user->id))
        ->toThrow(AttachmentException::class);
});

it('rejects a file larger than the field max size', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user, ['max_file_size_bytes' => 1024]);

    // A ~2 KB fake file exceeds the 1 KB field cap.
    expect(fn () => $this->storage->store(UploadedFile::fake()->create('big.jpg', 2, 'image/jpeg'), $version, 'photos', $this->user->id))
        ->toThrow(AttachmentException::class);
});

it('re-points the attachment to the submission and populates attachment_refs on submit', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user);
    $attachment = $this->storage->store(g6FakeImage('site.png'), $version, 'photos', $this->user->id);

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['photos' => [['id' => $attachment->id, 'mime' => 'image/jpeg']]],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $attachment->refresh();
    expect($attachment->attachable_type)->toBe('submission')
        ->and($attachment->attachable_id)->toBe($result->submission->id);

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->attachment_refs)->toBe([$attachment->id])
        ->and($answerDoc->answers['photos'])->toBe([['id' => $attachment->id, 'mime' => 'image/jpeg']]);
});

it('leaves an unreferenced staged attachment owned by its form_field (orphan)', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user);
    $used = $this->storage->store(g6FakeImage('a.png'), $version, 'photos', $this->user->id);
    $orphan = $this->storage->store(g6FakeImage('b.png'), $version, 'photos', $this->user->id);

    $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['photos' => [['id' => $used->id]]],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    expect($orphan->refresh()->attachable_type)->toBe('form_field');
});

it('rejects a media answer that is not a list at Stage 1', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user);

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['photos' => ['id' => 'not-a-list']], // an object, not a list of refs
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('structural')
            ->and($e->fieldErrors()[0]['rule'])->toBe('expected_list');
    }
});

it('enforces required + max_count at Stage 3', function (): void {
    // Required, but no files → field_required.
    $requiredVersion = g6PhotoVersion($this->tenant, $this->user, [], RequiredMode::Required);
    try {
        $this->pipeline->submit(new SubmissionPayload($requiredVersion, [], SubmissionSource::Manual, respondentUserId: $this->user->id));
        expect(false)->toBeTrue('expected a required error');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('field_required');
    }

    // max_count = 1, two refs → media_too_many.
    $cappedVersion = g6PhotoVersion($this->tenant, $this->user, ['max_count' => 1]);
    $a = $this->storage->store(g6FakeImage('a.png'), $cappedVersion, 'photos', $this->user->id);
    $b = $this->storage->store(g6FakeImage('b.png'), $cappedVersion, 'photos', $this->user->id);
    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $cappedVersion,
            answers: ['photos' => [['id' => $a->id], ['id' => $b->id]]],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a media_too_many error');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('media_too_many');
    }
});

it('rejects a reference to a nonexistent attachment (Stage 3.5)', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user);

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['photos' => [['id' => '01920000-0000-7000-8000-000000000000']]],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected an attachment_not_found error');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('attachment_not_found');
    }
    expect(Submission::query()->count())->toBe(0);
});

it('rejects an infected attachment (Stage 3.5)', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user);
    $attachment = $this->storage->store(g6FakeImage('bad.png'), $version, 'photos', $this->user->id);
    $attachment->update(['virus_scan_status' => ScanStatus::Infected]);

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['photos' => [['id' => $attachment->id]]],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected an attachment_infected error');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('attachment_infected');
    }
});

it('rejects an attachment referenced under a different field than it was staged for', function (): void {
    $version = g6Publish($this->tenant, $this->user, function (FormVersion $draft, User $u): void {
        addFormField($draft, $u, 'photos', FieldType::ImageCapture, 0);
        addFormField($draft, $u, 'docs', FieldType::FileUpload, 1);
    });
    $stagedForPhotos = $this->storage->store(g6FakeImage('a.png'), $version, 'photos', $this->user->id);

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['docs' => [['id' => $stagedForPhotos->id]]], // wrong field
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected an attachment_not_found error');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('attachment_not_found');
    }
});

it('transitions a pending attachment to skipped when scanning is disabled (ScanAttachmentJob)', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user);
    $attachment = $this->storage->store(g6FakeImage('a.png'), $version, 'photos', $this->user->id);

    (new ScanAttachmentJob($attachment->id, $this->tenant->id))->handle();

    expect($attachment->refresh()->virus_scan_status)->toBe(ScanStatus::Skipped);
});

it('refuses to publish a media field placed inside a repeatable section', function (): void {
    try {
        g6Publish($this->tenant, $this->user, function (FormVersion $draft, User $u): void {
            $section = FormSection::create(['form_version_id' => $draft->id, 'key' => 'roster', 'label' => 'Roster', 'sequence' => 0, 'is_repeatable' => true]);
            addFormField($draft, $u, 'photos', FieldType::ImageCapture, 0, ['form_section_id' => $section->id]);
        });
        expect(false)->toBeTrue('expected a PublishValidationException');
    } catch (PublishValidationException $e) {
        expect($e->getMessage())->toContain('photos');
    }
});

it('refuses to publish a media field whose min_count exceeds its max_count', function (): void {
    try {
        g6Publish($this->tenant, $this->user, function (FormVersion $draft, User $u): void {
            addFormField($draft, $u, 'photos', FieldType::ImageCapture, 0, ['config' => ['min_count' => 3, 'max_count' => 1]]);
        });
        expect(false)->toBeTrue('expected a PublishValidationException');
    } catch (PublishValidationException $e) {
        expect($e->getMessage())->toContain('photos');
    }
});

it('presents media as supported with a normalized media config + upload url (G6)', function (): void {
    $version = g6PhotoVersion($this->tenant, $this->user, [
        'accepted_types' => ['image/jpeg', 'image/png'],
        'max_file_size_bytes' => 10_485_760,
        'max_count' => 3,
        'min_count' => 1,
        'capture_source' => 'camera',
    ]);

    /** @var Form $form */
    $form = Form::query()->findOrFail($version->form_id);
    $presented = app(EncodeFormPresenter::class)->present($form, $version);
    $field = collect($presented['blocks'])->flatMap(fn (array $b): array => $b['fields'])->firstWhere('key', 'photos');

    expect($field['supported'])->toBeTrue()
        ->and($field['media'])->toBe([
            'acceptedTypes' => ['image/jpeg', 'image/png'],
            'maxFileSizeBytes' => 10_485_760,
            'maxCount' => 3,
            'minCount' => 1,
            'captureSource' => 'camera',
        ])
        ->and($field['upload']['url'])->toContain('/attachments');
});

/*
|--------------------------------------------------------------------------
| M187 (`R-af2d278b`) — a Signature anyone can sign.
|--------------------------------------------------------------------------
|
| The type was plumbed end to end by G6 (value shape, staging, policy, both validators) except for the control, and
| both channels kept it out of their supported sets, so it rendered "Not available for manual entry yet". The pad
| draws a PNG and uploads it through the same staging endpoint a photo uses.
*/

function g6SignatureVersion(Tenant $tenant, User $user, array $config = [], RequiredMode $required = RequiredMode::Optional): FormVersion
{
    return g6Publish($tenant, $user, function (FormVersion $draft, User $u) use ($config, $required): void {
        addFormField($draft, $u, 'signed', FieldType::Signature, 0, ['is_required' => $required, 'config' => $config]);
    });
}

it('presents a signature as supported, with a media config and the upload url (M187)', function (): void {
    $version = g6SignatureVersion($this->tenant, $this->user);

    /** @var Form $form */
    $form = Form::query()->findOrFail($version->form_id);
    $presented = app(EncodeFormPresenter::class)->present($form, $version);
    $field = collect($presented['blocks'])->flatMap(fn (array $b): array => $b['fields'])->firstWhere('key', 'signed');

    expect($field['supported'])->toBeTrue()
        ->and($field['media'])->toBeArray()
        ->and($field['upload']['url'])->toContain('/attachments');
});

it('stores the pad\'s PNG even when the question carries an old list of file types that leaves PNG out (M187)', function (): void {
    // Before M187 a signature took the photo's settings tab, so an author could have narrowed its file types. The pad
    // only ever sends a PNG, and a list that refuses it would leave the question impossible to answer.
    $version = g6SignatureVersion($this->tenant, $this->user, ['accepted_types' => ['image/jpeg']]);

    $attachment = $this->storage->store(g6FakeImage('signature.png'), $version, 'signed', $this->user->id);

    expect($attachment->mime_type)->toBe('image/png');
});

it('still refuses a signature that is not an image (M187)', function (): void {
    $version = g6SignatureVersion($this->tenant, $this->user);

    expect(fn () => $this->storage->store(UploadedFile::fake()->createWithContent('signature.pdf', '%PDF-1.4 fake'), $version, 'signed', $this->user->id))
        ->toThrow(AttachmentException::class);
});

it('submits a required signature answered with its staged drawing (M187)', function (): void {
    $version = g6SignatureVersion($this->tenant, $this->user, required: RequiredMode::Required);
    $attachment = $this->storage->store(g6FakeImage('signature.png'), $version, 'signed', $this->user->id);

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['signed' => [['id' => $attachment->id, 'mime' => 'image/png']]],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $attachment->refresh();
    expect($attachment->attachable_type)->toBe('submission')
        ->and($attachment->attachable_id)->toBe($result->submission->id);
});

/**
 * What the pad uploads: a 900 × 300 RGBA PNG on white (browsers export a canvas as RGBA even when it is opaque).
 *
 * ⚠️ NOT `g6FakeImage()`: that 1 × 1 PNG is grey-with-alpha (colour type 4), which dompdf silently drops — measured, the
 * document carried no image at all — so a PDF assertion against it would test a file the pad never makes.
 */
function g6PadPng(): UploadedFile
{
    $image = imagecreatetruecolor(900, 300);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 255, 255, 0));
    imageline($image, 40, 220, 860, 80, (int) imagecolorallocate($image, 17, 17, 17));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent('signature.png', (string) ob_get_clean());
}

/** A submitted response whose Signature holds one staged drawing, with that attachment's scan status set. */
function g6SignedSubmission(Tenant $tenant, User $user, AttachmentStorageService $storage, SubmissionPipeline $pipeline, ScanStatus $scan): array
{
    $version = g6SignatureVersion($tenant, $user);
    $attachment = $storage->store(g6PadPng(), $version, 'signed', $user->id);
    $result = $pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['signed' => [['id' => $attachment->id, 'mime' => 'image/png']]],
        source: SubmissionSource::Manual,
        respondentUserId: $user->id,
    ));
    $attachment->forceFill(['virus_scan_status' => $scan])->save();

    return [$result->submission->refresh(), $attachment];
}

/** @return array<string, array<string, mixed>> the response page's answer rows, by question key */
function g6DetailRows(User $user, Submission $submission): array
{
    return collect(app(SubmissionInboxPresenter::class)->detail($user, $submission)['blocks'])
        ->flatMap(fn (array $b): array => $b['fields'])
        ->keyBy('key')
        ->all();
}

it('shows a signature on the response page as its picture once the file may be served (M187)', function (): void {
    [$submission, $attachment] = g6SignedSubmission($this->tenant, $this->user, $this->storage, $this->pipeline, ScanStatus::Skipped);

    $row = g6DetailRows($this->user, $submission)['signed'];

    expect($row['image'])->toBe(['url' => route('attachments.show', ['attachment' => $attachment->id]), 'alt' => 'Signature']);
});

it('keeps a signature as text until its scan lets it be served, and for a file the scan refused (M187)', function (): void {
    [$pending] = g6SignedSubmission($this->tenant, $this->user, $this->storage, $this->pipeline, ScanStatus::Pending);
    expect(g6DetailRows($this->user, $pending)['signed']['image'])->toBeNull();

    [$infected] = g6SignedSubmission($this->tenant, $this->user, $this->storage, $this->pipeline, ScanStatus::Infected);
    expect(g6DetailRows($this->user, $infected)['signed']['image'])->toBeNull();
});

/** @return array<string, array<string, mixed>> the PDF's answer rows, by question key */
function g6PdfRows(Submission $submission): array
{
    return collect(app(SubmissionPdfPresenter::class)->present($submission)['blocks'])
        ->flatMap(fn (array $b): array => $b['fields'])
        ->keyBy('key')
        ->all();
}

it('embeds a servable signature in the response PDF as a data URI of its own file (M187)', function (): void {
    [$submission, $attachment] = g6SignedSubmission($this->tenant, $this->user, $this->storage, $this->pipeline, ScanStatus::Skipped);
    $bytes = Storage::disk($attachment->disk)->get($attachment->path);

    expect(g6PdfRows($submission)['signed']['image'])->toBe('data:image/png;base64,'.base64_encode((string) $bytes));

    // And dompdf draws it: an image XObject in the document, which no answer of text produces.
    expect(app(SubmissionPdfRenderer::class)->render($submission))->toMatch('~/Subtype\s*/Image~');
});

it('keeps the PDF row as text for a signature not yet servable, or refused by the scan (M187)', function (): void {
    [$pending] = g6SignedSubmission($this->tenant, $this->user, $this->storage, $this->pipeline, ScanStatus::Pending);
    [$infected] = g6SignedSubmission($this->tenant, $this->user, $this->storage, $this->pipeline, ScanStatus::Infected);

    expect(g6PdfRows($pending)['signed']['image'])->toBeNull()
        ->and(g6PdfRows($infected)['signed']['image'])->toBeNull()
        ->and(app(SubmissionPdfRenderer::class)->render($pending))->not->toMatch('~/Subtype\s*/Image~');
});

it('never embeds a signature too large on a side to be the pad\'s, read from the header alone (M187)', function (): void {
    [$submission, $attachment] = g6SignedSubmission($this->tenant, $this->user, $this->storage, $this->pipeline, ScanStatus::Skipped);

    // A small file whose header claims a 5000 × 10 canvas: the dimension cap refuses it before any decoder sees it.
    $wide = imagecreatetruecolor(5000, 10);
    ob_start();
    imagepng($wide);
    Storage::disk($attachment->disk)->put($attachment->path, (string) ob_get_clean());

    expect(g6PdfRows($submission)['signed']['image'])->toBeNull();
});

it('keeps a form with a Signature unscannable, and the refusal names the signature (M187, D164)', function (): void {
    $version = g6SignatureVersion($this->tenant, $this->user);

    expect(CapabilityFlags::isOcrCompatible($version))->toBeFalse()
        ->and(OcrException::formNotEligible()->getMessage())->toContain('a signature');
});
