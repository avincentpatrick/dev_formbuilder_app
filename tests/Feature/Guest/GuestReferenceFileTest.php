<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\FieldType;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersionReferenceFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M138 — a form's reference files are NOT served to respondents (R-10c9e1bc, D92 = A)
|--------------------------------------------------------------------------
| M132 listed a form's PDFs and images on the guest schema (`version.reference_files`) and served each from
| `GET /api/v1/public/reference-files/{shareToken}/{file}`. The user's staging smoke test found that no tool they
| compared offers attached files to respondents — Kobo and ODK use an attached file only inside the form — so D92
| hides them: the schema lists none, and the route, kept registered for the Kobo-style rebuild, refuses every file
| with the same enveloped 404. Staff keep the files (FormReferenceFileLifecycleTest, FormReferenceFileUploadTest).
|
| ⚠️ The positive control is every fact M132 served on: the file is the form's own, clean, and frozen into the very
| version the token was minted for. Each still holds, and the answer is still 404.
|
| ⚠️ Helpers are prefixed `guestReferenceFile*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake(config('filesystems.default'));

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function guestReferenceFileUrl(string $token, string $file): string
{
    return "http://acme.meridian.test/api/v1/public/reference-files/{$token}/{$file}";
}

/** A form's own clean reference file, written to the table and the disk, attached to the form's draft. */
function guestReferenceFileStored(Form $form, string $label, array $state = []): Attachment
{
    $attachment = Attachment::factory()->create([
        'attachable_type' => 'form',
        'attachable_id' => $form->id,
        'kind' => AttachmentKind::FormReferenceFile,
        'mime_type' => 'application/pdf',
        'original_filename' => 'guide.pdf',
        'virus_scan_status' => ScanStatus::Clean,
        ...$state,
    ]);
    Storage::disk($attachment->disk)->put($attachment->path, '%PDF-BYTES');

    $draftId = (string) $form->refresh()->draft_version_id;
    FormVersionReferenceFile::create([
        'form_version_id' => $draftId,
        'attachment_id' => $attachment->id,
        'label' => $label,
        'position' => FormVersionReferenceFile::query()->where('form_version_id', $draftId)->count() + 1,
    ]);

    return $attachment;
}

/**
 * A guest-enabled form whose published version shows one PDF, and the PDF.
 *
 * @return array{0: Form, 1: Attachment}
 */
function guestReferenceFileForm(Tenant $tenant, User $owner, string $slug = 'intake'): array
{
    $form = app(FormService::class)->create($tenant, $owner, 'Clinic Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0);
    $file = guestReferenceFileStored($form, 'Visit guide');
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update(['public_slug' => $slug, 'allow_guest_submissions' => true]);

    return [$form->refresh(), $file];
}

it('refuses every reference file with one enveloped 404, a clean file the token’s own version lists included', function (string $mime): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');
    addFormField($form->draftVersion, $this->owner, 'full_name', FieldType::ShortText, 0);
    $file = guestReferenceFileStored($form, 'Visit guide', ['mime_type' => $mime]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $form->refresh()->update(['public_slug' => 'intake', 'allow_guest_submissions' => true]);
    $form->refresh();

    // Every fact M132 served on still holds.
    expect($file->refresh()->virus_scan_status->servable())->toBeTrue()
        ->and(FormVersionReferenceFile::query()
            ->where('form_version_id', $form->current_published_version_id)
            ->where('attachment_id', $file->id)
            ->exists())->toBeTrue();

    $this->getJson(guestReferenceFileUrl(shareTokenFor($form), $file->id))
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'file_not_found', 'message' => 'This file is not available.']]);
})->with(['a PDF' => 'application/pdf', 'an image' => 'image/png']);

it('lists no reference files on the schema, while the version still holds them for staff', function (): void {
    [$form, $file] = guestReferenceFileForm($this->tenant, $this->owner);

    $this->getJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form))
        ->assertOk()
        ->assertJsonPath('data.version.id', $form->current_published_version_id)
        ->assertJsonMissingPath('data.version.reference_files');

    enterTenant($this->tenant->id, $this->owner->id);
    expect(FormVersionReferenceFile::query()->where('attachment_id', $file->id)->count())->toBeGreaterThan(0);
});
