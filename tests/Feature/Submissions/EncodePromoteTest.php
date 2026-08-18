<?php

declare(strict_types=1);

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\User;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I9b — Submit, once the encode channel sends a client_submission_uuid.
|--------------------------------------------------------------------------
| This file exists for ONE defect above all others. `SubmissionPipeline::submit()`'s Stage 2b resolves a
| same-uuid row and returns it as an idempotent no-op — FOR A DRAFT TOO. Send a uuid without cloning
| `GuestSubmissionController`'s draft branch and Submit flashes "Submission recorded." while the row stays
| `draft` forever: no exception, no log line, a 302 to the success page. Every assertion below that names a
| STATUS is guarding that shape.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->form = publishedInboxForm($this->tenant, $this->owner);
});

function encodeReenter(): void
{
    enterTenant(test()->tenant->id, test()->owner->id);
}

function saveEncodeDraft(Form $form, array $answers, string $uuid): void
{
    test()->actingAs(test()->owner)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/submissions/draft", [
            'answers' => $answers,
            'client_submission_uuid' => $uuid,
        ])
        ->assertSuccessful();
    encodeReenter();
}

function submitEncode(Form $form, array $answers, ?string $uuid = null)
{
    $body = ['answers' => $answers];
    if ($uuid !== null) {
        $body['client_submission_uuid'] = $uuid;
    }

    $response = test()->actingAs(test()->owner)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", $body);
    encodeReenter();

    return $response;
}

it('promotes the SAME row a draft was saved into, rather than no-opping on it', function (): void {
    // THE LINCHPIN. Asserting only "no error" and a success toast passes against the broken version, because
    // Stage 2b returns the draft with a 200 and the controller redirects happily. The STATUS is the assertion.
    $uuid = Uuid::uuid7()->toString();
    saveEncodeDraft($this->form, ['full_name' => 'Ada'], $uuid);

    $draftId = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail()->id;

    submitEncode($this->form, ['full_name' => 'Ada Lovelace'], $uuid)->assertRedirect();

    $row = Submission::findOrFail($draftId);

    expect($row->status)->toBe(SubmissionStatus::Submitted)
        ->and($row->id)->toBe($draftId)                 // the id is stable — links to it still resolve
        ->and($row->submitted_at)->not->toBeNull()
        ->and($row->draft_expires_at)->toBeNull()       // no longer reapable
        ->and(Submission::query()->count())->toBe(1);   // promoted, not duplicated

    // The final edits made between the last autosave and Submit are captured.
    $answers = SubmissionAnswer::query()->where('submission_id', $draftId)->firstOrFail();
    expect($answers->answers)->toBe(['full_name' => 'Ada Lovelace']);
});

it('fires exactly one SubmissionCreated for a promoted draft', function (): void {
    // The draft save must NOT announce, and the promote must announce once. A second event would double every
    // notification, webhook delivery and usage increment for one response.
    Event::fake([SubmissionCreated::class]);

    $uuid = Uuid::uuid7()->toString();
    saveEncodeDraft($this->form, ['full_name' => 'Ada'], $uuid);

    Event::assertNotDispatched(SubmissionCreated::class);

    submitEncode($this->form, ['full_name' => 'Ada'], $uuid);

    Event::assertDispatchedTimes(SubmissionCreated::class, 1);
});

it('still submits normally when no draft exists for the uuid', function (): void {
    // The uuid alone must not imply a draft. This is the ordinary create-page Submit, which now carries one.
    submitEncode($this->form, ['full_name' => 'Ada'], Uuid::uuid7()->toString())->assertRedirect();

    expect(Submission::query()->count())->toBe(1)
        ->and(Submission::query()->firstOrFail()->status)->toBe(SubmissionStatus::Submitted);
});

it('resolves a double-clicked Submit to one submission', function (): void {
    // Stage 2b went live on this channel the moment it started sending a uuid, and this is the payoff:
    // previously two identical POSTs produced two submissions.
    $uuid = Uuid::uuid7()->toString();

    submitEncode($this->form, ['full_name' => 'Ada'], $uuid)->assertRedirect();
    submitEncode($this->form, ['full_name' => 'Ada'], $uuid)->assertRedirect();

    expect(Submission::query()->count())->toBe(1);
});

it('keeps the answer row on the DRAFT\'S version when the form was republished after saving', function (): void {
    // ⚠️ THE VERSION-PIN NEGATIVE. `updateDraft()` writes `form_version_id` and `answers_schema_checksum`
    // onto the answer row FROM THE PAYLOAD. Pass the currently-published version on the draft branch and the
    // head row sits on v1 while its answers claim v2 — a mismatch nothing rejects, surfacing months later as
    // a wrong PDF or a wrong export column. Nothing else in the suite would catch it.
    $uuid = Uuid::uuid7()->toString();
    saveEncodeDraft($this->form, ['full_name' => 'Ada'], $uuid);

    $draft = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();
    $pinnedVersionId = $draft->form_version_id;

    // Republish: the form gains a new current version while the keyer is mid-transcription.
    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    encodeReenter();
    $form = Form::findOrFail($this->form->id);

    expect($form->current_published_version_id)->not->toBe($pinnedVersionId);

    submitEncode($form, ['full_name' => 'Ada'], $uuid);

    $row = Submission::findOrFail($draft->id);
    $answers = SubmissionAnswer::query()->where('submission_id', $draft->id)->firstOrFail();

    expect($row->form_version_id)->toBe($pinnedVersionId)
        ->and($answers->form_version_id)->toBe($pinnedVersionId);
});

it('leaves the draft resumable when Submit fails semantic validation', function (): void {
    // Stage 3 runs only at promote, and it runs OUTSIDE the transaction, so a refusal must leave the draft
    // exactly as it was rather than half-finalized.
    $uuid = Uuid::uuid7()->toString();
    saveEncodeDraft($this->form, ['full_name' => 'Ada'], $uuid);

    // `full_name` is required on the seeded inbox form, so an empty document fails Stage 3.
    submitEncode($this->form, [], $uuid)->assertRedirect();

    $row = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();

    expect($row->status)->toBe(SubmissionStatus::Draft)
        ->and($row->draft_expires_at)->not->toBeNull();
});

it('preserves the manual source through the promote', function (): void {
    $uuid = Uuid::uuid7()->toString();
    saveEncodeDraft($this->form, ['full_name' => 'Ada'], $uuid);

    submitEncode($this->form, ['full_name' => 'Ada'], $uuid);

    expect(Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail()->source)
        ->toBe(SubmissionSource::Manual);
});

it('projects the answer index exactly once for a promoted draft', function (): void {
    // The finalizer's projectors are INSERT-only, so a tail that ran twice — or a controller that both
    // submitted AND promoted — would duplicate index rows with no error at all. An earlier version of this
    // case asserted only the submission count and the version id, i.e. it never touched the index and could
    // not fail for the reason its name gives.
    $version = FormVersion::findOrFail($this->form->current_published_version_id);
    $indexed = FormField::query()->where('form_version_id', $version->id)->where('is_queryable', true)->count();

    $uuid = Uuid::uuid7()->toString();
    saveEncodeDraft($this->form, ['full_name' => 'Ada'], $uuid);

    // A draft projects NOTHING — the index is written by the Stage-4 tail, which only promotion runs.
    expect(SubmissionAnswerIndex::query()->count())->toBe(0);

    submitEncode($this->form, ['full_name' => 'Ada'], $uuid);

    $draft = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();

    expect(SubmissionAnswerIndex::query()->where('submission_id', $draft->id)->count())->toBe($indexed)
        ->and(SubmissionAnswerIndex::query()->count())->toBe($indexed);
});

it('promotes a draft the pipeline itself resolved, closing the autosave/submit commit race', function (): void {
    // ⚠️ R1 SURVIVES THE DRAFT BRANCH THROUGH A RACE, and this is the backstop for it. The controller's
    // `$draft` lookup and the pipeline's Stage 2b both read BEFORE a concurrent autosave commits, so both can
    // miss; the insert then trips the (tenant_id, client_submission_uuid) unique index and the 23505 catch
    // resolves the row — the freshly-committed DRAFT — returning it `created: false`. Simulated here by
    // submitting a uuid whose draft exists but which the controller's own scoped lookup cannot see, because
    // it belongs to a different user: the pipeline still resolves it by uuid alone.
    $other = User::factory()->create();
    enterTenant($this->tenant->id, $other->id);
    makeActiveMember($other, 'owner');
    encodeReenter();

    $uuid = Uuid::uuid7()->toString();
    $draft = Submission::factory()->forVersion(FormVersion::findOrFail($this->form->current_published_version_id))->create([
        'status' => SubmissionStatus::Draft,
        'source' => SubmissionSource::Manual,
        'respondent_user_id' => $other->id,
        'client_submission_uuid' => $uuid,
        'submitted_at' => null,
    ]);
    SubmissionAnswer::factory()->forSubmission($draft)->create(['answers' => ['full_name' => 'Ada']]);
    encodeReenter();

    submitEncode($this->form, ['full_name' => 'Ada'], $uuid)->assertRedirect();

    // The row must NOT be left as a draft carrying a success toast.
    expect(Submission::findOrFail($draft->id)->status)->toBe(SubmissionStatus::Submitted);
});
