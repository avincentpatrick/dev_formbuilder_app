<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\Branding\TenantBrandingService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment F5 — the guest runtime backend end-to-end (mint → schema → submit).
|--------------------------------------------------------------------------
| Proves the unauthenticated cross-tenant path: /f/{slug} mints a stateless share token on the subdomain;
| the subdomain-less /api/v1/public endpoints resolve tenant purely from the token, funnelling a
| `source=guest` submission through the SAME F4 SubmissionPipeline. Helpers here are self-contained (they
| only lean on the always-loaded Pest.php globals) so this file runs standalone.
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

// guestTenant() / publishedGuestForm() / guestForm() / shareTokenFor() moved to tests/Pest.php in I8b —
// as top-level functions here they resolved only when THIS file was loaded, so a single-file run of any
// other guest suite died with "Call to undefined function". The H22a apiMember() lesson, second occurrence.

// ── Mint (GET /f/{slug} on the subdomain) ────────────────────────────────────────────────────

it('mints a share token for a guest-enabled published form', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    guestForm($tenant, $owner);

    $this->getJson('http://acme.meridian.test/f/intake')
        ->assertOk()
        ->assertJsonStructure(['shareToken', 'expiresAt', 'form' => ['id', 'title']])
        ->assertJsonPath('form.title', 'Intake');
});

it('404s an unknown slug', function (): void {
    guestTenant();

    $this->getJson('http://acme.meridian.test/f/nope')->assertNotFound();
});

/** The `<html …>` opening tag — the element the personalization invariant is actually about. */
function guestRootTag(string $html): string
{
    preg_match('/<html[^>]*>/', $html, $matches);

    return $matches[0] ?? '';
}

it('never emits personalization attributes on the guest runtime shell (Increment G11)', function (): void {
    // PRD Feature #9 + design-system-reference.md §2.9's governing rule: personalization is strictly a
    // per-user, authenticated-app concern. It must never affect how a tenant's public form renders to a
    // respondent — that is tenant branding, a distinct Phase-3 concept serving a distinct audience.
    //
    // This matters more than it looks. public-runtime.css imports the SAME theme.css as the admin app,
    // so every [data-accent] / [data-font-size] / [data-dyslexia-font] rule physically EXISTS in the
    // guest bundle. They are inert only because nothing ever sets the attributes on the guest <html>.
    // This test is what keeps that true if someone later reaches for a shared layout partial.
    //
    // Asserted while ACTING AS a user with maximal preferences — precisely the scenario §2.9 calls out
    // ("regardless of which admin/builder user is viewing analytics on the back end at the same moment").
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    guestForm($tenant, $owner);

    UserUiPreference::create([
        'user_id' => $owner->id,
        'theme_mode' => 'dark',
        'accent_token' => 'teal',
        'font_size_scale' => 'extra_large',
        'use_dyslexia_friendly_font' => true,
    ]);

    // withoutVite(): this is the only test in this file that renders the guest SHELL rather than
    // hitting a JSON endpoint, and the Pest CI job builds no assets — without the stub, @vite throws
    // "Vite manifest not found". (It passes locally only if you happen to have built.)
    $html = $this->actingAs($owner)
        ->withoutVite()
        ->get('http://acme.meridian.test/f/intake')
        ->assertOk()
        ->getContent();

    // `data-theme-mode` is asserted against the ROOT TAG rather than the whole document, and the change
    // is H23b's. The invariant was always "the guest root element carries no personalization attribute";
    // a whole-document substring search was a serviceable proxy for it right up until a stylesheet
    // legitimately NAMED one of those attributes in a selector — which is what the shared brand partial
    // does (`:root:not([data-theme-mode='light'])…`, the selector shape it must match to win the
    // cascade). The other three axes stay whole-document assertions: nothing anywhere emits or names
    // them, and that is worth continuing to pin at full strength.
    expect(guestRootTag($html))->toStartWith('<html')
        ->and(guestRootTag($html))->not->toContain('data-theme-mode')
        ->and(guestRootTag($html))->not->toContain('data-accent')
        ->and($html)->not->toContain('data-accent')
        ->and($html)->not->toContain('data-font-size')
        ->and($html)->not->toContain('data-dyslexia-font')
        // The opt-in face must not even be referenced in the guest bundle's stylesheet graph.
        ->and($html)->not->toContain('OpenDyslexic');
});

it('keeps the root element attribute-free even when the tenant IS branded (Increment H23b)', function (): void {
    // The layer boundary, asserted where the two layers now actually meet. A tenant brand reaching the
    // respondent must NOT arrive as a personalization attribute — it arrives as a <style> block and
    // nothing else. Getting this wrong by reusing the admin root's emission path would hand every
    // respondent the preferences of whichever member happened to be signed in on that device.
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'admin');
    assignPlanTier(PlanTier::Starter);
    guestForm($tenant, $owner);
    app(TenantBrandingService::class)->setBrandColor($tenant, '#C0392B');

    UserUiPreference::create([
        'user_id' => $owner->id,
        'theme_mode' => 'dark',
        'accent_token' => 'teal',
        'font_size_scale' => 'extra_large',
        'use_dyslexia_friendly_font' => true,
    ]);

    $html = $this->actingAs($owner)
        ->withoutVite()
        ->get('http://acme.meridian.test/f/intake')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('id="tenant-brand"')          // the tenant layer DID arrive …
        ->and(guestRootTag($html))->not->toContain('data-')  // … and the personalization layer did not
        ->and($html)->not->toContain('data-accent')
        ->and($html)->not->toContain('data-font-size')
        ->and($html)->not->toContain('data-dyslexia-font');
});

it('404s a form that exists and is published but has guest access disabled (non-disclosure)', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedGuestForm($tenant, $owner);
    $form->update(['public_slug' => 'secret', 'allow_guest_submissions' => false]);

    $this->getJson('http://acme.meridian.test/f/secret')->assertNotFound();
});

it('404s a guest-enabled form with no published version', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = makeForm($owner, 'Draft only');           // never published
    makeDraftVersion($form);
    $form->update(['public_slug' => 'draft', 'allow_guest_submissions' => true]);

    $this->getJson('http://acme.meridian.test/f/draft')->assertNotFound();
});

// ── Schema read (GET /api/v1/public/f/{shareToken}) ──────────────────────────────────────────

it('returns the pinned published schema for a valid token', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $versionId = (string) $form->current_published_version_id;
    $token = shareTokenFor($form);

    $this->getJson("http://acme.meridian.test/api/v1/public/f/{$token}")
        ->assertOk()
        ->assertJsonPath('data.version.id', $versionId)
        ->assertJsonStructure(['data' => ['form' => ['id', 'title'], 'version' => ['id', 'checksum', 'schema']]]);
});

it('401s a tampered token before any tenant context is set', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);
    // Flip the FIRST char (a guaranteed-significant payload byte). Flipping the LAST base64 char was flaky:
    // its low bits are unused when the encoded length isn't a multiple of 3, so it can decode to the same
    // signature bytes → the tamper slips through and the endpoint returns 200 instead of 401.
    $tampered = ($token[0] === 'A' ? 'B' : 'A').substr($token, 1);

    $this->getJson("http://acme.meridian.test/api/v1/public/f/{$tampered}")
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_share_token');
});

it('401s an expired token', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $expired = shareTokenFor($form, now: time() - 90_000); // minted ~25h ago; TTL is 24h

    $this->getJson("http://acme.meridian.test/api/v1/public/f/{$expired}")
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'share_token_expired');
});

it('403s schema read once guest access is revoked after minting', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);

    // Operator flips the revocation lever after the link was handed out.
    $form->update(['allow_guest_submissions' => false]);

    $this->getJson("http://acme.meridian.test/api/v1/public/f/{$token}")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'guest_disabled');
});

it('still serves the pinned schema for a version that was superseded after minting', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $pinnedVersionId = (string) $form->current_published_version_id;
    $token = shareTokenFor($form);

    // Republish → the pinned version becomes superseded, a new version goes live.
    app(PublishService::class)->publish($form->refresh(), $owner);

    $this->getJson("http://acme.meridian.test/api/v1/public/f/{$token}")
        ->assertOk()
        ->assertJsonPath('data.version.id', $pinnedVersionId); // the old, now-superseded schema still reads
});

// ── Submit (POST /api/v1/public/f/{shareToken}/submissions) ──────────────────────────────────

it('records a guest submission through the pipeline with full provenance', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $versionId = (string) $form->current_published_version_id;
    $token = shareTokenFor($form);

    // No session/cookie/CSRF is sent — the token is the sole credential (the group carries no `web`).
    $this->withHeaders(['User-Agent' => 'GuestBrowser/1.0'])
        ->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
            'answers' => ['full_name' => 'Ada Lovelace', 'age' => '36'],
            'guest_contact_email' => 'ada@example.test',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonStructure(['data' => ['id', 'status']]);

    enterTenant($tenant->id);
    $submission = Submission::query()->firstOrFail();
    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->source)->toBe(SubmissionSource::Guest)
        ->and($submission->respondent_user_id)->toBeNull()
        ->and($submission->tenant_id)->toBe($tenant->id)          // auto-stamped from the token's tenant context
        ->and($submission->form_version_id)->toBe($versionId)
        ->and($submission->guest_contact_email)->toBe('ada@example.test')
        ->and($submission->guest_user_agent)->toBe('GuestBrowser/1.0')
        ->and($submission->guest_ip)->not->toBeNull()             // captured at submit, not page-load
        ->and($submission->guest_token)->toBe(app(GuestShareTokenService::class)->fingerprint($token));

    expect(SubmissionAnswer::query()->findOrFail($submission->id)->answers)
        ->toEqual(['full_name' => 'Ada Lovelace', 'age' => '36']);
    expect(SubmissionAnswerIndex::query()->where('submission_id', $submission->id)->where('field_key', 'age')->exists())
        ->toBeTrue();
});

it('persists device_id + app_version when the guest supplies them (Increment G8b)', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['full_name' => 'Ada Lovelace', 'age' => '36'],
        'device_id' => '0192f1a2-b3c4-7d5e-8f90-1a2b3c4d5e6f',
        'app_version' => 'g8b-abc1234',
    ])->assertCreated();

    enterTenant($tenant->id);
    $submission = Submission::query()->firstOrFail();
    expect($submission->device_id)->toBe('0192f1a2-b3c4-7d5e-8f90-1a2b3c4d5e6f')
        ->and($submission->app_version)->toBe('g8b-abc1234');
});

it('leaves device_id + app_version null when the guest omits them', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['full_name' => 'Ada Lovelace', 'age' => '36'],
    ])->assertCreated();

    enterTenant($tenant->id);
    $submission = Submission::query()->firstOrFail();
    expect($submission->device_id)->toBeNull()->and($submission->app_version)->toBeNull();
});

it('422s a missing required answer and persists nothing', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['full_name' => '', 'age' => '36'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'submission_invalid')
        ->assertJsonStructure(['error' => ['details' => ['fields']]]);

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(0)
        ->and(SubmissionAnswer::query()->count())->toBe(0);
});

it('treats a replayed client_submission_uuid as an idempotent no-op', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);
    $clientUuid = Uuid::uuid7()->toString();

    $payload = [
        'answers' => ['full_name' => 'Grace Hopper', 'age' => '45'],
        'client_submission_uuid' => $clientUuid,
    ];

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", $payload)->assertCreated();
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", $payload)->assertOk(); // 200 replay

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(1);
});

it('409 submission_conflict when the same uuid replays with different content (Increment G8c)', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);
    $clientUuid = Uuid::uuid7()->toString();

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['full_name' => 'Grace Hopper', 'age' => '45'],
        'client_submission_uuid' => $clientUuid,
    ])->assertCreated();

    // Same idempotency key, materially different answers → a genuine concurrent-edit conflict.
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['full_name' => 'Ada Lovelace', 'age' => '36'],
        'client_submission_uuid' => $clientUuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_conflict')
        // ⚠️ THE MESSAGE, NOT ONLY THE CODE (M11). A second cause now shares `submission_conflict`, so a
        // code-only assertion here would pass just as happily for "this uuid was never yours" — which is a
        // DIFFERENT defect and would mean the content rule had stopped working.
        ->assertJsonPath('error.message', 'This response conflicts with a copy already saved for the same submission.');

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(1);
});

it('409s (not 500) a submit against a token whose version the form has moved past', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form); // pinned to v1

    app(PublishService::class)->publish($form->refresh(), $owner); // v1 → superseded, v2 live

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['full_name' => 'Late Comer', 'age' => '20'],
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'form_updated');

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(0);
});

it('401s a submit with a forged token that claims this tenant but is signed with the wrong key', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);

    // An attacker fabricates a well-formed token for the real tenant/form/version, signed with a key they guessed.
    $forged = (new GuestShareTokenService('attacker-key', 86_400, 'attacker-resume-key', 2_592_000))
        ->mint($tenant->id, $form->id, (string) $form->current_published_version_id)->token;

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$forged}/submissions", [
        'answers' => ['full_name' => 'Mallory', 'age' => '30'],
    ])->assertUnauthorized()->assertJsonPath('error.code', 'invalid_share_token');

    enterTenant($tenant->id);
    expect(Submission::query()->count())->toBe(0);
});

it('403s a submit once guest access is revoked', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);
    $form->update(['allow_guest_submissions' => false]);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['full_name' => 'Ada', 'age' => '36'],
    ])->assertForbidden()->assertJsonPath('error.code', 'guest_disabled');
});

// ── Rate limiting (per-IP mint, per-token submit) ────────────────────────────────────────────

it('rate-limits the mint endpoint per IP', function (): void {
    config(['guest.rate_limit.mint_per_ip' => 1]);
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    guestForm($tenant, $owner);

    $this->getJson('http://acme.meridian.test/f/intake')->assertOk();
    $this->getJson('http://acme.meridian.test/f/intake')->assertStatus(429);
});

it('rate-limits the guest submit endpoint', function (): void {
    config(['guest.rate_limit.submit_per_token' => 1, 'guest.rate_limit.submit_per_ip' => 99]);
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $token = shareTokenFor($form);

    $body = ['answers' => ['full_name' => 'Ada', 'age' => '36']];
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", $body)->assertCreated();
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", $body)->assertStatus(429);
});

/*
|--------------------------------------------------------------------------
| Increment M11 — a uuid from ANOTHER form is refused, not resolved.
|--------------------------------------------------------------------------
| The share token fixes the FORM; `client_submission_uuid` arrives in the BODY. Two independent inputs, and
| the uniqueness domain of the index behind them is the whole TENANT — so before M11 a guest holding form
| A's link who named a uuid from form B got either B's id/reference/status serialized back with their own
| answers discarded, or a repeatable unauthenticated 500 from a 23505 no recovery arm could classify.
*/

/** A second guest-enabled form in the same tenant, with its own slug. */
function otherGuestForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Other Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->update(['public_slug' => 'other', 'allow_guest_submissions' => true, 'save_and_resume' => true]);

    return $form->refresh();
}

it('409s (not 500) a guest submit naming a uuid that belongs to ANOTHER form’s draft (M11)', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $mine = guestForm($tenant, $owner);
    $theirs = otherGuestForm($tenant, $owner);
    $uuid = Uuid::uuid7()->toString();

    // Form B has a live guest DRAFT under this uuid.
    app(SubmissionDraftService::class)->saveDraft(new SubmissionPayload(
        version: $theirs->currentPublishedVersion,
        answers: ['full_name' => 'Ada'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
    ));

    $token = shareTokenFor($mine);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", [
        'answers' => ['full_name' => 'Mallory', 'age' => '30'],
        'client_submission_uuid' => $uuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_conflict')
        ->assertJsonPath('error.message', 'This submission identifier already belongs to another response.');

    // Form B's draft is untouched: still a draft, still Ada's answers, and no second row was created.
    enterTenant($tenant->id);
    $foreign = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();
    expect($foreign->status)->toBe(SubmissionStatus::Draft)
        ->and($foreign->form_id)->toBe($theirs->id)
        ->and(SubmissionAnswer::query()->findOrFail($foreign->id)->answers['full_name'])->toBe('Ada')
        ->and(Submission::query()->count())->toBe(1);
});

it('never serializes ANOTHER form’s finalized submission back to a guest who names its uuid (M11)', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $mine = guestForm($tenant, $owner);
    $theirs = otherGuestForm($tenant, $owner);
    $uuid = Uuid::uuid7()->toString();

    $foreignToken = shareTokenFor($theirs);
    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$foreignToken}/submissions", [
        'answers' => ['full_name' => 'Ada'],
        'client_submission_uuid' => $uuid,
    ])->assertCreated();

    enterTenant($tenant->id);
    $foreign = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();

    // The same uuid replayed against form A's token. Before M11 this was a 200 carrying B's id, reference
    // and status whenever the stored checksum could not be compared or happened to match.
    $response = $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($mine).'/submissions', [
        'answers' => ['full_name' => 'Mallory', 'age' => '30'],
        'client_submission_uuid' => $uuid,
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_conflict')
        ->assertJsonPath('error.message', 'This submission identifier already belongs to another response.');
    expect($response->getContent())->not->toContain($foreign->id)
        ->and($response->getContent())->not->toContain((string) $foreign->reference);
});

it('409s a guest submit naming a uuid that belongs to a MEMBER’s draft on the same form (M11)', function (): void {
    $tenant = guestTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = guestForm($tenant, $owner);
    $uuid = Uuid::uuid7()->toString();

    // An encoder's own draft on THIS form — same form, but it has an author, so it is not a guest's to promote.
    app(SubmissionDraftService::class)->saveDraft(new SubmissionPayload(
        version: $form->currentPublishedVersion,
        answers: ['full_name' => 'Ada'],
        source: SubmissionSource::Manual,
        respondentUserId: $owner->id,
        clientSubmissionUuid: $uuid,
    ));

    $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form).'/submissions', [
        'answers' => ['full_name' => 'Mallory', 'age' => '30'],
        'client_submission_uuid' => $uuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'submission_conflict')
        ->assertJsonPath('error.message', 'This submission identifier already belongs to another response.');

    enterTenant($tenant->id);
    expect(Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail()->status)
        ->toBe(SubmissionStatus::Draft);
});
