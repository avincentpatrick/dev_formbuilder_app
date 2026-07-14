<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ScanStatus;
use App\Enums\SubmissionSource;
use App\Exceptions\Attachments\AttachmentException;
use App\Exceptions\Forms\PublishValidationException;
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
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SubmissionPayload;
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
