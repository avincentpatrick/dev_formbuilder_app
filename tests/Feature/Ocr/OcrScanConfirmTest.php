<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OcrScanStatus;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormField;
use App\Models\OcrScan;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Ocr\Support\ReadScanFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M129 — saving a reviewed scan: through the pipeline, once, and with its page files moved to the response.
|--------------------------------------------------------------------------
| The reviewer's answers — never the extraction — go through `SubmissionPipeline::submit()` with the scan's id
| as the idempotency key. Every refusal must come back with an errors bag, because the review page keeps the
| reviewer's corrections only when one arrives.
|
| ⚠️ Helpers are prefixed `ocrConfirm*`: Pest loads every test file into one process.
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

    $this->form = ocrConfirmForm($this->tenant, $this->admin, 'Clinic Visit');
    $this->scan = ReadScanFixture::make($this->form, $this->admin, [
        'patient_name' => ReadScanFixture::read('short_text', 'Maria', 'MARIA', 96),
        'age' => ReadScanFixture::read('integer', '41', '41', 82, 'review'),
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function ocrConfirmForm(Tenant $tenant, User $user, string $title): Form
{
    $form = app(FormService::class)->create($tenant, $user, $title);
    addFormField($form->draftVersion, $user, 'patient_name', FieldType::ShortText, 1, ['is_required' => RequiredMode::Required]);
    addFormField($form->draftVersion, $user, 'age', FieldType::Integer, 2);
    app(PublishService::class)->publish($form->refresh(), $user);

    $form = $form->refresh();
    $form->forceFill(['allow_ocr_single' => true])->save();

    return $form;
}

function ocrConfirmUrl(Form $form, OcrScan $scan): string
{
    return "http://acme.meridian.test/forms/{$form->id}/ocr/scans/{$scan->id}/confirm";
}

function ocrConfirmReviewUrl(Form $form, OcrScan $scan): string
{
    return "http://acme.meridian.test/forms/{$form->id}/ocr/scans/{$scan->id}/review";
}

it('saves the reviewer\'s answers as an ordinary response from the scan, against the current version', function (): void {
    $response = $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria Santos', 'age' => 42]]);

    enterTenant($this->tenant->id, $this->admin->id);
    $scan = $this->scan->refresh();
    $submission = Submission::query()->findOrFail($scan->submission_id);

    $response->assertRedirect("/submissions/{$submission->id}");

    expect($submission->source)->toBe(SubmissionSource::OcrSingle)
        ->and($submission->form_version_id)->toBe($this->form->current_published_version_id)
        ->and($submission->respondent_user_id)->toBe($this->admin->id)
        ->and($submission->client_submission_uuid)->toBe($scan->id)
        ->and($submission->answers->answers['patient_name'])->toBe('Maria Santos')
        ->and((int) $submission->answers->answers['age'])->toBe(42)
        ->and($scan->confirmed_by)->toBe($this->admin->id)
        ->and($scan->confirmed_at)->not->toBeNull();
});

it('moves the scan\'s page files to the response, which is what makes them readable through it', function (): void {
    $pageId = $this->scan->pages[0]['attachment_id'];

    // While the scan is a proposal its page is the scan's, and the shared file route has no owner arm for it.
    $this->actingAs($this->admin)->get("http://acme.meridian.test/attachments/{$pageId}")->assertForbidden();

    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $page = Attachment::query()->findOrFail($pageId);

    expect($page->attachable_type)->toBe('submission')
        ->and($page->attachable_id)->toBe($this->scan->refresh()->submission_id);

    $this->actingAs($this->admin)->get("http://acme.meridian.test/attachments/{$pageId}")->assertOk();
});

it('saves a scan once, however many times Save is pressed', function (): void {
    $post = fn () => $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria']]);

    $post()->assertRedirect();
    $post()->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $submissionId = $this->scan->refresh()->submission_id;

    expect(Submission::query()->where('form_id', $this->form->id)->count())->toBe(1);
    $post()->assertRedirect("/submissions/{$submissionId}");
});

it('finds the same response again when the save stopped after the response was created', function (): void {
    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect();

    // The response exists; the link step never committed.
    enterTenant($this->tenant->id, $this->admin->id);
    $first = $this->scan->refresh()->submission_id;
    $this->scan->forceFill(['submission_id' => null, 'confirmed_at' => null, 'confirmed_by' => null])->save();
    Attachment::query()->whereKey($this->scan->pages[0]['attachment_id'])->update(['attachable_type' => 'ocr_scan', 'attachable_id' => $this->scan->id]);

    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect("/submissions/{$first}");

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Submission::query()->where('form_id', $this->form->id)->count())->toBe(1)
        ->and($this->scan->refresh()->submission_id)->toBe($first);
});

it('keeps the reviewer\'s corrections when an answer is refused, and saves nothing', function (): void {
    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => null, 'age' => 42]])
        ->assertRedirect(ocrConfirmReviewUrl($this->form, $this->scan))
        ->assertSessionHasErrors('answers.patient_name');

    enterTenant($this->tenant->id, $this->admin->id);
    expect($this->scan->refresh()->submission_id)->toBeNull()
        ->and(Submission::query()->where('form_id', $this->form->id)->count())->toBe(0);
});

it('refuses a closed form with an errors bag rather than a bare toast', function (): void {
    $this->form->forceFill(['closes_at' => now()->subDay()])->save();

    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect(ocrConfirmReviewUrl($this->form, $this->scan))
        ->assertSessionHasErrors('scan');

    enterTenant($this->tenant->id, $this->admin->id);
    expect($this->scan->refresh()->submission_id)->toBeNull();
});

it('refuses another reviewer whose save would take over a response someone else already created', function (): void {
    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $this->scan->forceFill(['submission_id' => null, 'confirmed_at' => null, 'confirmed_by' => null])->save();
    $other = User::factory()->create();
    enterTenant($this->tenant->id, $other->id);
    makeActiveMember($other, 'admin');

    $this->actingAs($other)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect(ocrConfirmReviewUrl($this->form, $this->scan))
        ->assertSessionHasErrors('scan');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Submission::query()->where('form_id', $this->form->id)->count())->toBe(1);
});

it('refuses to save a scan that has not been read', function (): void {
    $queued = ReadScanFixture::make($this->form, $this->admin, [], ['status' => OcrScanStatus::Queued, 'extraction' => null]);

    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $queued))
        ->post(ocrConfirmUrl($this->form, $queued), ['answers' => ['patient_name' => 'Maria']])
        ->assertSessionHasErrors('scan');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Submission::query()->where('form_id', $this->form->id)->count())->toBe(0);
});

it('answers 404 for a scan saved under another form, after it saves under its own', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $other = ocrConfirmForm($this->tenant, $this->admin, 'Another Form');

    $this->actingAs($this->admin)
        ->post(ocrConfirmUrl($other, $this->scan), ['answers' => ['patient_name' => 'Maria']])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria']])
        ->assertRedirect();
});

it('takes the answers from the request and the idempotency key from the scan, never a caller-chosen one', function (): void {
    $forged = '0192e2e0-0000-7000-8000-0000000000ff';

    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Maria'], 'client_submission_uuid' => $forged])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $submission = Submission::query()->findOrFail($this->scan->refresh()->submission_id);

    expect($submission->client_submission_uuid)->toBe($this->scan->id);
});

it('leaves the extraction alone: what the scan read is the proposal, and the reviewer\'s answers are the response', function (): void {
    $before = $this->scan->extraction;

    $this->actingAs($this->admin)
        ->from(ocrConfirmReviewUrl($this->form, $this->scan))
        ->post(ocrConfirmUrl($this->form, $this->scan), ['answers' => ['patient_name' => 'Corrected']])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $scan = $this->scan->refresh();

    expect($scan->extraction)->toEqual($before)
        ->and(Submission::query()->findOrFail($scan->submission_id)->answers->answers['patient_name'])->toBe('Corrected')
        ->and(FormField::query()->where('form_version_id', $this->form->current_published_version_id)->count())->toBe(2);
});
