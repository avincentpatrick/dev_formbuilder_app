<?php

declare(strict_types=1);

use App\Enums\OcrScanSource;
use App\Enums\OcrScanStatus;
use App\Enums\SubmissionSource;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\OcrScan;
use App\Models\Submission;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Ocr\OcrScanConfirmation;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M166 — the sender of a filled paper copy checks what was read, then submits it (`R-309e4dfa`, `D116`).
|--------------------------------------------------------------------------
| `GET /api/v1/public/paper/{shareToken}/{paperToken}` tells the guest's page where the scan stands and, once it is
| read, the form's answers as read; `…/pages/{n}` serves its pages; and the guest submit takes the paper token, uses the
| scan's id as the response's idempotency key and links the scan to the response. The upload itself is
| `GuestScanUploadTest`'s, so the scans here are written directly, through the real page write path.
|
| ⚠️ Helpers are prefixed `paperReview*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
    Queue::fake();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'admin');

    $this->form = guestForm($this->tenant, $this->owner);
    $this->form->forceFill(['allow_ocr_single' => true, 'allow_guest_ocr' => true])->save();
    $this->token = shareTokenFor($this->form);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A guest scan of `$form` in `$status`, with one real page; read scans carry an extraction for its two questions. */
function paperReviewScan(Form $form, OcrScanStatus $status, OcrScanSource $source = OcrScanSource::Guest): OcrScan
{
    $id = Uuid::uuid7()->toString();
    $page = app(AttachmentStorageService::class)->storeOcrScanPage(
        UploadedFile::fake()->createWithContent('page.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')),
        (string) $form->tenant_id,
        $id,
        null,
    );
    $page->forceFill(['virus_scan_status' => 'clean'])->save();

    $scan = new OcrScan([
        'tenant_id' => $form->tenant_id,
        'form_id' => $form->id,
        'form_version_id' => $form->current_published_version_id,
        'status' => $status,
        'provider' => 'google_vision',
        'pages' => [['attachment_id' => (string) $page->id, 'response_path' => null]],
        'error_message' => $status === OcrScanStatus::Failed ? 'The provider key is not configured (staff wording).' : null,
        'extraction' => $status === OcrScanStatus::Read ? [
            'fields' => [
                'full_name' => ['type' => 'short_text', 'state' => 'read', 'value' => 'Ada Lovelace', 'text' => 'Ada Lovelace', 'confidence' => 96, 'tier' => 'auto', 'page' => 1],
                'age' => ['type' => 'integer', 'state' => 'read', 'value' => '36', 'text' => '36', 'confidence' => 78, 'tier' => 'review', 'page' => 1],
            ],
        ] : null,
    ]);
    $scan->id = $id;
    $scan->source = $source;
    $scan->save();

    return $scan;
}

function paperReviewToken(Form $form, OcrScan $scan, ?int $now = null): string
{
    return app(GuestShareTokenService::class)
        ->mintPaper($form->tenant_id, $form->id, (string) $form->current_published_version_id, $scan->id, $now)
        ->token;
}

function paperReviewUrl(string $shareToken, string $paperToken, string $tail = ''): string
{
    return "http://acme.meridian.test/api/v1/public/paper/{$shareToken}/{$paperToken}{$tail}";
}

// ── Where the scan stands ──────────────────────────────────────────────────────────────────────

it('says a scan is still being read, and that a failed one failed — never in the staff wording', function (): void {
    $queued = paperReviewScan($this->form, OcrScanStatus::Queued);
    $failed = paperReviewScan($this->form, OcrScanStatus::Failed);

    $this->getJson(paperReviewUrl($this->token, paperReviewToken($this->form, $queued)))
        ->assertOk()
        ->assertExactJson(['data' => ['status' => 'reading']]);

    $response = $this->getJson(paperReviewUrl($this->token, paperReviewToken($this->form, $failed)))->assertOk();
    expect($response->json())->toBe(['data' => ['status' => 'failed']])
        ->and($response->getContent())->not->toContain('provider key');
});

it('gives a read scan\'s answers carried onto the form, a note-worthy meta per question, and its pages, never cached', function (): void {
    $scan = paperReviewScan($this->form, OcrScanStatus::Read);
    $paper = paperReviewToken($this->form, $scan);

    $response = $this->getJson(paperReviewUrl($this->token, $paper))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json('data.status'))->toBe('read')
        ->and($response->json('data.version_id'))->toBe($this->form->current_published_version_id)
        ->and($response->json('data.answers'))->toEqual(['full_name' => 'Ada Lovelace', 'age' => 36])
        ->and($response->json('data.answers.age'))->toBe(36)
        ->and($response->json('data.fields.age.tier'))->toBe('review')
        ->and($response->json('data.fields.age.carried'))->toBeTrue()
        ->and($response->json('data.pages'))->toHaveCount(1)
        ->and($response->json('data.pages.0.url'))->toBe("/api/v1/public/paper/{$this->token}/{$paper}/pages/1")
        ->and($response->json('data.pages.0.servable'))->toBeTrue();
});

// ── Only the sender's token opens it ───────────────────────────────────────────────────────────

it('refuses a forged token, an expired one, one for another form and one naming a staff scan — after a real one is admitted', function (): void {
    $scan = paperReviewScan($this->form, OcrScanStatus::Read);
    $this->getJson(paperReviewUrl($this->token, paperReviewToken($this->form, $scan)))->assertOk();

    $real = paperReviewToken($this->form, $scan);
    $forged = substr($real, 0, -4).'AAAA';
    $this->getJson(paperReviewUrl($this->token, $forged))->assertStatus(403)->assertJsonPath('error.code', 'paper_token_invalid');

    $expired = paperReviewToken($this->form, $scan, time() - 86400);
    $this->getJson(paperReviewUrl($this->token, $expired))->assertStatus(403)->assertJsonPath('error.code', 'paper_token_expired');

    // Another form of the same workspace: its own share token cannot open this form's scan.
    enterTenant($this->tenant->id, $this->owner->id);
    $other = guestForm($this->tenant, $this->owner, 'other-intake');
    $this->getJson(paperReviewUrl(shareTokenFor($other), $real))->assertStatus(403)->assertJsonPath('error.code', 'paper_token_invalid');

    // A token minted for a scan a member uploaded opens nothing.
    enterTenant($this->tenant->id, $this->owner->id);
    $staff = paperReviewScan($this->form, OcrScanStatus::Read, OcrScanSource::Staff);
    $this->getJson(paperReviewUrl($this->token, paperReviewToken($this->form, $staff)))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'paper_token_invalid');
});

it('serves the scan\'s pages while its sender checks it, never cached, and no page it does not have', function (): void {
    $scan = paperReviewScan($this->form, OcrScanStatus::Read);
    $paper = paperReviewToken($this->form, $scan);

    $page = $this->get(paperReviewUrl($this->token, $paper, '/pages/1'))->assertOk();
    expect($page->headers->get('Content-Type'))->toBe('image/png')
        ->and($page->headers->get('Cache-Control'))->toContain('no-store');

    $this->get(paperReviewUrl($this->token, $paper, '/pages/2'))->assertNotFound();
});

// ── The guest's Submit ─────────────────────────────────────────────────────────────────────────

it('saves the checked answers as the guest\'s own response, keyed by the scan, and links the scan and its pages to it', function (): void {
    $scan = paperReviewScan($this->form, OcrScanStatus::Read);
    $paper = paperReviewToken($this->form, $scan);
    $body = ['answers' => ['full_name' => 'Ada King', 'age' => 37], 'paper_token' => $paper, 'client_submission_uuid' => Uuid::uuid7()->toString()];

    $created = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$this->token}/submissions", $body)->assertCreated();

    enterTenant($this->tenant->id, $this->owner->id);
    $submission = Submission::query()->findOrFail($created->json('data.id'));
    $scan->refresh();
    $page = Attachment::query()->findOrFail($scan->pages[0]['attachment_id']);

    expect($submission->source)->toBe(SubmissionSource::Guest)
        ->and($submission->client_submission_uuid)->toBe($scan->id)
        ->and($submission->respondent_user_id)->toBeNull()
        ->and($scan->submission_id)->toBe($submission->id)
        ->and($scan->confirmed_by)->toBeNull()
        ->and($scan->confirmed_at)->not->toBeNull()
        ->and($page->attachable_type)->toBe('submission')
        ->and($page->attachable_id)->toBe($submission->id);

    // A replayed Submit is the same response, and the scan now says it was submitted; its pages are no longer the token's.
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$this->token}/submissions", $body)
        ->assertOk()
        ->assertJsonPath('data.id', $submission->id);
    $this->getJson(paperReviewUrl($this->token, $paper))->assertExactJson(['data' => ['status' => 'submitted']]);
    $this->get(paperReviewUrl($this->token, $paper, '/pages/1'))->assertNotFound();
});

it('refuses a Submit carrying a forged paper token, and creates nothing', function (): void {
    $scan = paperReviewScan($this->form, OcrScanStatus::Read);
    $forged = substr(paperReviewToken($this->form, $scan), 0, -4).'AAAA';

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$this->token}/submissions", [
        'answers' => ['full_name' => 'Ada'],
        'paper_token' => $forged,
    ])->assertStatus(403)->assertJsonPath('error.code', 'paper_token_invalid');

    enterTenant($this->tenant->id, $this->owner->id);
    expect(Submission::query()->count())->toBe(0)
        ->and($scan->refresh()->submission_id)->toBeNull();
});

it('lets a staff save that came first win, and tells the guest so rather than making a second response', function (): void {
    $scan = paperReviewScan($this->form, OcrScanStatus::Read);
    $paper = paperReviewToken($this->form, $scan);

    enterTenant($this->tenant->id, $this->owner->id);
    app(OcrScanConfirmation::class)->confirm($this->form->currentPublishedVersion, $scan, $this->owner, ['full_name' => 'Ada Lovelace']);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$this->token}/submissions", [
        'answers' => ['full_name' => 'Ada King'],
        'paper_token' => $paper,
    ])->assertStatus(409);

    enterTenant($this->tenant->id, $this->owner->id);
    expect(Submission::query()->count())->toBe(1)
        ->and($scan->refresh()->confirmed_by)->toBe($this->owner->id);
});
