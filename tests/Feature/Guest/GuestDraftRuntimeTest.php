<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResumeLinkNotification;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
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
    $second = $this->postJson($url, ['answers' => ['age' => '31', 'full_name' => 'Ada'], 'client_submission_uuid' => $uuid])
        ->assertOk(); // 200, not 201 — an in-place overwrite

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

    $this->postJson($url, ['answers' => ['age' => '30'], 'client_submission_uuid' => $uuid])->assertCreated();

    enterTenant($f->tenant->id);
    $originalExpiry = Submission::query()->firstOrFail()->draft_expires_at->toIso8601String();

    // Two days later, a second save must NOT move the (creation-time) expiry.
    $this->travel(2)->days();
    TenantContext::flush();
    $this->postJson($url, ['answers' => ['age' => '31', 'full_name' => 'Ada'], 'client_submission_uuid' => $uuid])->assertOk();

    enterTenant($f->tenant->id);
    expect(Submission::query()->firstOrFail()->draft_expires_at->toIso8601String())->toBe($originalExpiry);
});
