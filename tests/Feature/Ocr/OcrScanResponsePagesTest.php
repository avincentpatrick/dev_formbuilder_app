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
| M153 (`R-1e00f872`) — a response saved from a scan shows the pages it was read from.
|--------------------------------------------------------------------------
| The user's words on staging: "i did not see the 2 photos when i click the submitted response". Saving a scan
| already moved its page files to the response; the response page listed none of them. These cases drive the
| real save (`confirm`) and then the real response page, so what they pin is what a reviewer sees.
|
| Every GET carries `withoutVite()` and `->component(..., false)` — the Pest CI job builds no assets, and
| Inertia's page-file check cannot resolve a component on a case-sensitive filesystem.
|
| ⚠️ Helpers are prefixed `ocrPages*`: Pest loads every test file into one process.
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

    $this->form = ocrPagesForm($this->tenant, $this->admin);
    $this->scan = ReadScanFixture::make($this->form, $this->admin, [
        'patient_name' => ReadScanFixture::read('short_text', 'Maria', 'MARIA', 96),
    ]);
    // The fixture's page — created first, so it is the OLDER file of every pair below.
    $this->firstFile = $this->scan->pages[0]['attachment_id'];
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function ocrPagesForm(Tenant $tenant, User $user): Form
{
    $form = app(FormService::class)->create($tenant, $user, 'Clinic Visit');
    addFormField($form->draftVersion, $user, 'patient_name', FieldType::ShortText, 1, ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($form->refresh(), $user);

    $form = $form->refresh();
    $form->forceFill(['allow_ocr_single' => true])->save();

    return $form;
}

/**
 * Store one more page through the real write path and put it in the scan's page list — FIRST or last. A page put
 * first is newer than the page after it, which is what proves the response numbers pages by the scan's order and
 * not by the files'.
 */
function ocrPagesAdd(OcrScan $scan, User $uploader, bool $first, bool $servable = true): string
{
    $file = app(AttachmentStorageService::class)->storeOcrScanPage(
        UploadedFile::fake()->createWithContent('page.png', (string) base64_decode(ReadScanFixture::PNG)),
        (string) $scan->tenant_id,
        $scan->id,
        (string) $uploader->id,
    );
    if ($servable) {
        Attachment::query()->whereKey($file->id)->update(['virus_scan_status' => ScanStatus::Skipped->value]);
    }

    $entry = ['attachment_id' => (string) $file->id, 'response_path' => null];
    $scan->forceFill(['pages' => $first ? [$entry, ...$scan->pages] : [...$scan->pages, $entry]])->save();

    return (string) $file->id;
}

/** Save the scan through the real route, as the reviewer does, and return the response's id. */
function ocrPagesSave(Tenant $tenant, User $reviewer, Form $form, OcrScan $scan): string
{
    test()->actingAs($reviewer)
        ->from("http://acme.meridian.test/forms/{$form->id}/ocr/scans/{$scan->id}/review")
        ->post("http://acme.meridian.test/forms/{$form->id}/ocr/scans/{$scan->id}/confirm", ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect();

    enterTenant($tenant->id, $reviewer->id);

    return (string) $scan->refresh()->submission_id;
}

function ocrPagesUrl(string $path): string
{
    return "http://acme.meridian.test{$path}";
}

it('shows a saved scan\'s pages on its response, numbered in the scan\'s order', function (): void {
    $newer = ocrPagesAdd($this->scan, $this->admin, first: true);
    $submissionId = ocrPagesSave($this->tenant, $this->admin, $this->form, $this->scan);

    $this->withoutVite()->actingAs($this->admin)
        ->get(ocrPagesUrl("/submissions/{$submissionId}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Show', false)
            ->where('scan_pages', [
                ['number' => 1, 'url' => "/attachments/{$newer}", 'mime' => 'image/png', 'servable' => true],
                ['number' => 2, 'url' => "/attachments/{$this->firstFile}", 'mime' => 'image/png', 'servable' => true],
            ]));
});

it('links each page to a file the response\'s own reader can open', function (): void {
    $second = ocrPagesAdd($this->scan, $this->admin, first: false);
    $submissionId = ocrPagesSave($this->tenant, $this->admin, $this->form, $this->scan);

    // A viewer may open the response but may not create one, so the review screen's page route refuses them —
    // which is why the response page must never link there.
    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $urls = ["/attachments/{$this->firstFile}", "/attachments/{$second}"];
    $this->withoutVite()->actingAs($viewer)
        ->get(ocrPagesUrl("/submissions/{$submissionId}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Show', false)
            ->has('scan_pages', 2)
            ->where('scan_pages.0.url', $urls[0])
            ->where('scan_pages.1.url', $urls[1]));

    foreach ($urls as $url) {
        $this->actingAs($viewer)->get(ocrPagesUrl($url))->assertOk();
    }
    $this->actingAs($viewer)
        ->get(ocrPagesUrl("/forms/{$this->form->id}/ocr/scans/{$this->scan->id}/pages/1"))
        ->assertForbidden();
});

it('shows each response its own scan\'s pages, and none on a response that was not a scan', function (): void {
    // Two saved scans of ONE form: a lookup by form alone would hand both responses the same scan, and the
    // ownership filter would then quietly empty one of them.
    $other = ReadScanFixture::make($this->form, $this->admin, [
        'patient_name' => ReadScanFixture::read('short_text', 'Jose', 'JOSE', 96),
    ]);
    $otherFile = $other->pages[0]['attachment_id'];
    $firstResponse = ocrPagesSave($this->tenant, $this->admin, $this->form, $this->scan);
    $otherResponse = ocrPagesSave($this->tenant, $this->admin, $this->form, $other);
    $typed = seedInboxSubmission($this->form, $this->admin, SubmissionStatus::Submitted, ['patient_name' => 'Ana'], SubmissionSource::Manual);

    foreach ([$firstResponse => [$this->firstFile], $otherResponse => [$otherFile], $typed->id => []] as $response => $files) {
        $this->withoutVite()->actingAs($this->admin)
            ->get(ocrPagesUrl("/submissions/{$response}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('submissions/Show', false)
                ->where('scan_pages', array_map(
                    static fn (string $file): array => ['number' => 1, 'url' => "/attachments/{$file}", 'mime' => 'image/png', 'servable' => true],
                    $files,
                )));
    }
});

it('marks a page still waiting for its virus check instead of hiding it', function (): void {
    $pending = ocrPagesAdd($this->scan, $this->admin, first: false, servable: false);
    $submissionId = ocrPagesSave($this->tenant, $this->admin, $this->form, $this->scan);

    $this->withoutVite()->actingAs($this->admin)
        ->get(ocrPagesUrl("/submissions/{$submissionId}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Show', false)
            ->where('scan_pages.0.servable', true)
            ->where('scan_pages.1', ['number' => 2, 'url' => "/attachments/{$pending}", 'mime' => 'image/png', 'servable' => false]));
});

it('lists only the files the response owns, so it never links one the file route would refuse', function (): void {
    $second = ocrPagesAdd($this->scan, $this->admin, first: false);
    $submissionId = ocrPagesSave($this->tenant, $this->admin, $this->form, $this->scan);

    // The first page no longer belongs to the response — the file route would answer 403 for it.
    Attachment::query()->whereKey($this->firstFile)->update(['attachable_type' => 'ocr_scan', 'attachable_id' => $this->scan->id]);
    $this->actingAs($this->admin)->get(ocrPagesUrl("/attachments/{$this->firstFile}"))->assertForbidden();

    $this->withoutVite()->actingAs($this->admin)
        ->get(ocrPagesUrl("/submissions/{$submissionId}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Show', false)
            ->where('scan_pages', [
                ['number' => 2, 'url' => "/attachments/{$second}", 'mime' => 'image/png', 'servable' => true],
            ]));
});
