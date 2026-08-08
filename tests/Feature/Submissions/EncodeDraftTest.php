<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I9b — the authenticated draft-autosave endpoint.
|--------------------------------------------------------------------------
| PRD Feature #7's last gap: a keyer transcribing a paper form had no way to stop. The endpoint reuses
| `SubmissionDraftService::saveDraft()`, the seam that service's own docblock has always advertised.
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

function draftUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/submissions/draft";
}

/**
 * Re-enter the test's own tenant context after an HTTP round trip.
 *
 * The request sets its RLS context from the resolved tenant and the test process does not inherit it back,
 * so a model query straight after a `postJson` runs under whatever GUC the request left — which is how a
 * `firstOrFail()` on a row that demonstrably exists throws `ModelNotFoundException`, and how a `save()`
 * raises `new row violates row-level security policy`. Failing CLOSED like that is the tenancy design
 * working; the test just has to say which tenant it is again.
 */
function reenter(): void
{
    enterTenant(test()->tenant->id, test()->owner->id);
}

function draftBody(array $answers, string $uuid, ?string $step = null): array
{
    return array_filter([
        'answers' => $answers,
        'client_submission_uuid' => $uuid,
        'draft_current_step' => $step,
    ], static fn ($v): bool => $v !== null);
}

it('creates a manual draft and returns its progress', function (): void {
    $uuid = Uuid::uuid7()->toString();

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], $uuid))
        ->assertCreated()
        ->assertJsonPath('data.completeness_percent', fn ($v): bool => is_int($v));

    reenter();
    $draft = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();

    expect($draft->status)->toBe(SubmissionStatus::Draft)
        ->and($draft->source)->toBe(SubmissionSource::Manual)
        ->and($draft->respondent_user_id)->toBe($this->owner->id)
        ->and($draft->draft_expires_at)->not->toBeNull()
        ->and($draft->last_saved_at)->not->toBeNull();
});

it('overwrites one row across many autosave ticks instead of creating one per tick', function (): void {
    // ⚠️ THE TEST THIS ENDPOINT EXISTS TO SURVIVE. `saveDraft()` consults `findByClientUuid()` only when the
    // uuid is non-null; make the field nullable "for convenience" and every debounce tick takes the
    // `createDraft()` branch, so an hour of typing leaves a hundred draft rows each with a 30-day TTL.
    $uuid = Uuid::uuid7()->toString();

    foreach (['A', 'B', 'C'] as $name) {
        $this->actingAs($this->owner)
            ->postJson(draftUrl($this->form), draftBody(['full_name' => $name], $uuid))
            ->assertSuccessful();
    }

    reenter();
    expect(Submission::query()->where('status', SubmissionStatus::Draft)->count())->toBe(1);

    $draft = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();
    $answers = SubmissionAnswer::query()->where('submission_id', $draft->id)->firstOrFail();

    // The LAST payload wins — the draft channel overwrites in place rather than 409ing on changed content.
    expect($answers->answers)->toBe(['full_name' => 'C']);
});

it('refuses a draft save with no client_submission_uuid', function (): void {
    // The other half of the same guard: `required` is what makes the N-row bug unrepresentable rather than
    // merely untested, so a future "make it nullable like the guest one" is a red test.
    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), ['answers' => ['full_name' => 'Ada']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('client_submission_uuid');
});

it('is available on the free plan, because losing transcription is data loss', function (): void {
    // docs/ux/form-filling-ux-flow.md pins manual encoding's autosave as "the one channel never gated behind
    // a paid plan". This reddens if someone copies the guest route's `feature:save_and_resume` middleware.
    assignPlanTier(PlanTier::Free);

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], Uuid::uuid7()->toString()))
        ->assertCreated();
});

it('ignores the per-form save_and_resume flag, which is a respondent affordance', function (): void {
    // `forms.save_and_resume` is the author's decision about whether RESPONDENTS may park a response and
    // return by emailed link. Whether staff may avoid retyping is not the same question. Reddens if someone
    // copies `GuestDraftController`'s `$form->save_and_resume` check.
    $this->form->forceFill(['save_and_resume' => false])->save();

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], Uuid::uuid7()->toString()))
        ->assertCreated();
});

it('refuses a member without submissions.create', function (): void {
    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    enterTenant($this->tenant->id, $this->owner->id);

    $this->actingAs($viewer)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], Uuid::uuid7()->toString()))
        ->assertForbidden();
});

it('refuses a form editor with no grant on this form', function (): void {
    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    enterTenant($this->tenant->id, $this->owner->id);

    // No `makeCollaborator()` — the `.own` scope has nothing to match.
    $this->actingAs($editor)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], Uuid::uuid7()->toString()))
        ->assertForbidden();

    // The control: granted, the same request succeeds. Without it this passes against a route that 403s
    // everyone.
    reenter();
    makeCollaborator($this->form, $editor, ResourceCapacity::Editor);

    $this->actingAs($editor)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], Uuid::uuid7()->toString()))
        ->assertCreated();
});

it('returns a typed 422 envelope for a structurally invalid answer, never a 500', function (): void {
    // Stage 1 still runs on the draft path (Stage 3 does not), so an unknown key is refused. The composable
    // keys its inline error off this envelope, so its shape is a contract.
    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['no_such_field' => 'x'], Uuid::uuid7()->toString()))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'submission_invalid');
});

it('stops with a typed 409 once the draft has been submitted from another tab', function (): void {
    // The two-tab race: tab A submitted, tab B's next autosave tick arrives. `updateDraft()`'s lock re-assert
    // refuses, and the composable must stop permanently rather than retry against a row that will never be a
    // draft again — so the code, not just the status, is asserted.
    $uuid = Uuid::uuid7()->toString();

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], $uuid))
        ->assertCreated();

    reenter();
    Submission::query()->where('client_submission_uuid', $uuid)
        ->update(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada B'], $uuid))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'draft_already_finalized');
});

it('refuses a NEW draft on a closed form but keeps saving an existing one', function (): void {
    // H12a's grace window, reached through this channel. `saveDraft()` runs `assertCanStart()` on the CREATE
    // branch only, so a keyer who started before the form closed is never stranded mid-transcription.
    $uuid = Uuid::uuid7()->toString();

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], $uuid))
        ->assertCreated();

    reenter();
    $this->form->forceFill(['closes_at' => now()->subDay()])->save();

    // The existing draft still saves...
    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada B'], $uuid))
        ->assertSuccessful();

    // ...but a brand-new one is refused, with the typed code the client renders as a banner.
    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Bob'], Uuid::uuid7()->toString()))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'form_closed');
});

it('never adopts another tenant\'s draft with the same uuid', function (): void {
    // The partial-unique index is per-tenant and RLS scopes the lookup, so a colliding uuid must create a
    // NEW local row rather than resolving to — or worse, overwriting — the other tenant's draft.
    $uuid = Uuid::uuid7()->toString();

    $other = inboxTenant('northwind');
    $otherOwner = User::factory()->create();
    enterTenant($other->id, $otherOwner->id);
    makeActiveMember($otherOwner, 'owner');
    $otherForm = publishedInboxForm($other, $otherOwner);

    $this->actingAs($otherOwner)
        ->postJson("http://northwind.meridian.test/forms/{$otherForm->id}/submissions/draft", draftBody(['full_name' => 'Theirs'], $uuid))
        ->assertCreated();

    enterTenant($this->tenant->id, $this->owner->id);

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ours'], $uuid))
        ->assertCreated();

    enterTenant($this->tenant->id, $this->owner->id);
    $mine = Submission::query()->where('client_submission_uuid', $uuid)->get();

    expect($mine)->toHaveCount(1)
        ->and($mine->first()?->form_id)->toBe($this->form->id);
});

it('never writes into another FORM\'s draft, even with a valid uuid', function (): void {
    // ⚠️ THE CROSS-FORM NEGATIVE. The route gate authorizes the bound {form}; the uuid arrives in the body and
    // is chosen by the caller. Resolved on uuid alone — as both draft lookups originally were — a member
    // authorized on form A writes into form B's draft, on a form they may hold no grant on at all. RLS bounds
    // this to the tenant and no further, so it is the application's job.
    $other = publishedInboxForm($this->tenant, $this->owner, 'Other');
    $uuid = Uuid::uuid7()->toString();

    // A draft on the OTHER form.
    $this->actingAs($this->owner)
        ->postJson(draftUrl($other), draftBody(['full_name' => 'Theirs'], $uuid))
        ->assertCreated();
    reenter();
    $otherDraft = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();

    // The same uuid, posted at THIS form, is REFUSED — not silently written into the other form's draft,
    // and not inserted as a second row either (the unique index is per TENANT, so that would 500).
    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Mine'], $uuid))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_conflict');
    reenter();

    $untouched = SubmissionAnswer::query()->where('submission_id', $otherDraft->id)->firstOrFail();
    expect($untouched->answers)->toBe(['full_name' => 'Theirs'])
        ->and(Submission::query()->where('form_id', $this->form->id)->count())->toBe(0)
        ->and(Submission::query()->where('form_id', $other->id)->count())->toBe(1);
});

it('never writes into another USER\'s draft on the same form', function (): void {
    // The second half of the scoping rule. A draft belongs to the person keying it; two staff transcribing
    // the same form each own theirs.
    $colleague = User::factory()->create();
    enterTenant($this->tenant->id, $colleague->id);
    makeActiveMember($colleague, 'owner');
    reenter();

    $uuid = Uuid::uuid7()->toString();

    $this->actingAs($colleague)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Colleague'], $uuid))
        ->assertCreated();
    reenter();
    $theirs = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Mine'], $uuid))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_conflict');
    reenter();

    $untouched = SubmissionAnswer::query()->where('submission_id', $theirs->id)->firstOrFail();
    expect($untouched->answers)->toBe(['full_name' => 'Colleague'])
        ->and($theirs->respondent_user_id)->toBe($colleague->id)
        ->and(Submission::query()->count())->toBe(1);
});

it('stops the autosave with form_updated once the draft\'s version is superseded', function (): void {
    // ⚠️ THE VERSION-PIN NEGATIVE FOR THE *DRAFT* ENDPOINT. Resolving the current published version here
    // (as the first draft of this controller did) writes a head row on v1 with an answer row claiming v2, and
    // normalises against a schema the draft was never keyed against — so a renamed field turns every
    // remaining tick into a 422 the keyer is told is retryable. A typed 409 is what lets the composable stop.
    $uuid = Uuid::uuid7()->toString();

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], $uuid))
        ->assertCreated();
    reenter();
    $pinned = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail()->form_version_id;

    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    reenter();

    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada B'], $uuid))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'form_updated');

    reenter();
    $row = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();
    $answers = SubmissionAnswer::query()->where('submission_id', $row->id)->firstOrFail();

    // Refused, not half-written: both halves still name the pinned version.
    expect($row->form_version_id)->toBe($pinned)
        ->and($answers->form_version_id)->toBe($pinned);
});

it('returns the draft expiry, which is the whole mitigation for keeping stamp-once', function (): void {
    // I9b deliberately did NOT restamp `draft_expires_at` on save (an existing tested contract on the guest
    // path). The compensating control is that the date is SURFACED — so if this stops being returned, the
    // reason for not restamping stops holding.
    $this->actingAs($this->owner)
        ->postJson(draftUrl($this->form), draftBody(['full_name' => 'Ada'], Uuid::uuid7()->toString()))
        ->assertCreated()
        ->assertJsonPath('data.expires_at', fn ($v): bool => is_string($v) && $v !== '');
});
