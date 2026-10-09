<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ScanStatus;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\OcrScan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Ocr\Support\ReadScanFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M154 (`R-1585698b`) — correcting a scanned response keeps the paper beside the form.
|--------------------------------------------------------------------------
| `M153` put the scanned pages on the response page, and its "Edit answers" button then opened the encode page
| without them — so a reviewer who spotted a misread answer lost the paper the moment they went to fix it. These
| cases drive the real save (`confirm`) and then the real edit page.
|
| Every GET carries `withoutVite()` and `->component(..., false)` — the Pest CI job builds no assets, and
| Inertia's page-file check cannot resolve a component on a case-sensitive filesystem.
|
| ⚠️ Helpers are prefixed `ocrEdit*`: Pest loads every test file into one process, and `M153`'s `ocrPages*`
| helpers are not loaded when this file runs alone.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
    Queue::fake();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Clinic Visit');
    addFormField($form->draftVersion, $this->admin, 'patient_name', FieldType::ShortText, 1, ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($form->refresh(), $this->admin);
    $this->form = $form->refresh();
    $this->form->forceFill(['allow_ocr_single' => true])->save();

    $this->scan = ReadScanFixture::make($this->form, $this->admin, [
        'patient_name' => ReadScanFixture::read('short_text', 'Maria', 'MARIA', 96),
    ]);
    // The fixture's page — created first, so it is the OLDER file of every pair below.
    $this->firstFile = $this->scan->pages[0]['attachment_id'];
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Store one more page through the real write path and put it FIRST in the scan's page list. */
function ocrEditAddFirst(OcrScan $scan, User $uploader): string
{
    $file = app(AttachmentStorageService::class)->storeOcrScanPage(
        UploadedFile::fake()->createWithContent('page.png', (string) base64_decode(ReadScanFixture::PNG)),
        (string) $scan->tenant_id,
        $scan->id,
        (string) $uploader->id,
    );
    Attachment::query()->whereKey($file->id)->update(['virus_scan_status' => ScanStatus::Skipped->value]);
    $scan->forceFill(['pages' => [['attachment_id' => (string) $file->id, 'response_path' => null], ...$scan->pages]])->save();

    return (string) $file->id;
}

/** Save the scan through the real route, as the reviewer does, and return the response's id. */
function ocrEditSave(Tenant $tenant, User $reviewer, Form $form, OcrScan $scan): string
{
    test()->actingAs($reviewer)
        ->from("http://acme.meridian.test/forms/{$form->id}/ocr/scans/{$scan->id}/review")
        ->post("http://acme.meridian.test/forms/{$form->id}/ocr/scans/{$scan->id}/confirm", ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect();

    enterTenant($tenant->id, $reviewer->id);

    return (string) $scan->refresh()->submission_id;
}

/** @return array{number: int, url: string, mime: string, servable: bool} */
function ocrEditPage(int $number, string $file): array
{
    return ['number' => $number, 'url' => "/attachments/{$file}", 'mime' => 'image/png', 'servable' => true];
}

it('shows a saved scan\'s pages beside the answers being corrected, in the scan\'s order', function (): void {
    $newer = ocrEditAddFirst($this->scan, $this->admin);
    $submissionId = ocrEditSave($this->tenant, $this->admin, $this->form, $this->scan);

    $this->withoutVite()->actingAs($this->admin)
        ->get("http://acme.meridian.test/submissions/{$submissionId}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('editing.answers.patient_name', 'Maria')
            ->where('scan_pages', [ocrEditPage(1, $newer), ocrEditPage(2, $this->firstFile)]));
});

it('gives each corrected response its own scan\'s pages, and none to a response that was not a scan', function (): void {
    // Two saved scans of ONE form — the case that caught `M153`'s survivor: a lookup by form alone hands both
    // responses the same scan, and the ownership filter then quietly empties one of them.
    $other = ReadScanFixture::make($this->form, $this->admin, [
        'patient_name' => ReadScanFixture::read('short_text', 'Jose', 'JOSE', 96),
    ]);
    $otherFile = $other->pages[0]['attachment_id'];
    $firstResponse = ocrEditSave($this->tenant, $this->admin, $this->form, $this->scan);
    $otherResponse = ocrEditSave($this->tenant, $this->admin, $this->form, $other);
    $typed = seedInboxSubmission($this->form, $this->admin, SubmissionStatus::Submitted, ['patient_name' => 'Ana'], SubmissionSource::Manual);

    foreach ([$firstResponse => [ocrEditPage(1, $this->firstFile)], $otherResponse => [ocrEditPage(1, $otherFile)], $typed->id => []] as $response => $pages) {
        $this->withoutVite()->actingAs($this->admin)
            ->get("http://acme.meridian.test/submissions/{$response}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('submissions/Encode', false)
                ->where('scan_pages', $pages));
    }
});

it('sends no pages to an editor who may correct the answers but not open the response\'s files', function (): void {
    /*
     * Reachable only synthetically — every shipped role that may edit a response may also read it. But
     * `SubmissionPolicy::update()` does not require `submissions.view`, and the file route does, so without the
     * guard such an editor would be handed links that answer 403: a page of broken images.
     */
    $submissionId = ocrEditSave($this->tenant, $this->admin, $this->form, $this->scan);

    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    $editor->syncRoles([]);
    $editor->syncPermissions(['submissions.edit.any', 'dashboard.form.view', 'dashboard.org.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The premise, measured: this editor really is refused the page's file.
    $this->actingAs($editor)->get("http://acme.meridian.test/attachments/{$this->firstFile}")->assertForbidden();

    $this->withoutVite()->actingAs($editor)
        ->get("http://acme.meridian.test/submissions/{$submissionId}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('scan_pages', []));
});
