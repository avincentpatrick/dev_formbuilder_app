<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResumeLinkNotification;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\AnswersContentChecksum;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H9b — the guest save-and-resume surface end-to-end.
|--------------------------------------------------------------------------
| POST /api/v1/public/f/{shareToken}/draft upserts a durable server draft (SubmissionDraftService::saveDraft)
| and returns a resume token scoped to its submissions.id; GET /api/v1/public/drafts/{resumeToken} restores it
| (pre-RLS EstablishGuestDraftContext); the existing submit route finalizes a saved draft via promote(), never
| submit(). Gated on feature:save_and_resume — inert with no plan seeded, blocking only a resolved-and-denied
| plan. Own helpers (distinct names from GuestRuntimeTest) so this file runs standalone.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A tenant reachable at {slug}.meridian.test. */
function draftTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

/**
 * A guest-enabled published form (required full_name + optional age) with the per-form save-and-resume opt-in
 * ON (Increment H10 gates the draft channel on it). Requires enterTenant already called.
 */
function draftForm(Tenant $tenant, User $owner, string $slug = 'intake'): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($form->draftVersion, $owner, 'age', FieldType::Integer, 1);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update(['public_slug' => $slug, 'allow_guest_submissions' => true, 'save_and_resume' => true]);

    return $form->refresh();
}

function draftShareToken(Form $form): string
{
    return app(GuestShareTokenService::class)->mint(
        $form->tenant_id, $form->id, (string) $form->current_published_version_id,
    )->token;
}

/** Build the {tenant, owner, form, token} fixture, with context left on the tenant. */
function draftFixture(): object
{
    $tenant = draftTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = draftForm($tenant, $owner);

    return (object) ['tenant' => $tenant, 'owner' => $owner, 'form' => $form, 'token' => draftShareToken($form)];
}

// ── Draft upsert (POST /api/v1/public/f/{shareToken}/draft) ───────────────────────────────────

it('creates a draft and returns a resume token scoped to its submission id', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $response = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'], // incomplete (no full_name) is fine for a draft
        'client_submission_uuid' => $uuid,
    ])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'completeness_percent', 'resume_token', 'resume_url', 'expires_at']]);

    enterTenant($f->tenant->id);
    $draft = Submission::query()->firstOrFail();
    expect($draft->status)->toBe(SubmissionStatus::Draft)
        ->and($draft->source)->toBe(SubmissionSource::Guest)
        ->and($draft->draft_expires_at)->not->toBeNull()
        ->and($draft->client_submission_uuid)->toBe($uuid)
        ->and($response->json('data.id'))->toBe($draft->id)
        ->and($response->json('data.completeness_percent'))->toBe(50); // 1 of 2 answerable (full_name/age)

    // The resume token round-trips to this draft.
    $resume = app(GuestShareTokenService::class)->verifyResume($response->json('data.resume_token'));
    expect($resume->submissionId)->toBe($draft->id);
});

it('overwrites the same draft in place on a subsequent save (409 suspended)', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();
    $url = "http://acme.meridian.test/api/v1/public/f/{$f->token}/draft";

    $first = $this->postJson($url, ['answers' => ['age' => '30'], 'client_submission_uuid' => $uuid])->assertCreated();
    // The CONTENT 409 stays suspended: materially different answers under one uuid still overwrite in place.
    // The save carries P3a's baseline, which is what tells this apart from a second DEVICE writing — that
    // case is the sibling test below, and it is refused.
    $second = $this->postJson($url, [
        'answers' => ['age' => '31', 'full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $first->json('data.content_checksum'),
    ])->assertOk(); // 200, not 201 — an in-place overwrite

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.completeness_percent'))->toBe(100);

    enterTenant($f->tenant->id);
    expect(Submission::query()->count())->toBe(1);
});

it('emails the resume link on finish_later when a contact email is present', function (): void {
    Notification::fake();
    $f = draftFixture();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'],
        'client_submission_uuid' => Uuid::uuid7()->toString(),
        'guest_contact_email' => 'ada@example.test',
        'finish_later' => true,
    ])->assertCreated();

    Notification::assertSentOnDemand(
        ResumeLinkNotification::class,
        fn (ResumeLinkNotification $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ada@example.test'
            && str_contains($n->resumeUrl, '/f/resume/'),
    );
});

it('does not email when finish_later is absent', function (): void {
    Notification::fake();
    $f = draftFixture();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'],
        'client_submission_uuid' => Uuid::uuid7()->toString(),
        'guest_contact_email' => 'ada@example.test',
    ])->assertCreated();

    Notification::assertNothingSent();
});

// ── Feature gating (save_and_resume, Starter+) ────────────────────────────────────────────────

it('402s the draft-save for a plan that lacks save_and_resume, but never the final submit', function (): void {
    $f = draftFixture();
    assignPlanTier(PlanTier::Free); // Free lacks save_and_resume

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'feature_not_available');

    // never-block: the final submit is ungated even on Free.
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/submissions", [
        'answers' => ['full_name' => 'Ada', 'age' => '30'],
    ])->assertCreated();
});

it('allows the draft-save for a plan that grants save_and_resume', function (): void {
    $f = draftFixture();
    assignPlanTier(PlanTier::Starter);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertCreated();
});

// ── Resume-read (GET /api/v1/public/drafts/{resumeToken}) ─────────────────────────────────────

it('restores a draft from its resume token with a fresh share token', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $save = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30', 'full_name' => 'Ada'], 'client_submission_uuid' => $uuid,
    ])->assertCreated();
    $resumeToken = $save->json('data.resume_token');

    $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$resumeToken}")
        ->assertOk()
        ->assertJsonPath('data.id', $save->json('data.id'))
        ->assertJsonPath('data.client_submission_uuid', $uuid)
        ->assertJsonPath('data.answers.full_name', 'Ada')
        ->assertJsonPath('data.completeness_percent', 100)
        ->assertJsonStructure(['data' => ['id', 'answers', 'form_version_id', 'share_token', 'share_token_expires_at']]);
});

it('404s a resume token whose draft has been finalized', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $save = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30', 'full_name' => 'Ada'], 'client_submission_uuid' => $uuid,
    ])->assertCreated();
    $resumeToken = $save->json('data.resume_token');

    // Finalize via the submit route (promotes the draft).
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/submissions", [
        'answers' => ['full_name' => 'Ada', 'age' => '30'], 'client_submission_uuid' => $uuid,
    ])->assertCreated();

    $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$resumeToken}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'draft_not_found');
});

it('cannot read another tenant\'s draft even with a validly-signed resume token (RLS)', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();
    $save = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'], 'client_submission_uuid' => $uuid,
    ])->assertCreated();
    $draftId = $save->json('data.id');

    // Forge a resume token that points at tenant A's draft but claims a DIFFERENT tenant.
    $other = draftTenant('beta');
    $forged = app(GuestShareTokenService::class)->mintResume(
        $other->id, $f->form->id, (string) $f->form->current_published_version_id, $draftId,
    )->token;

    $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$forged}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'draft_not_found');
});

/*
|--------------------------------------------------------------------------
| Increment M30 — one exhausted resume link must not 429 everybody else's.
|--------------------------------------------------------------------------
| `throttle:guest` guards two route SHAPES: the share-token group ({shareToken}) and this route
| ({resumeToken}). Until M30 the limiter keyed its per-token bucket on `route('shareToken')` alone, which
| this route does not declare — so the key hashed the empty string and every draft-resume request in the
| deployment, across every tenant, shared ONE 30/min bucket.
|
| ⚠️ THE ASSERTION THAT MATTERS IS THE THIRD REQUEST, NOT THE 429. A test that only exhausted one token and
| asserted 429 passes on the BROKEN code too — the global bucket 429s just as readily, and rather more
| eagerly. The discriminating observation is that a SECOND, unrelated resume token is still served after the
| first one's budget is gone. The structural half of this fix (`RateLimiterBindingTest`) inspects the key
| directly; this case is here because a key is an implementation and a refused visitor is the consequence.
*/
it('meters each resume token separately, so one exhausted link does not refuse another', function (): void {
    // Per-token budget of 1, per-IP budget high enough to stay out of the way — the file's own idiom from
    // `GuestRuntimeTest`'s rate-limit cases. Both drafts are minted before the budget is narrowed, since the
    // save channel shares this limiter and would otherwise spend the very bucket under test.
    $f = draftFixture();

    $resumeA = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30', 'full_name' => 'Ada'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertCreated()->json('data.resume_token');

    $resumeB = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '41', 'full_name' => 'Grace'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertCreated()->json('data.resume_token');

    expect($resumeA)->not->toBe($resumeB);

    config(['guest.rate_limit.submit_per_token' => 1, 'guest.rate_limit.submit_per_ip' => 99]);

    $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$resumeA}")->assertOk();
    $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$resumeA}")->assertStatus(429);

    // ⛔ THE WHOLE CASE. On the pre-M30 key this is a 429: A's two requests emptied the one bucket every
    // resume link shared, so B — a different draft, and in production a different tenant — is refused by
    // somebody else's traffic.
    $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$resumeB}")->assertOk();
});

// ── Draft-aware finalize (POST .../submissions promotes an existing draft) ─────────────────────

it('finalizes a saved draft in place via promote(), metering it exactly once', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $save = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'], 'client_submission_uuid' => $uuid,
    ])->assertCreated();
    $draftId = $save->json('data.id');

    Event::fake([SubmissionCreated::class]);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/submissions", [
        'answers' => ['full_name' => 'Ada', 'age' => '30'], 'client_submission_uuid' => $uuid,
    ])
        ->assertCreated()
        ->assertJsonPath('data.id', $draftId)     // the SAME row, flipped in place
        ->assertJsonPath('data.status', 'submitted');

    Event::assertDispatchedTimes(SubmissionCreated::class, 1); // metered only on promotion

    enterTenant($f->tenant->id);
    expect(Submission::query()->count())->toBe(1)
        ->and(Submission::query()->firstOrFail()->status)->toBe(SubmissionStatus::Submitted);
});

it('409s a draft save whose uuid was already finalized (draft_already_finalized)', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30', 'full_name' => 'Ada'], 'client_submission_uuid' => $uuid,
    ])->assertCreated();

    // Finalize it.
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/submissions", [
        'answers' => ['full_name' => 'Ada', 'age' => '30'], 'client_submission_uuid' => $uuid,
    ])->assertCreated();

    // A late draft-save for the same (now finalized) uuid → the distinct H9b conflict code.
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '31'], 'client_submission_uuid' => $uuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'draft_already_finalized');
});

// ── Web shell entry (GET /f/resume/{resumeToken} on the subdomain) ────────────────────────────

it('opens the guest SPA shell from a resume link, embedding the resume token', function (): void {
    $f = draftFixture();
    $resumeToken = app(GuestShareTokenService::class)->mintResume(
        $f->tenant->id, $f->form->id, (string) $f->form->current_published_version_id, Uuid::uuid7()->toString(),
    )->token;

    $html = $this->withoutVite()
        ->get("http://acme.meridian.test/f/resume/{$resumeToken}")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<html')
        ->and($html)->toContain('data-resume-token="'.$resumeToken.'"')
        ->and($html)->toContain('data-share-token='); // a fresh share token is embedded so the SPA boots
});

it('404s a resume shell link with a bad token (non-disclosure)', function (): void {
    draftFixture();

    $this->get('http://acme.meridian.test/f/resume/v1.garbage.sig')->assertNotFound();
});

// ── H10 — per-form opt-in gate ────────────────────────────────────────────────────────────────

it('403s the draft-save when the form has save_and_resume disabled (save_resume_disabled)', function (): void {
    $f = draftFixture();
    $f->form->forceFill(['save_and_resume' => false])->save();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'save_resume_disabled');
});

// ── H10 — resume cursor (draft_current_step) + reconciliation fields on the resume-read ─────────

it('persists the current step on save and returns it (with last_saved_at + locale) on resume', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $save = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'],
        'client_submission_uuid' => $uuid,
        'draft_current_step' => 'section-2',
        'locale' => 'en',
    ])->assertCreated();

    $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$save->json('data.resume_token')}")
        ->assertOk()
        ->assertJsonPath('data.draft_current_step', 'section-2')
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonStructure(['data' => ['last_saved_at', 'draft_current_step', 'locale']]);

    enterTenant($f->tenant->id);
    expect(Submission::query()->firstOrFail()->draft_current_step)->toBe('section-2');
});

// ── H10 — tenant-configurable draft TTL (stamped once at creation) ─────────────────────────────

it('stamps the draft expiry from the tenant draft_ttl_days setting, defaulting to 30 days', function (): void {
    $f = draftFixture();
    $f->tenant->forceFill(['draft_ttl_days' => 7])->save();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertCreated();

    enterTenant($f->tenant->id);
    expect(Submission::query()->firstOrFail()->draft_expires_at->toDateString())
        ->toBe(now()->addDays(7)->toDateString());
});

it('does not restamp the draft expiry on a later save (stamp-once)', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();
    $url = "http://acme.meridian.test/api/v1/public/f/{$f->token}/draft";

    $first = $this->postJson($url, ['answers' => ['age' => '30'], 'client_submission_uuid' => $uuid])->assertCreated();

    enterTenant($f->tenant->id);
    $originalExpiry = Submission::query()->firstOrFail()->draft_expires_at->toIso8601String();

    // Two days later, a second save must NOT move the (creation-time) expiry. It carries the baseline P3a
    // added, exactly as the SPA does — without it this is a lost-update refusal rather than a save.
    $this->travel(2)->days();
    TenantContext::flush();
    $this->postJson($url, [
        'answers' => ['age' => '31', 'full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $first->json('data.content_checksum'),
    ])->assertOk();

    enterTenant($f->tenant->id);
    expect(Submission::query()->firstOrFail()->draft_expires_at->toIso8601String())->toBe($originalExpiry);
});

/*
|--------------------------------------------------------------------------
| Increment H21b — branch drift under H12a's grace window (Doc #27 §5.4).
|--------------------------------------------------------------------------
| §5.4's finding is that branching adds NOTHING to the close and expiry policies, and that this is itself
| worth recording: the grace window promotes a pre-close draft without re-examining the respondent's path,
| and H10's reconciliation handles a VERSION change on resume. Neither had ever been exercised against a
| GRAPH change. §9 therefore assigns H21b a test rather than a code change — these are it. A test that only
| asserted "the request did not 500" would be vacuous, so both pin the pruning outcome.
*/

/** A guest form whose second section is gated on a first-section answer, with save-and-resume on. */
function branchingDraftForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Branching intake');
    $draft = $form->draftVersion;

    $s1 = FormSection::create(['form_version_id' => $draft->id, 'key' => 'basics', 'label' => 'Basics', 'sequence' => 1]);
    $s2 = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'details', 'label' => 'Details', 'sequence' => 2,
        'relevant_expression' => '${gate} = \'yes\'',
    ]);
    addFormField($draft, $owner, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
    addFormField($draft, $owner, 'detail', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);

    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update([
        'public_slug' => 'branching', 'allow_guest_submissions' => true, 'save_and_resume' => true,
    ]);

    return $form->refresh();
}

it('promotes a pre-close draft under the grace window and prunes against the path its own answers take', function (): void {
    $tenant = draftTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = branchingDraftForm($tenant, $owner);
    $token = draftShareToken($form);
    $uuid = Uuid::uuid7()->toString();

    // The respondent is on the branch: `gate = yes` makes the Details section relevant, and they answer it.
    TenantContext::flush();
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/draft", [
        'answers' => ['gate' => 'yes', 'detail' => 'on the branch'],
        'client_submission_uuid' => $uuid,
        'draft_current_step' => 'details',
    ])->assertCreated();

    // The form closes AFTER the draft was started — H12a's grace window is exactly this case.
    // The close instant must fall AFTER the draft was created — that is the whole grace-window predicate
    // (`assertCanPromote` refuses a draft created at/after close as one that should never have started).
    $this->travel(1)->hours();
    enterTenant($tenant->id, $owner->id);
    $form->update(['closes_at' => now()->subMinute()]);

    TenantContext::flush();
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['gate' => 'yes', 'detail' => 'on the branch'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();

    enterTenant($tenant->id);
    $submission = Submission::query()->firstOrFail();
    expect($submission->status)->toBe(SubmissionStatus::Submitted);
    // The taken path is honoured: the gated section was relevant, so its answer survives the promote-time prune.
    expect($submission->answers()->firstOrFail()->answers)->toMatchArray(['gate' => 'yes', 'detail' => 'on the branch']);
});

it('prunes the off-branch answer at promote time, after close, exactly as it would before', function (): void {
    $tenant = draftTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = branchingDraftForm($tenant, $owner);
    $token = draftShareToken($form);
    $uuid = Uuid::uuid7()->toString();

    // The respondent answered the branch, then went back and closed the gate — the retained-but-irrelevant
    // shape §4.4 describes. The stored draft keeps `detail`; the promote must drop it.
    TenantContext::flush();
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/draft", [
        'answers' => ['gate' => 'no', 'detail' => 'typed before the branch closed'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();

    // The close instant must fall AFTER the draft was created — that is the whole grace-window predicate
    // (`assertCanPromote` refuses a draft created at/after close as one that should never have started).
    $this->travel(1)->hours();
    enterTenant($tenant->id, $owner->id);
    $form->update(['closes_at' => now()->subMinute()]);

    TenantContext::flush();
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['gate' => 'no', 'detail' => 'typed before the branch closed'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();

    enterTenant($tenant->id);
    $answers = Submission::query()->firstOrFail()->answers()->firstOrFail()->answers;
    expect($answers)->toHaveKey('gate')->and($answers)->not->toHaveKey('detail');
});

it('refuses loudly, rather than promoting against a different graph, when the branch is republished', function (): void {
    $tenant = draftTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = branchingDraftForm($tenant, $owner);
    $token = draftShareToken($form);
    $uuid = Uuid::uuid7()->toString();

    TenantContext::flush();
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/draft", [
        'answers' => ['gate' => 'yes', 'detail' => 'on the branch'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();

    // The author moves the branch and republishes. The draft stays pinned to the version it was captured
    // against, and `promote()` re-asserts that version is still published — so this is the ONE drift the
    // server catches by itself. §5.4's offline-replay hole is the twin it could not see, and H21b closes
    // that one client-side.
    enterTenant($tenant->id, $owner->id);
    $newDraft = $form->refresh()->draftVersion;
    $newDraft->sections()->where('key', 'details')->update(['relevant_expression' => '${gate} = \'never\'']);
    app(PublishService::class)->publish($form->refresh(), $owner);

    TenantContext::flush();
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['gate' => 'yes', 'detail' => 'on the branch'],
        'client_submission_uuid' => $uuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_version_superseded');

    enterTenant($tenant->id);
    expect(Submission::query()->firstOrFail()->status)->toBe(SubmissionStatus::Draft);
});

/*
|--------------------------------------------------------------------------
| Increment P3a — the lost-update guard over HTTP. The resume link is what makes this reachable: it hands a
| SECOND device the same client_submission_uuid, so one draft acquires two writers.
|--------------------------------------------------------------------------
*/

it('HEADLINE: refuses the second device over HTTP with 409 draft_conflict and keeps the first device work', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();
    $url = "http://acme.meridian.test/api/v1/public/f/{$f->token}/draft";

    // Phone saves, then emails itself the resume link.
    $seed = $this->postJson($url, ['answers' => ['age' => '30'], 'client_submission_uuid' => $uuid])->assertCreated();
    $sharedBase = $seed->json('data.content_checksum');
    expect($sharedBase)->not->toBeNull();

    // Tablet opens the link and is handed the SAME base the phone holds.
    $resumed = $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$seed->json('data.resume_token')}")
        ->assertOk();
    expect($resumed->json('data.content_checksum'))->toBe($sharedBase)
        ->and($resumed->json('data.client_submission_uuid'))->toBe($uuid); // the same logical submission

    // Tablet saves first and moves the stored state on.
    $this->postJson($url, [
        'answers' => ['age' => '30', 'full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $sharedBase,
    ])->assertOk();

    // The phone, still holding the pre-tablet base, saves. Before P3a this silently erased 'Ada'.
    $this->postJson($url, [
        'answers' => ['age' => '31'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $sharedBase,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'draft_conflict');

    // The tablet's answers survive and the phone wrote nothing.
    enterTenant($f->tenant->id);
    $answers = SubmissionAnswer::query()->where('submission_id', $seed->json('data.id'))->value('answers');
    expect(data_get($answers, 'full_name'))->toBe('Ada')
        ->and(data_get($answers, 'age'))->toBe('30')
        ->and(Submission::query()->count())->toBe(1);
});

it('lets the refused device recover by re-reading the draft and saving from the fresh base', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();
    $url = "http://acme.meridian.test/api/v1/public/f/{$f->token}/draft";

    $seed = $this->postJson($url, ['answers' => ['age' => '30'], 'client_submission_uuid' => $uuid])->assertCreated();
    $resumeToken = $seed->json('data.resume_token');
    $stale = $seed->json('data.content_checksum');

    // Another device writes.
    $this->postJson($url, [
        'answers' => ['age' => '30', 'full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $stale,
    ])->assertOk();

    // The refusal is not a dead end — the message says Reload, and reloading is what actually works.
    $this->postJson($url, ['answers' => ['age' => '31'], 'client_submission_uuid' => $uuid, 'base_content_checksum' => $stale])
        ->assertStatus(409);

    $fresh = $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$resumeToken}")->assertOk();
    $this->postJson($url, [
        'answers' => ['age' => '31', 'full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $fresh->json('data.content_checksum'),
    ])->assertOk();

    enterTenant($f->tenant->id);
    $answers = SubmissionAnswer::query()->where('submission_id', $seed->json('data.id'))->value('answers');
    expect(data_get($answers, 'age'))->toBe('31')->and(data_get($answers, 'full_name'))->toBe('Ada');
});

it('refuses a client that stops sending the baseline rather than silently dropping the guard', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();
    $url = "http://acme.meridian.test/api/v1/public/f/{$f->token}/draft";

    $this->postJson($url, ['answers' => ['age' => '30'], 'client_submission_uuid' => $uuid])->assertCreated();

    // The fail-CLOSED direction over HTTP. A stale client is refused; it is not quietly unguarded.
    $this->postJson($url, ['answers' => ['age' => '31'], 'client_submission_uuid' => $uuid])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'draft_conflict');
});

it('rejects a malformed baseline at validation rather than treating it as a conflict', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => 'not-a-sha256',
    ])->assertStatus(422);
});

it('HEADLINE: refuses a stale device SUBMITTING over a draft another device advanced', function (): void {
    // ⚠️ THE SHARPEST INSTANCE, FOUND BY THE ADVERSARIAL PASS RATHER THAN BY THE ROW. A submit against an
    // existing draft SAVES first and then PROMOTES, so before P3a a stale device did not merely overwrite the
    // other device's answers -- it finalized the row with its own stale copy, and no later save could undo it.
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();
    $draftUrl = "http://acme.meridian.test/api/v1/public/f/{$f->token}/draft";

    $seed = $this->postJson($draftUrl, [
        'answers' => ['age' => '30'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();
    $sharedBase = $seed->json('data.content_checksum');

    // The tablet saves real work into the draft.
    $this->postJson($draftUrl, [
        'answers' => ['age' => '30', 'full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $sharedBase,
    ])->assertOk();

    // The phone, still on the pre-tablet base, presses Submit.
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/submissions", [
        'answers' => ['age' => '31', 'full_name' => 'Bob'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $sharedBase,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'draft_conflict');

    // Nothing was finalized and the tablet's answers are intact.
    enterTenant($f->tenant->id);
    $row = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();
    expect($row->status)->toBe(SubmissionStatus::Draft);
    $answers = SubmissionAnswer::query()->where('submission_id', $row->id)->value('answers');
    expect(data_get($answers, 'full_name'))->toBe('Ada');
});

it('still replays an offline submit that makes no baseline claim, because stranding it breaks the promise', function (): void {
    // ⚠️ THE DELIBERATE ASYMMETRY WITH THE DRAFT CHANNEL. An outbox row serialized by an earlier build has no
    // baseline, and refusing it would strand a real, finished response that nothing can resubmit. The draft
    // channel fails CLOSED because a refused tick costs a retype; this one fails OPEN because a refused
    // replay costs the response.
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/submissions", [
        'answers' => ['age' => '30', 'full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
    ])->assertSuccessful();

    enterTenant($f->tenant->id);
    expect(Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail()->status)
        ->toBe(SubmissionStatus::Submitted);
});

it('promotes a submit that carries the CURRENT baseline, so the token is used and not merely present', function (): void {
    // ⚠️ THIS TEST EXISTS BECAUSE A MUTATION SURVIVED. Nulling the token in the controller still produced a
    // 409 on the stale-submit case above -- `claimsBaseline()` reads the real request value, so the guard
    // fired for the WRONG reason and the assertion could not tell. Only a submit that must SUCCEED on a
    // correct baseline discriminates "the token is compared" from "the token is merely non-null".
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();
    $draftUrl = "http://acme.meridian.test/api/v1/public/f/{$f->token}/draft";

    $seed = $this->postJson($draftUrl, ['answers' => ['age' => '30'], 'client_submission_uuid' => $uuid])
        ->assertCreated();

    // One more save, so the baseline has genuinely MOVED and a stale value would be caught.
    $moved = $this->postJson($draftUrl, [
        'answers' => ['age' => '31'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $seed->json('data.content_checksum'),
    ])->assertOk();

    expect($moved->json('data.content_checksum'))->not->toBe($seed->json('data.content_checksum'));

    // The same device submits with the CURRENT base and is promoted.
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/submissions", [
        'answers' => ['age' => '31', 'full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
        'base_content_checksum' => $moved->json('data.content_checksum'),
    ])->assertSuccessful();

    enterTenant($f->tenant->id);
    $row = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();
    expect($row->status)->toBe(SubmissionStatus::Submitted);
    expect(data_get(SubmissionAnswer::query()->where('submission_id', $row->id)->value('answers'), 'full_name'))
        ->toBe('Ada');
});

/*
|--------------------------------------------------------------------------
| Increment M11 — the draft channel refuses a uuid spent on another form.
|--------------------------------------------------------------------------
| This route is the one the M11 backlog row does NOT name, and it carried the identical defect: the scoped
| resolve missed, createDraft() inserted into the tenant-wide `(tenant_id, client_submission_uuid)` index,
| the 23505 could not be classified, the reference-retry budget was burned on an insert that could only fail
| again, and the QueryException escaped as a 500 — on a route anyone on the internet can POST to.
*/

it('409s (not 500) a guest draft save naming a uuid spent on ANOTHER form (M11)', function (): void {
    $f = draftFixture();
    $theirs = draftForm($f->tenant, $f->owner, 'other');
    $uuid = Uuid::uuid7()->toString();

    // Form B claims the uuid first, on its own channel.
    $this->postJson('http://acme.meridian.test/api/v1/public/f/'.draftShareToken($theirs).'/draft', [
        'answers' => ['age' => '30'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '31'],
        'client_submission_uuid' => $uuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_uuid_claimed')
        ->assertJsonPath('error.message', 'This submission identifier already belongs to another response.');

    // Exactly one row, still form B's, still its answers.
    enterTenant($f->tenant->id);
    $rows = Submission::query()->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->form_id)->toBe($theirs->id)
        ->and(SubmissionAnswer::query()->findOrFail($rows->first()->id)->answers['age'])->toBe('30');
});

it('refuses a uuid still reserved by a SOFT-DELETED row rather than failing on the index (M11)', function (): void {
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();

    // ⚠️ THE TOMBSTONE KEEPS THE UUID. `submissions_tenant_client_uuid_unique` filters on
    // `client_submission_uuid IS NOT NULL` and NOT on `deleted_at IS NULL`, so a soft-deleted row still owns
    // the index entry while the SoftDeletes global scope hides it from every resolve — which is exactly why
    // ReapTenantDraftsJob hard-deletes. Nothing soft-deletes a submission today, so this asserts a LATENT
    // failure stays closed rather than a live one.
    enterTenant($f->tenant->id);
    Submission::query()->firstOrFail()->delete();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '31'],
        'client_submission_uuid' => $uuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_uuid_claimed')
        ->assertJsonPath('error.message', 'This submission identifier already belongs to another response.');

    enterTenant($f->tenant->id);
    expect(Submission::query()->count())->toBe(0)
        ->and(Submission::withTrashed()->count())->toBe(1);
});

// ── Increment M12 — the pre-lock lost update, on the unauthenticated arm ───────────────────────

it('409s a guest submit whose draft moved inside promote’s pre-lock window (draft_conflict)', function (): void {
    // The flow the resume link invites, and the reason this door mattered most here: two devices hold the
    // SAME client_submission_uuid by design (H9b/H10), promote() read the answer document outside any
    // transaction, and SubmissionFinalizer replaces the whole document — so the second device's answers were
    // gone and the row was `submitted`, which no later save can undo.
    //
    // ⚠️ NO `base_content_checksum` IS SENT, WHICH IS WHAT MAKES THIS MEASURE M12 RATHER THAN P3a. With one,
    // saveDraft()'s own guard fires first and returns the IDENTICAL code and sentence — the wrong-reason pass
    // no envelope assertion could detect. It is also the live shape: `App.vue` remounts with
    // `draftBaseline = null` (docs/feature-backlog.md's 409-folding row), and on such a client this guard is
    // the only one there is.
    $f = draftFixture();
    $uuid = Uuid::uuid7()->toString();

    $save = $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/draft", [
        'answers' => ['age' => '30'], 'client_submission_uuid' => $uuid,
    ])->assertCreated();
    $draftId = $save->json('data.id');

    // The tablet's autosave, committed inside promote()'s window. `skip: 1` steps past the saveDraft() the
    // submit route runs first — see interleaveDuringPromote().
    $moved = ['full_name' => 'Ada', 'age' => '31'];
    $fired = interleaveDuringPromote(function () use ($draftId, $moved): void {
        SubmissionAnswer::query()->where('submission_id', $draftId)->update([
            'answers' => $moved,
            'answers_content_checksum' => AnswersContentChecksum::of($moved),
        ]);
    }, skip: 1);

    Event::fake([SubmissionCreated::class]);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$f->token}/submissions", [
        'answers' => ['full_name' => 'Ada', 'age' => '30'], 'client_submission_uuid' => $uuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'draft_conflict')
        ->assertJsonPath('error.message', 'This draft was updated on another device. Reload it to pick up the newer answers before saving again.');

    expect($fired())->toBeTrue();
    Event::assertNotDispatched(SubmissionCreated::class);

    enterTenant($f->tenant->id);

    // Ksorted before comparing: `===` on arrays is KEY-ORDER sensitive and a jsonb round-trip returns the
    // database's order. Normalising order keeps the comparison strict on the values.
    $survived = SubmissionAnswer::query()->findOrFail($draftId)->answers;
    ksort($survived);
    ksort($moved);

    expect(Submission::query()->count())->toBe(1)
        ->and(Submission::findOrFail($draftId)->status)->toBe(SubmissionStatus::Draft)
        ->and($survived)->toBe($moved);
});
