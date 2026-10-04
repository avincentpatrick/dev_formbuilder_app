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
| M132 — a form's reference files, read by a respondent (R-bf49e4c1): `GET /api/v1/public/reference-files/{shareToken}/{file}`
|--------------------------------------------------------------------------
| The guest page lists the files the token's version shows (`version.reference_files` on the schema) and fetches
| each from this route. It serves a file only when that version shows it; every other case is the same enveloped
| 404, so the route says nothing about what exists.
|
| ⚠️ EVERY REFUSAL RUNS ITS POSITIVE CONTROL FIRST, in the same test, and then changes ONE fact (M122).
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

it('serves a PDF the token’s version shows as a download, never sniffed, and keeps it a day', function (): void {
    [$form, $file] = guestReferenceFileForm($this->tenant, $this->owner);

    $response = $this->get(guestReferenceFileUrl(shareTokenFor($form), $file->id));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe('%PDF-BYTES')
        // A PDF never renders in this origin: it is always a download.
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->getCacheControlDirective('private'))->toBeTrue()
        ->and($response->headers->getCacheControlDirective('max-age'))->toBe('86400');
});

it('serves an image the token’s version shows inline', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');
    addFormField($form->draftVersion, $this->owner, 'full_name', FieldType::ShortText, 0);
    $image = guestReferenceFileStored($form, 'Map', ['mime_type' => 'image/png', 'original_filename' => 'map.png']);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $form->refresh()->update(['public_slug' => 'intake', 'allow_guest_submissions' => true]);

    $response = $this->get(guestReferenceFileUrl(shareTokenFor($form->refresh()), $image->id));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('refuses with one enveloped 404 each file the token’s version does not show, after serving the one it does', function (string $case): void {
    [$form, $file] = guestReferenceFileForm($this->tenant, $this->owner);
    $token = shareTokenFor($form);

    // The positive control: this exact request is served before the one fact under test changes.
    $this->get(guestReferenceFileUrl($token, $file->id))->assertOk();
    enterTenant($this->tenant->id, $this->owner->id);

    $requested = match ($case) {
        // Attached after the publish: the DRAFT shows it, the token's version does not.
        'a file only the draft shows' => guestReferenceFileStored($form, 'Later')->id,
        'a file of another kind' => tap($file)->update(['kind' => AttachmentKind::FormContentImage])->id,
        'a file held by something other than a form' => tap($file)->update(['attachable_type' => 'form_field'])->id,
        'a file another form owns' => tap($file)->update([
            'attachable_id' => app(FormService::class)->create($this->tenant, $this->owner, 'Another form')->id,
        ])->id,
        'a file still being checked' => tap($file)->update(['virus_scan_status' => ScanStatus::Pending])->id,
        'a file the check refused' => tap($file)->update(['virus_scan_status' => ScanStatus::Infected])->id,
        'a form whose guest access is off' => tap($file, fn () => $form->update(['allow_guest_submissions' => false]))->id,
        'an id that is not a uuid' => 'not-a-uuid',
    };

    $this->getJson(guestReferenceFileUrl($token, $requested))
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'file_not_found', 'message' => 'This file is not available.']]);
})->with([
    'a file only the draft shows',
    'a file of another kind',
    'a file held by something other than a form',
    'a file another form owns',
    'a file still being checked',
    'a file the check refused',
    'a form whose guest access is off',
    'an id that is not a uuid',
]);

it('lists on the schema the files the version shows, past their check, in the author’s order', function (): void {
    [$form, $file] = guestReferenceFileForm($this->tenant, $this->owner);
    enterTenant($this->tenant->id, $this->owner->id);
    // Next version: a second file, plus one still being checked and one the check refused.
    $image = guestReferenceFileStored($form, 'Map', ['mime_type' => 'image/png']);
    guestReferenceFileStored($form, 'Pending', ['virus_scan_status' => ScanStatus::Pending]);
    guestReferenceFileStored($form, 'Refused', ['virus_scan_status' => ScanStatus::Infected]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $files = $this->getJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form->refresh()))
        ->assertOk()
        ->json('data.version.reference_files');

    expect($files)->toBe([
        ['id' => $file->id, 'label' => 'Visit guide', 'mime_type' => 'application/pdf', 'size_bytes' => $file->size_bytes],
        ['id' => $image->id, 'label' => 'Map', 'mime_type' => 'image/png', 'size_bytes' => $image->size_bytes],
    ]);
});

it('keeps an older token on the version it was minted for, after the newer version shows a different file', function (): void {
    [$form, $file] = guestReferenceFileForm($this->tenant, $this->owner);
    $oldToken = shareTokenFor($form);
    enterTenant($this->tenant->id, $this->owner->id);
    $later = guestReferenceFileStored($form, 'Later');
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $newToken = shareTokenFor($form->refresh());

    $this->get(guestReferenceFileUrl($newToken, $later->id))->assertOk();
    $this->get(guestReferenceFileUrl($oldToken, $file->id))->assertOk();
    $this->getJson(guestReferenceFileUrl($oldToken, $later->id))->assertNotFound();
});
