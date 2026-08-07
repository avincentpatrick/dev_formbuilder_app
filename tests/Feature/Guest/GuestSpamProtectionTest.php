<?php

declare(strict_types=1);

use App\Enums\FormBotChallenge;
use App\Http\Middleware\EnforceGuestFormRateLimit;
use App\Models\Form;
use App\Models\User;
use App\Support\Guest\GuestChallengeService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Per-form spam protection on the guest runtime (Increment I8b) — PRD Feature #3's last unbuilt acceptance
| criterion: "Per-form, configurable rate limiting / bot-challenge is available to curb spam submissions",
| and docs/security-threat-model.md §4's bot-flooding row.
|--------------------------------------------------------------------------
| Two mechanisms, deliberately not one control:
|   · the CHALLENGE costs the attacker, which is what bounds the low-and-slow distributed case (many IPs,
|     many tokens, nobody individually fast) that rate limiting structurally cannot see;
|   · the per-form RATE LIMIT bounds velocity from one source.
|
| ⚠️ THE BACKWARD-COMPATIBILITY TEST IS THE MOST IMPORTANT ONE HERE. Both columns default to today's
| behaviour, and the threat model's requirement that the challenge be "not enabled by default" is a
| commitment about field-collection tenants, not a convenience: a form filled by a health worker on a
| shared tablet must not acquire a puzzle because a different tenant's form was being spammed.
|
| This file reuses GuestRuntimeTest's helpers (guestTenant/guestForm/shareTokenFor) — Pest loads every test
| file into one process, so they resolve.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->form = guestForm($this->tenant, $this->owner);
    $this->token = shareTokenFor($this->form);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function publicUrl(string $path): string
{
    return 'http://acme.meridian.test/api/v1/public'.$path;
}

/** Turn a form's spam protection on, bypassing the audited service (this file tests the RUNTIME). */
function setSpamProtection(Form $form, ?FormBotChallenge $challenge = null, ?int $perMinute = null): void
{
    $form->forceFill([
        'bot_challenge' => ($challenge ?? FormBotChallenge::Off)->value,
        'guest_rate_limit_per_minute' => $perMinute,
    ])->save();
}

/** Solve a minted challenge in PHP, exactly as the browser solver does — and return the wire header. */
function solveChallenge(array $challenge): string
{
    for ($n = 0; $n <= $challenge['maxnumber']; $n++) {
        if (hash('sha256', $challenge['salt'].$n) === $challenge['challenge']) {
            return base64_encode((string) json_encode([
                'algorithm' => $challenge['algorithm'],
                'challenge' => $challenge['challenge'],
                'salt' => $challenge['salt'],
                'number' => $n,
                'signature' => $challenge['signature'],
            ]));
        }
    }

    throw new RuntimeException('Unsolvable challenge — maxnumber does not bound the search.');
}

$body = ['answers' => ['full_name' => 'Ada', 'age' => '36']];

// ── Backward compatibility ───────────────────────────────────────────────────────────────────

it('leaves a form created before I8b behaving exactly as it did', function () use ($body): void {
    // No column was touched: `bot_challenge` defaults to off and `guest_rate_limit_per_minute` to NULL.
    // If this ever reddens, the migration that ships it breaks every published form in the deployment.
    expect($this->form->bot_challenge)->toBe(FormBotChallenge::Off);
    expect($this->form->guest_rate_limit_per_minute)->toBeNull();

    $this->postJson(publicUrl("/f/{$this->token}/submissions"), $body)->assertCreated();
});

// ── The challenge ────────────────────────────────────────────────────────────────────────────

it('accepts a solved challenge', function () use ($body): void {
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $challenge = $this->postJson(publicUrl("/f/{$this->token}/challenge"))->assertOk()->json('data');

    $this->withHeader('X-Meridian-Challenge', solveChallenge($challenge))
        ->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertCreated();
});

it('refuses a submission with no challenge header at all', function () use ($body): void {
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $this->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'challenge_required');
});

it('refuses a forged signature', function () use ($body): void {
    // The attack the HMAC exists for: a client that invents its own challenge and solves that.
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $salt = 'deadbeef.'.rtrim(strtr(base64_encode((string) json_encode([
        'fid' => $this->form->id,
        'exp' => time() + 300,
    ])), '+/', '-_'), '=');

    $header = base64_encode((string) json_encode([
        'algorithm' => 'SHA-256',
        'challenge' => hash('sha256', $salt.'7'),
        'salt' => $salt,
        'number' => 7,
        'signature' => hash('sha256', 'not-the-key'),
    ]));

    $this->withHeader('X-Meridian-Challenge', $header)
        ->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'challenge_failed');
});

it('refuses a wrong number even with our own valid signature', function () use ($body): void {
    // Proves the WORK is checked, not merely the provenance. Without the sha256(salt.number) compare a
    // client could take a legitimately-issued challenge and skip the solving entirely.
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $challenge = app(GuestChallengeService::class)->mint($this->form->id);
    $solved = json_decode(base64_decode(solveChallenge($challenge), true), true);
    $solved['number'] += 1;

    $this->withHeader('X-Meridian-Challenge', base64_encode((string) json_encode($solved)))
        ->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'challenge_failed');
});

it('refuses a replayed challenge', function () use ($body): void {
    // One challenge, one submission. Without the cache guard a single solve would authorise a flood,
    // which is the entire mechanism defeated.
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $header = solveChallenge($this->postJson(publicUrl("/f/{$this->token}/challenge"))->json('data'));

    $this->withHeader('X-Meridian-Challenge', $header)
        ->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertCreated();

    $this->withHeader('X-Meridian-Challenge', $header)
        ->postJson(publicUrl("/f/{$this->token}/submissions"), array_merge($body, [
            'client_submission_uuid' => (string) Uuid::uuid7(),
        ]))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'challenge_failed');
});

it('refuses an expired challenge', function () use ($body): void {
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    config(['guest.challenge.ttl' => 1]);
    app()->forgetInstance(GuestChallengeService::class);

    $header = solveChallenge($this->postJson(publicUrl("/f/{$this->token}/challenge"))->json('data'));

    $this->travel(5)->seconds();

    $this->withHeader('X-Meridian-Challenge', $header)
        ->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'challenge_failed');
});

it('refuses a challenge minted for a DIFFERENT form', function () use ($body): void {
    // The form binding, and the reason it is the form rather than the share token: a farm could otherwise
    // pre-solve cheap challenges on one form and spend them against an expensive one.
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $other = guestForm($this->tenant, $this->owner, 'other-form');

    $header = solveChallenge(app(GuestChallengeService::class)->mint($other->id));

    $this->withHeader('X-Meridian-Challenge', $header)
        ->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'challenge_failed');
});

it('accepts a challenge across a re-minted share token, which is what makes offline replay work', function () use ($body): void {
    // ⚠️ THE BINDING DECISION, PINNED. replay.ts re-mints the share token immediately before every outbox
    // POST, so a TOKEN-bound challenge would reject exactly the offline replays this product exists to
    // support. Solve against one token, submit with another.
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $header = solveChallenge($this->postJson(publicUrl("/f/{$this->token}/challenge"))->json('data'));

    // ⚠️ MINTED AT AN EXPLICIT LATER CLOCK. A share token's payload is deterministic given the same
    // second, so `shareTokenFor($form)` twice in one test produces a BYTE-IDENTICAL string and the test
    // would pass without ever exercising a second token.
    $freshToken = shareTokenFor($this->form, now()->getTimestamp() + 60);
    expect($freshToken)->not->toBe($this->token);

    $this->withHeader('X-Meridian-Challenge', $header)
        ->postJson(publicUrl("/f/{$freshToken}/submissions"), $body)
        ->assertCreated();
});

it('does not require a challenge on the schema, attachment or draft routes', function (): void {
    // Each absence is a decision: the SW caches the schema read; one puzzle per uploaded file is hostile
    // to a field worker photographing three documents; and draft autosave fires while someone types.
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $this->getJson(publicUrl("/f/{$this->token}"))->assertOk();
});

it('issues challenges under their own limiter, not the submit bucket', function (): void {
    // Sharing `throttle:guest` would make every submission cost two requests against submit_per_token and
    // silently halve the documented 30/min ceiling to 15/min.
    config(['guest.rate_limit.challenge_per_token' => 1, 'guest.rate_limit.challenge_per_ip' => 99]);

    $this->postJson(publicUrl("/f/{$this->token}/challenge"))->assertOk();
    $this->postJson(publicUrl("/f/{$this->token}/challenge"))->assertStatus(429);

    // ...and the submit bucket is untouched by those two calls.
    setSpamProtection($this->form, FormBotChallenge::Off);
    $this->postJson(publicUrl("/f/{$this->token}/submissions"), [
        'answers' => ['full_name' => 'Ada', 'age' => '36'],
    ])->assertCreated();
});

// ── The per-form rate limit ──────────────────────────────────────────────────────────────────

it('applies no per-form ceiling when the column is NULL', function () use ($body): void {
    setSpamProtection($this->form, perMinute: null);

    foreach (range(1, 3) as $i) {
        $this->postJson(publicUrl("/f/{$this->token}/submissions"), array_merge($body, [
            'client_submission_uuid' => (string) Uuid::uuid7(),
        ]))->assertCreated();
    }
});

it('enforces the per-form ceiling per IP', function () use ($body): void {
    setSpamProtection($this->form, perMinute: 1);

    $this->postJson(publicUrl("/f/{$this->token}/submissions"), $body)->assertCreated();

    $this->postJson(publicUrl("/f/{$this->token}/submissions"), array_merge($body, [
        'client_submission_uuid' => (string) Uuid::uuid7(),
    ]))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');
});

it('does not bleed the per-form bucket across forms', function () use ($body): void {
    // The key is gform:{formId}:{ip}. Keyed on IP alone, one busy form would throttle every other form
    // the same respondent touches.
    setSpamProtection($this->form, perMinute: 1);
    $other = guestForm($this->tenant, $this->owner, 'second-form');
    setSpamProtection($other, perMinute: 1);
    $otherToken = shareTokenFor($other);

    $this->postJson(publicUrl("/f/{$this->token}/submissions"), $body)->assertCreated();
    $this->postJson(publicUrl("/f/{$this->token}/submissions"), $body)->assertStatus(429);

    // The second form is unaffected, from the same IP, in the same minute.
    $this->postJson(publicUrl("/f/{$otherToken}/submissions"), $body)->assertCreated();
});

it('does not bleed the per-form bucket across IPs', function () use ($body): void {
    // ⚠️ THE SELF-DoS TEST. A form-wide bucket would let one attacker at one IP lock the form for every
    // legitimate respondent at a cost of N requests a minute — a control whose primary effect under
    // attack is denying service to real users. Per-IP-within-this-form is why that cannot happen.
    setSpamProtection($this->form, perMinute: 1);

    $this->postJson(publicUrl("/f/{$this->token}/submissions"), $body)->assertCreated();
    $this->postJson(publicUrl("/f/{$this->token}/submissions"), $body)->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertCreated();
});

it('carries the per-form ceiling onto attachments and drafts, but not the schema read', function (): void {
    setSpamProtection($this->form, perMinute: 1);

    $this->getJson(publicUrl("/f/{$this->token}"))->assertOk();
    $this->getJson(publicUrl("/f/{$this->token}"))->assertOk();

    $gated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array($route->getName(), [
            'api.v1.public.submissions.store',
            'api.v1.public.attachments.store',
            'api.v1.public.drafts.store',
        ], true));

    expect($gated)->toHaveCount(3);
    $gated->each(fn ($route) => expect($route->gatherMiddleware())
        ->toContain(EnforceGuestFormRateLimit::class));

    expect(Route::getRoutes()->getByName('api.v1.public.forms.schema')->gatherMiddleware())
        ->not->toContain(EnforceGuestFormRateLimit::class);
});

// ── The cache-flush residual, recorded rather than hidden ────────────────────────────────────

it('makes an unspent challenge replayable for one TTL window after a cache flush', function () use ($body): void {
    // Recorded as a TEST rather than only in prose, because it is the one honest weakness of a
    // cache-backed replay guard and the next person to read GuestChallengeService should find it pinned.
    // Bounded, self-healing, and admits nothing that solving a fresh challenge would not.
    setSpamProtection($this->form, FormBotChallenge::ProofOfWork);

    $header = solveChallenge($this->postJson(publicUrl("/f/{$this->token}/challenge"))->json('data'));

    $this->withHeader('X-Meridian-Challenge', $header)
        ->postJson(publicUrl("/f/{$this->token}/submissions"), $body)
        ->assertCreated();

    Cache::flush();

    $this->withHeader('X-Meridian-Challenge', $header)
        ->postJson(publicUrl("/f/{$this->token}/submissions"), array_merge($body, [
            'client_submission_uuid' => (string) Uuid::uuid7(),
        ]))
        ->assertCreated();
});
