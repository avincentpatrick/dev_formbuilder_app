<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
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
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
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

/** A tenant reachable at {slug}.meridian.test. */
function guestTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

/** A published form (required full_name + optional age). Requires enterTenant already called. */
function publishedGuestForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($form->draftVersion, $owner, 'age', FieldType::Integer, 1, [
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Number, // so the pipeline projects a typed index row for `age`
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/** The same, with guest access enabled at a public slug. */
function guestForm(Tenant $tenant, User $owner, string $slug = 'intake'): Form
{
    $form = publishedGuestForm($tenant, $owner);
    $form->update(['public_slug' => $slug, 'allow_guest_submissions' => true]);

    return $form->refresh();
}

/** Mint a share token for a form's current published version (optionally at a forged clock, for expiry tests). */
function shareTokenFor(Form $form, ?int $now = null): string
{
    return app(GuestShareTokenService::class)->mint(
        $form->tenant_id,
        $form->id,
        (string) $form->current_published_version_id,
        $now,
    )->token;
}

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

    expect($html)->toContain('<html')
        ->and($html)->not->toContain('data-theme-mode')
        ->and($html)->not->toContain('data-accent')
        ->and($html)->not->toContain('data-font-size')
        ->and($html)->not->toContain('data-dyslexia-font')
        // The opt-in face must not even be referenced in the guest bundle's stylesheet graph.
        ->and($html)->not->toContain('OpenDyslexic');
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
        ->assertJsonPath('error.code', 'submission_conflict');

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
    $forged = (new GuestShareTokenService('attacker-key', 86_400))
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
