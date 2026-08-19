<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SettingKey;
use App\Enums\TenantUserStatus;
use App\Enums\UsageMetric;
use App\Models\Audit;
use App\Models\GoogleAuthRequest;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Auth\GoogleSignInProvisioner;
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Auth\GoogleAuthStateService;
use App\Support\Auth\GoogleIdentity;
use App\Support\Auth\GoogleIdentityProvider;
use App\Support\Auth\GoogleSignInRefusedException;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Auth\FakeGoogleIdentityProvider;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| First-party Google sign-in, end to end (Increment J3c2 — ADR-0019).
|--------------------------------------------------------------------------
| Three hops on two hosts:
|
|   tenant host   GET /auth/google/redirect             mint a row + a signed state → Google
|   central host  GET /auth/google/callback?code&state  exchange, burn the state, mint a handoff
|   tenant host   GET /auth/google/complete/{handoff}   burn the handoff, provision, SIGN IN
|
| ⚠️ EVERY PRE-EXISTING IDENTITY HERE IS COMMITTED, AND SKIPPING THAT DOES NOT FAIL HONESTLY. Both
| lookups the provisioner performs — by `google_id` and by email — run on the `pgsql_auth` connection,
| which is a SEPARATE SESSION and cannot see `RefreshDatabase`'s open transaction. A `User::factory()`
| fixture is therefore invisible to them, so the flow takes the CREATE branch and dies on the `users.email`
| unique index — a 23505 that reads like a linkage bug and is a fixture bug.
|
| ⚠️ AND EVERY COMMITTED ROW OUTLIVES THE TEST, so every `sub` and every address below is unique per case.
| These rows are cleaned only by `migrate:fresh`; a fixed `sub` would be found by the NEXT run of a
| different case and quietly change which branch it took.
|
| ⚠️ EVERY CASE DRIVES GET ROUTES. A test that POSTs `/login` for a committed identity cannot work under
| `RefreshDatabase` and fails as "Call to a member function all() on array" from inside Fortify — see
| `AuthScanFixturesTest`'s note. The two-factor fork is therefore asserted on the completion hop's redirect
| and session, never by posting credentials.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Without these the gate closes the route that STARTS a sign-in — an unconfigured deployment is a
    // supported state. (Only that route consults the gate; see GoogleSignInGate.)
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect' => 'http://meridian.test/auth/google/callback',
    ]);

    $this->tenant = googleTenant('acme');
    $this->google = fakeGoogle();

    // ⚠️ OPENED BY DEFAULT, AND THAT DIRECTION IS DELIBERATE. `registration.invite_only` is fail-closed
    // TRUE, so a workspace left at its default refuses every new member — which would make the seat-quota
    // and account-creation cases below pass for entirely the wrong reason. Opening here means a case can
    // only fail honestly; the ONE case that is about the closed state closes it explicitly and says so.
    googleOpenWorkspace($this->tenant->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/*
|--------------------------------------------------------------------------
| Fixtures. Named with a `google` prefix because Pest helpers are GLOBAL — `setSeatQuota()` already
| belongs to SsoAcsWebTest, and a duplicate declaration passes every per-file run while fatalling only on
| the full suite.
|--------------------------------------------------------------------------
*/

function googleTenant(string $slug): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

/** A committed account, optionally already bound to a Google `sub`. */
function googleCommittedIdentity(bool $verified = true, ?string $subject = null, ?string $email = null): User
{
    $user = committedTenantIdentity('Committed Google Member', $verified, $email);

    if ($subject !== null) {
        User::on('pgsql_privileged')->whereKey($user->getKey())->update(['google_id' => $subject]);
        $user->setAttribute('google_id', $subject);
        $user->syncOriginalAttribute('google_id');
    }

    return $user;
}

/** A `sub` no other case can collide with, because committed rows outlive the transaction. */
function googleSubject(): string
{
    return 'sub-'.Str::lower(Str::random(24));
}

function googleMintUrl(string $host, ?string $returnTo = null): string
{
    return "http://{$host}/auth/google/redirect".($returnTo === null ? '' : '?return_to='.urlencode($returnTo));
}

function googleCallbackUrl(string $state, string $code = 'authorization-code'): string
{
    return 'http://meridian.test/auth/google/callback?code='.urlencode($code).'&state='.urlencode($state);
}

/** The 64-hex handoff the callback put in its redirect, or '' when it bounced instead. */
function googleHandoffFrom(TestResponse $response): string
{
    preg_match('#/auth/google/complete/([0-9a-f]{64})#', (string) $response->headers->get('Location'), $matches);

    return $matches[1] ?? '';
}

/** Walk the first two hops and hand back the completion URL. */
function googleWalkToCompletion(TestResponse|string $hostOrResponse = 'acme.meridian.test', ?string $returnTo = null): string
{
    /** @var FakeGoogleIdentityProvider $fake */
    $fake = app(GoogleIdentityProvider::class);

    $host = is_string($hostOrResponse) ? $hostOrResponse : 'acme.meridian.test';

    test()->get(googleMintUrl($host, $returnTo))->assertRedirect();

    $callback = test()->get(googleCallbackUrl((string) $fake->lastState()));

    return (string) $callback->headers->get('Location');
}

/**
 * Write this workspace's `registration.invite_only` (J3c2 needs both directions).
 *
 * A raw INSERT rather than `TenantSettingRegistry::put()`, which is `OpenTenantRegistrationTest`'s own
 * approach: `put()` takes an actor and writes an audit row, and half these cases assert that a refusal
 * writes NO audit rows. Named `googleOpenWorkspace` rather than reusing that file's `openTheWorkspace()`
 * because Pest helpers are global and a single-file run does not load the other file.
 */
function googleSetInviteOnly(string $tenantId, bool $inviteOnly): void
{
    enterTenant($tenantId);
    DB::table('settings')->updateOrInsert(
        ['tenant_id' => $tenantId, 'key' => SettingKey::RegistrationInviteOnly->value],
        [
            'id' => Uuid::uuid7()->toString(),
            'value' => json_encode($inviteOnly),
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
    TenantContext::flush();
    app(TenantSettingRegistry::class)->forget();
}

function googleOpenWorkspace(string $tenantId): void
{
    googleSetInviteOnly($tenantId, false);
}

/** Cap `active_seats` while leaving every other quota unlimited. Enterprise, so nothing else is gated. */
function googleSeatQuota(int $seats): void
{
    $plan = Plan::query()->where('code', PlanTier::Enterprise->value)->firstOrFail();
    $plan->forceFill(['quotas' => array_merge($plan->quotas, [UsageMetric::ActiveSeats->value => $seats])])->save();

    app(EntitlementService::class)->forget();
}

/*
|--------------------------------------------------------------------------
| The mint
|--------------------------------------------------------------------------
*/

it('mints a row and sends the browser to Google carrying our own state', function (): void {
    $this->get(googleMintUrl('acme.meridian.test'))
        ->assertRedirect()
        ->assertRedirectContains('accounts.google.test');

    enterTenant($this->tenant->id);
    $row = GoogleAuthRequest::query()->sole();

    expect($row->tenant_id)->toBe($this->tenant->id)
        ->and($row->consumed_at)->toBeNull()
        ->and($row->handoff_hash)->toBeNull()
        // The state the browser carries names THIS row. Only the recording fake can see that: the `sid`
        // claim is inside a signed token and is invisible from the redirect's shape alone.
        ->and($this->google->lastState())->toContain('.')
        ->and($this->google->authorizeCalls)->toHaveCount(1)
        ->and($this->google->authorizeCalls[0]['redirectUri'])->toBe('http://meridian.test/auth/google/callback');
});

it('is not there at all when the deployment has no Google client', function (): void {
    config(['services.google.client_id' => '', 'services.google.client_secret' => '']);

    $this->get(googleMintUrl('acme.meridian.test'))->assertNotFound();

    // 404 rather than 403: "there is nothing here", never "this exists but is switched off".
    enterTenant($this->tenant->id);
    expect(GoogleAuthRequest::query()->count())->toBe(0);
});

it('runs on the central host with no row at all, because a tenant-less row is unwritable', function (): void {
    $this->get(googleMintUrl('meridian.test'))
        ->assertRedirect()
        ->assertRedirectContains('accounts.google.test');

    // ADR-0019 §D12: `TenantIsolation::nullableGlobalSql()` widens SELECT only, so strict writes make a
    // tenant-less row impossible — and the central arm needs no handoff anyway, callback and session
    // sharing a host. The state still verifies; `tid` is legitimately null.
    expect(GoogleAuthRequest::withoutTenantScope()->count())->toBe(0)
        ->and($this->google->authorizeCalls)->toHaveCount(1);
});

it('neutralises a protocol-relative URL into a same-origin path', function (): void {
    $this->get(googleMintUrl('acme.meridian.test', '//evil.test/steal'))->assertRedirect();

    enterTenant($this->tenant->id);

    // ⚠️ `/steal`, NOT null, AND THE FIRST DRAFT OF THIS CASE ASSERTED NULL. `SsoReturnTo` compares no
    // hosts — it DISCARDS the scheme and authority and keeps the path — so `//evil.test/steal` becomes
    // `/steal`, which a browser resolves against the origin it is already on. The attack is neutralised by
    // construction rather than by refusal, and asserting a refusal would have pinned behaviour the class
    // does not have and never claimed to.
    expect(GoogleAuthRequest::query()->sole()->return_to)->toBe('/steal');
});

it('stores nothing at all when a protocol-relative URL carries no path to keep', function (): void {
    $this->get(googleMintUrl('acme.meridian.test', '//evil.test'))->assertRedirect();

    enterTenant($this->tenant->id);

    // The other half of the same rule: with no path there is nothing to salvage, so the column stays null
    // and `SsoReturnTo::destination()` falls back to `/dashboard`.
    expect(GoogleAuthRequest::query()->sole()->return_to)->toBeNull();
});

it('keeps an absolute foreign URL as a same-origin path, which is the documented shape', function (): void {
    $this->get(googleMintUrl('acme.meridian.test', 'https://evil.test/steal'))->assertRedirect();

    enterTenant($this->tenant->id);

    // Stated rather than discovered: the scheme and authority are DISCARDED and only the path survives, so
    // the destination is this workspace's own host by construction.
    expect(GoogleAuthRequest::query()->sole()->return_to)->toBe('/steal');
});

it('bounds the table on the write path rather than waiting for a scheduler', function (): void {
    // ⚠️ No `assignPlanTier()` here, and deliberately no `googleSeatQuota()` either: this case never
    // provisions anybody, and `googleSeatQuota()` would `firstOrFail()` on a plan the `PlanSeeder` has not
    // run for. A fixture that throws for a reason unrelated to the assertion is worse than no fixture.
    //
    // The cap is "not among the newest N". Two mints past a cap of 2 must leave exactly 2 rows, and the
    // survivor set must be the NEWEST — a timestamp comparison would keep every row written in the same
    // second, which is the volume the bound exists for.
    config(['google-auth.requests.max_rows_per_tenant' => 2]);

    $minted = [];

    foreach (range(1, 4) as $ignored) {
        $this->get(googleMintUrl('acme.meridian.test'))->assertRedirect();
        // ⚠️ THE WITNESS IS THE STATE THE BROWSER WAS ACTUALLY HANDED, not a re-read of the table. The
        // first draft asserted `$rows->first()->state_id` equalled `orderByDesc('id')->first()->state_id`
        // — two reads of the SAME surviving rows, both taking the maximum, which is true of any survivor
        // set at all. It passed even with the subquery's ordering flipped to keep the OLDEST N, an
        // inversion that in production makes every mint past the cap delete the row it just wrote, so the
        // state handed to Google names nothing and sign-in is permanently broken for that workspace.
        $minted[] = app(GoogleAuthStateService::class)->verify((string) $this->google->lastState())->stateId;
    }

    enterTenant($this->tenant->id);
    $survivors = GoogleAuthRequest::query()->pluck('state_id')->all();

    // Every row here is still LIVE, so the liveness predicate protects all four and the cap deletes
    // nothing — which is the property that matters most and the one the original had backwards.
    expect($survivors)->toHaveCount(4)
        ->and($survivors)->toContain($minted[3])
        ->and($survivors)->toContain($minted[0]);
});

it('evicts spent rows past the cap, and never a live one', function (): void {
    config(['google-auth.requests.max_rows_per_tenant' => 1]);

    // Two DEAD rows (redeemed) and one LIVE one. A purely rank-based cap would evict the live row the
    // moment two newer rows existed — an unauthenticated denial of authentication for the whole
    // workspace, since anyone can mint rows for any tenant and a consent screen stays open ten minutes.
    enterTenant($this->tenant->id);

    $spent = collect(range(1, 2))->map(fn (int $i): GoogleAuthRequest => GoogleAuthRequest::query()->create([
        'state_id' => GoogleAuthRequest::mintStateId(),
        'issued_at' => now()->subMinutes(5),
        'expires_at' => now()->subMinutes(1),
    ]));

    $live = GoogleAuthRequest::query()->create([
        'state_id' => GoogleAuthRequest::mintStateId(),
        'issued_at' => now()->subMinutes(2),
        'expires_at' => now()->addMinutes(8),
    ]);

    TenantContext::flush();

    // One more mint runs the trim.
    $this->get(googleMintUrl('acme.meridian.test'))->assertRedirect();

    enterTenant($this->tenant->id);
    $remaining = GoogleAuthRequest::query()->pluck('state_id')->all();

    expect($remaining)->toContain($live->state_id)
        ->and($remaining)->not->toContain($spent[0]->state_id)
        ->and($remaining)->not->toContain($spent[1]->state_id);
});

/*
|--------------------------------------------------------------------------
| The state
|--------------------------------------------------------------------------
*/

it('refuses a tampered state before any tenant context exists', function (): void {
    $this->get(googleMintUrl('acme.meridian.test'))->assertRedirect();

    $tampered = substr((string) $this->google->lastState(), 0, -4).'AAAA';

    $this->get(googleCallbackUrl($tampered))
        ->assertRedirect('http://meridian.test/login?google=failed');

    // Never reached Google: the MAC is checked before anything else happens.
    expect($this->google->exchangeCalls)->toHaveCount(0);
});

it('refuses a state whose consent screen was left open too long', function (): void {
    // ⚠️ THE TOKEN IS FORGED ALREADY-EXPIRED RATHER THAN AGED WITH `travel()`, AND THAT IS NOT A
    // CONVENIENCE. `GoogleAuthStateService` stamps and checks `exp` with `time()`, which
    // `Carbon::setTestNow()` does not move — so `$this->travel(11)->minutes()` leaves the TOKEN valid and
    // merely expires the database ROW, and the flow then refuses one hop later for a different reason
    // (`attach()` finding nothing live) and bounces to the tenant host instead of the app URL. The first
    // draft of this case asserted the app-URL bounce and failed, which is how the difference surfaced.
    // The service takes an injectable `$now` for exactly this, and says so in its docblock.
    $expired = app(GoogleAuthStateService::class)->mint($this->tenant->id, GoogleAuthRequest::mintStateId(), time() - 3600);

    $this->get(googleCallbackUrl($expired))
        ->assertRedirect('http://meridian.test/login?google=failed');

    // Never reached Google: expiry is checked in the middleware, before any tenant context exists and long
    // before the exchange.
    expect($this->google->exchangeCalls)->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| The callback
|--------------------------------------------------------------------------
*/

it('burns the state, stores what Google vouched for, and hands off to the tenant host', function (): void {
    $subject = googleSubject();
    $this->google->as($subject, 'new-person@example.test');

    $this->get(googleMintUrl('acme.meridian.test'))->assertRedirect();
    $response = $this->get(googleCallbackUrl((string) $this->google->lastState()));

    $response->assertRedirect();
    expect(googleHandoffFrom($response))->toHaveLength(64)
        ->and((string) $response->headers->get('Location'))->toStartWith('http://acme.meridian.test/');

    enterTenant($this->tenant->id);
    $row = GoogleAuthRequest::query()->sole();

    expect($row->consumed_at)->not->toBeNull()
        ->and($row->google_sub)->toBe($subject)
        ->and($row->google_email_verified)->toBeTrue()
        // ⚠️ THE HASH, NEVER THE TOKEN. The plaintext travels in a URL path, so it lands in browser
        // history, any proxy log and any Referer — the `impersonation_tokens` precedent.
        ->and($row->handoff_hash)->toBe(hash('sha256', googleHandoffFrom($response)))
        ->and($row->handoff_hash)->not->toBe(googleHandoffFrom($response))
        // Nothing is provisioned here: a callback the browser never follows must leave no account behind.
        ->and($row->completed_at)->toBeNull();
});

it('refuses a replayed state and does not ask Google a second time', function (): void {
    $this->get(googleMintUrl('acme.meridian.test'))->assertRedirect();
    $state = (string) $this->google->lastState();

    $this->get(googleCallbackUrl($state))->assertRedirect();
    $replay = $this->get(googleCallbackUrl($state));

    // The affected-row count is the check: the second UPDATE matches nothing.
    $replay->assertRedirect('http://acme.meridian.test/login?google=failed');
    expect(googleHandoffFrom($replay))->toBe('');

    enterTenant($this->tenant->id);
    expect(GoogleAuthRequest::query()->sole()->handoff_hash)->not->toBeNull();
});

it('refuses an identity whose address Google never verified, and stores nothing', function (): void {
    $this->google->unverifiedEmail();

    $this->get(googleMintUrl('acme.meridian.test'))->assertRedirect();

    $this->get(googleCallbackUrl((string) $this->google->lastState()))
        ->assertRedirect('http://acme.meridian.test/login?google=failed');

    enterTenant($this->tenant->id);
    $row = GoogleAuthRequest::query()->sole();

    // The single security bug available in this increment: without the check, anyone who can attach an
    // unverified alias to a Google account signs in as that address.
    expect($row->consumed_at)->toBeNull()
        ->and($row->google_sub)->toBeNull()
        ->and($row->handoff_hash)->toBeNull();
});

it('refuses when the code cannot be exchanged, and says nothing about why', function (): void {
    $this->google->refusingExchange();

    $this->get(googleMintUrl('acme.meridian.test'))->assertRedirect();

    $this->get(googleCallbackUrl((string) $this->google->lastState()))
        ->assertRedirect('http://acme.meridian.test/login?google=failed');

    // A revoked secret, a dead network and a reused code are one outcome to the caller (§D9).
    expect($this->google->exchangeCalls)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| The completion hop — where the session is actually created
|--------------------------------------------------------------------------
*/

it('creates the account, joins the workspace and signs the person in', function (): void {
    $subject = googleSubject();
    $this->google->as($subject, 'brand-new@example.test', 'Brand New');

    $this->get(googleWalkToCompletion())->assertRedirect('/dashboard');

    // ⚠️ THE TENANT GUC IS GONE BY NOW, so a plain `User::query()` re-read finds NOTHING: the middleware's
    // `terminate()` cleared the context, and `users_users_visibility` needs the acting user or an ACTIVE
    // co-tenant. `pgsql_auth` cannot help either — this row was created inside the test transaction and
    // that connection is a separate session. So re-enter the tenant AS the person who just signed in,
    // which is the self arm of the policy and the only door open here.
    $this->assertAuthenticated();
    $signedInId = (string) auth()->id();

    enterTenant($this->tenant->id, $signedInId);
    $user = User::query()->whereKey($signedInId)->sole();

    expect($user->email)->toBe('brand-new@example.test');

    expect($user->google_id)->toBe($subject)
        // Google's claim IS the verification; a mail round trip asking the same question would be theatre.
        ->and($user->email_verified_at)->not->toBeNull()
        // No acceptance is recorded that never happened.
        ->and($user->tos_accepted_at)->toBeNull();

    enterTenant($this->tenant->id, $user->id);
    $membership = TenantUser::query()->where('user_id', $user->id)->sole();

    expect($membership->status)->toBe(TenantUserStatus::Active)
        ->and(Role::query()->whereKey($membership->invited_role_id)->value('name'))->toBe('viewer');
});

it('refuses a replayed handoff, so one consent can never become two sessions', function (): void {
    $completion = googleWalkToCompletion();

    $this->get($completion)->assertRedirect('/dashboard');

    // ⚠️ LOG OUT WITHOUT FLUSHING THE SESSION. The first draft called `flushSession()`, which since the
    // browser-binding fix would ALSO drop `google.flow_sid` — so the retry would be refused as unbound and
    // this case would pass without ever exercising the conditional UPDATE it exists for. Keeping the
    // binding means the ONLY thing left to refuse the retry is `completed_at`.
    Auth::logout();

    $this->get($completion)->assertRedirect('/login?google=failed');
    $this->assertGuest();
});

it('refuses a handoff presented by a browser that did not start the flow', function (): void {
    // ⚠️ OAuth LOGIN-CSRF, AND A SIGNED STATE DOES NOT PREVENT IT. An HMAC proves WE minted the token, not
    // that this browser did. Without the binding, the completion URL is a bearer token: capture it from
    // the callback's `Location` header, hand it to somebody else, and they are signed in as YOU — then
    // type, upload and configure inside your account, with an attacker-chosen `return_to` choosing where
    // they land. The adversarial pass found this after every gate was green.
    $completion = googleWalkToCompletion();

    // A different browser: no `google.flow_sid`, everything else identical.
    $this->flushSession();

    $this->get($completion)->assertRedirect('/login?google=failed');
    $this->assertGuest();
});

it('refuses a central callback presented by a browser that did not start the flow', function (): void {
    // The same attack on the arm that signs in at the callback itself, where there is no handoff at all.
    $this->get(googleMintUrl('meridian.test'))->assertRedirect();
    $state = (string) $this->google->lastState();

    $this->flushSession();

    $this->get(googleCallbackUrl($state))
        ->assertRedirect('http://meridian.test/login?google=failed');
    $this->assertGuest();
});

it('refuses a flow whose state belongs to a DIFFERENT sign-in this browser started', function (): void {
    // ⚠️ PRESENCE IS NOT ENOUGH, WHICH IS WHY THE CHECK COMPARES THE `state_id`. An attacker who can lure
    // the victim through `/auth/google/redirect` first would give them SOME binding; only comparing the
    // flow's own id refuses the attacker's state in the victim's session.
    $this->get(googleMintUrl('meridian.test'))->assertRedirect();
    $attackerState = (string) $this->google->lastState();

    // The victim now starts their own flow in the same browser, overwriting the binding.
    $this->get(googleMintUrl('meridian.test'))->assertRedirect();

    $this->get(googleCallbackUrl($attackerState))
        ->assertRedirect('http://meridian.test/login?google=failed');
    $this->assertGuest();
});

it('refuses a handoff whose sixty seconds have passed', function (): void {
    $completion = googleWalkToCompletion();

    $this->travel(2)->minutes();

    $this->get($completion)->assertRedirect('/login?google=failed');
    $this->assertGuest();
});

it('refuses a handoff minted for another workspace, and RLS is what refuses it', function (): void {
    googleTenant('other');

    $completion = googleWalkToCompletion();
    $elsewhere = str_replace('acme.meridian.test', 'other.meridian.test', $completion);

    // There is no `tenant_id` comparison anywhere in the flow: the other workspace's context simply cannot
    // see the row. Writing the check in PHP would suggest the guarantee lived there.
    $this->get($elsewhere)->assertRedirect('/login?google=failed');
    $this->assertGuest();
});

it('honours a return_to that survived both hops', function (): void {
    $this->get(googleWalkToCompletion('acme.meridian.test', '/forms/archive'))
        ->assertRedirect('/forms/archive');
});

/*
|--------------------------------------------------------------------------
| Linkage — ADR-0019 §D4, the decision of record
|--------------------------------------------------------------------------
*/

it('links onto a verified account, and the write lands where it can be read back', function (): void {
    $subject = googleSubject();
    $existing = googleCommittedIdentity(verified: true);
    $this->google->as($subject, $existing->email);

    $this->get(googleWalkToCompletion())->assertRedirect('/dashboard');
    // The model already in hand, never a re-read: after the request the middleware's `terminate()` has
    // cleared the tenant GUC, so `User::query()` sees nothing through `users_users_visibility`.
    $this->assertAuthenticatedAs($existing);

    // ⚠️ READ BACK ON `pgsql_auth`, WHICH IS THE WHOLE ASSERTION. `users` carries an own-row UPDATE policy
    // keyed on `app.current_user_id`, unset before `Auth::login()`, so the same write on the default
    // connection affects ZERO ROWS and throws nothing. Reading it back on the connection it was written
    // through is what distinguishes "linked" from "silently did nothing".
    expect(User::on('pgsql_auth')->whereKey($existing->getKey())->value('google_id'))->toBe($subject);
});

it('refuses to link onto an account that never proved it owns its own address', function (): void {
    $existing = googleCommittedIdentity(verified: false);
    $this->google->as(googleSubject(), $existing->email);

    $this->get(googleWalkToCompletion())->assertRedirect('/login?google=failed');
    $this->assertGuest();

    // Otherwise whoever registers an unverified row on your address first is handed your account.
    expect(User::on('pgsql_auth')->whereKey($existing->getKey())->value('google_id'))->toBeNull();
});

it('refuses when the address already belongs to a different Google account', function (): void {
    $original = googleSubject();
    $existing = googleCommittedIdentity(verified: true, subject: $original);
    $this->google->as(googleSubject(), $existing->email);

    // The reassigned-Workspace-address takeover: an administrator can hand `alice@company.com` to a new
    // employee after Alice leaves, and the address alone must never carry her account with it.
    $this->get(googleWalkToCompletion())->assertRedirect('/login?google=failed');
    $this->assertGuest();

    expect(User::on('pgsql_auth')->whereKey($existing->getKey())->value('google_id'))->toBe($original);
});

it('resolves a returning person by subject and never rewrites their address from Google', function (): void {
    $subject = googleSubject();
    $existing = googleCommittedIdentity(verified: true, subject: $subject);

    // Google now reports a DIFFERENT address for the same account — a rename, or a Workspace migration.
    $this->google->as($subject, 'renamed-at-google@example.test');

    $this->get(googleWalkToCompletion())->assertRedirect('/dashboard');
    // The model already in hand, never a re-read: after the request the middleware's `terminate()` has
    // cleared the tenant GUC, so `User::query()` sees nothing through `users_users_visibility`.
    $this->assertAuthenticatedAs($existing);

    // §D5: the local address is the record of what this product knows, and silently moving it would change
    // where password resets go.
    expect(User::on('pgsql_auth')->whereKey($existing->getKey())->value('email'))->toBe($existing->email)
        ->and(User::on('pgsql_auth')->where('email', 'renamed-at-google@example.test')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Membership — ADR-0016 §D20, reused rather than restated
|--------------------------------------------------------------------------
*/

it('lets an existing active member straight in, writing no second membership', function (): void {
    $subject = googleSubject();
    $member = googleCommittedIdentity(verified: true, subject: $subject);
    $this->google->as($subject, $member->email);

    enterTenant($this->tenant->id, $member->id);
    makeActiveMember($member, 'admin');
    $before = TenantUser::query()->sole();

    $this->get(googleWalkToCompletion())->assertRedirect('/dashboard');

    enterTenant($this->tenant->id, $member->id);
    $after = TenantUser::query()->sole();

    // Active → in, with no write at all. The common case, and the one that must stay cheap; the role in
    // particular must not be reset to `viewer` by a door the member did not ask to walk through.
    expect($after->getKey())->toBe($before->getKey())
        ->and($after->joined_at->equalTo($before->joined_at))->toBeTrue()
        ->and(Role::query()->whereKey($after->invited_role_id)->value('name'))->toBe('admin');
});

it('refuses a suspended membership rather than quietly reversing the sanction', function (): void {
    $subject = googleSubject();
    $member = googleCommittedIdentity(verified: true, subject: $subject);
    $this->google->as($subject, $member->email);

    enterTenant($this->tenant->id, $member->id);
    makeActiveMember($member, 'admin');
    TenantUser::query()->where('user_id', $member->id)->update(['status' => TenantUserStatus::Suspended]);

    Log::spy();

    $this->get(googleWalkToCompletion())->assertRedirect('/login?google=failed');
    $this->assertGuest();

    enterTenant($this->tenant->id, $member->id);
    expect(TenantUser::query()->sole()->status)->toBe(TenantUserStatus::Suspended);

    // ⚠️ THE LOGGED REASON IS ASSERTED, AND THE MUTATION PASS IS WHY. Deleting the provisioner's Suspended
    // check left this case GREEN: `attachMember()` refuses a suspended row too (P1c added the guard to the
    // shared path deliberately), so `joinViaGoogle()` returns null and the flow still refuses — just with
    // `seat_quota_exhausted` as its reason instead. The user-facing outcome is uniform by design (§D9), so
    // the log is the ONLY place an operator can tell "this person is suspended" from "this workspace is
    // full". Without this assertion the duplication is untested and the reason can rot silently.
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'auth.google.complete.rejected'
            && ($context['reason'] ?? null) === 'membership_suspended',
    );
});

it('refuses an unverified identity at the provisioner too, not only at the callback', function (): void {
    // ⚠️ ADR-0019 §D3 says `email_verified` is checked TWICE, and the mutation pass found only ONE of the
    // two defended: deleting the provisioner's copy left all 31 cases green, because the callback refuses
    // first and the flow never reaches the second. That second check is a backstop for a STORED row whose
    // `google_email_verified` is not true — unreachable today thanks to the migration's identity CHECK
    // constraint and `attach()`, and exactly the kind of defence that rots silently when nothing exercises
    // it. Driven directly, because the HTTP path deliberately cannot reach it.
    enterTenant($this->tenant->id);

    $unverified = new GoogleIdentity('sub-never-verified', 'unverified@example.test', false, 'Nobody');

    expect(fn () => app(GoogleSignInProvisioner::class)->provision($this->tenant, $unverified, request()))
        ->toThrow(GoogleSignInRefusedException::class);

    // And nothing was created on the way to the refusal.
    expect(User::on('pgsql_auth')->where('email', 'unverified@example.test')->exists())->toBeFalse();
});

it('activates an invitation at the INVITED role, not at the default', function (): void {
    $subject = googleSubject();
    $invitee = googleCommittedIdentity(verified: true, subject: $subject);
    $this->google->as($subject, $invitee->email);

    enterTenant($this->tenant->id, $invitee->id);
    $adminRole = Role::query()->where('name', 'admin')->whereNull('tenant_id')->sole();
    TenantUser::query()->create([
        'user_id' => $invitee->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => $adminRole->id,
        'invited_at' => now(),
    ]);

    $this->get(googleWalkToCompletion())->assertRedirect('/dashboard');

    enterTenant($this->tenant->id, $invitee->id);
    $membership = TenantUser::query()->sole();

    // An admin who invited someone AS an admin expressed an intent about that person; letting this door's
    // default silently demote them would make the invitation surface untrustworthy.
    expect($membership->status)->toBe(TenantUserStatus::Active)
        ->and(Role::query()->whereKey($membership->invited_role_id)->value('name'))->toBe('admin');
});

it('refuses a stranger on an invitation-only workspace', function (): void {
    // The one case that closes the workspace the `beforeEach` opened. `registration.invite_only` is
    // fail-closed TRUE by default, so this is the ordinary state of most workspaces rather than an edge
    // case — §D8 records it as a stated consequence: on a default workspace Google works for existing
    // members and invited people, and a stranger is refused. That is correct, and not a bug report.
    googleSetInviteOnly($this->tenant->id, true);

    $this->google->as(googleSubject(), 'stranger@example.test');

    $this->get(googleWalkToCompletion())->assertRedirect('/login?google=failed');
    $this->assertGuest();

    expect(User::query()->where('email', 'stranger@example.test')->exists())->toBeFalse();
});

it('refuses a full workspace and leaves no orphaned account behind', function (): void {
    $subject = googleSubject();
    $this->google->as($subject, 'one-too-many@example.test');

    $seatHolder = googleCommittedIdentity(verified: true);
    enterTenant($this->tenant->id, $seatHolder->id);
    makeActiveMember($seatHolder, 'admin');
    assignPlanTier(PlanTier::Enterprise);
    googleSeatQuota(1);

    $this->get(googleWalkToCompletion())->assertRedirect('/login?google=failed');
    $this->assertGuest();

    // ⚠️⚠️ THE ORPHAN IS OBSERVED BY RETRYING, NOT BY QUERYING, AND THE FIRST DRAFT OF THIS CASE WAS
    // ENTIRELY VACUOUS. It asserted `User::query()->where('email', …)->exists()` is false — but after the
    // request the middleware's `terminate()` has cleared both GUCs, so `users_users_visibility` matches
    // nothing and that is false whether the row exists or not — and `User::on('pgsql_auth')` is a separate
    // session that structurally cannot see a row written inside `RefreshDatabase`'s open transaction. Both
    // were unconditionally false. Deleting the `DB::transaction` wrapper in `provision()` left all 32 cases
    // green while the flow stranded a live account with no workspace on every full-workspace sign-in.
    //
    // Raising the cap and walking the SAME identity through again is what makes the rollback observable:
    // if the account survived, `resolveBySubject()` and `resolveUserByEmail()` are both blind to it (both
    // on `pgsql_auth`), so `createUser()` re-INSERTs the same address and the flow dies on
    // `users_email_unique`. A clean second walk therefore proves the first one left nothing behind.
    googleSeatQuota(5);

    $this->get(googleWalkToCompletion())->assertRedirect('/dashboard');
    $this->assertAuthenticated();
});

/*
|--------------------------------------------------------------------------
| Events, the second factor, and what a refusal must never write
|--------------------------------------------------------------------------
*/

it('announces a new account with Verified and never with Registered', function (): void {
    Event::fake([Registered::class, Verified::class]);

    $this->google->as(googleSubject(), 'announced@example.test');
    $this->get(googleWalkToCompletion())->assertRedirect('/dashboard');

    // `Registered` would additionally fire the framework's own SendEmailVerificationNotification — a
    // verification link for an address Google has already proved — and JoinTenantOnRegistration, which
    // joins at `viewer` with no Suspended refusal and no Invited arm: a second, weaker §D20.
    Event::assertNotDispatched(Registered::class);
    Event::assertDispatched(Verified::class);
});

it('says nothing when a returning person signs in again', function (): void {
    $subject = googleSubject();
    $returning = googleCommittedIdentity(verified: true, subject: $subject);
    $this->google->as($subject, $returning->email);

    enterTenant($this->tenant->id, $returning->id);
    makeActiveMember($returning, 'viewer');

    Event::fake([Registered::class, Verified::class]);

    $this->get(googleWalkToCompletion())->assertRedirect('/dashboard');

    // `SendWelcomeEmail` listens on `Verified`; firing it every time would welcome the same person weekly.
    Event::assertNotDispatched(Verified::class);
    Event::assertNotDispatched(Registered::class);
});

it('sends an enrolled member to the second factor instead of logging them straight in', function (): void {
    $subject = googleSubject();
    $member = googleCommittedIdentity(verified: true, subject: $subject);
    User::on('pgsql_privileged')->whereKey($member->getKey())->update([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->google->as($subject, $member->email);

    enterTenant($this->tenant->id, $member->id);
    makeActiveMember($member, 'admin');

    // ⚠️ THE DELIBERATE DIVERGENCE FROM ADR-0016 §D32 (ADR-0019 §D11). SAML treats the IdP as the
    // authentication authority; a Google account is a CONSUMER credential the end user chose, and this
    // product cannot know whether anything protects it. Someone who enrolled a TOTP must not find it
    // bypassed by a button on the same page.
    //
    // ⚠️ THE SAML TWIN OF THIS CASE IS `SsoLoginCompletionWebTest`'s "signs an enrolled member straight
    // in", and M7 had to write it: until then only ONE side of a two-sided decision was pinned, so the
    // other could have moved without a gate saying anything.
    $this->get(googleWalkToCompletion('acme.meridian.test', '/forms/archive'))
        ->assertRedirect('http://acme.meridian.test/two-factor-challenge');

    $this->assertGuest();
    expect(session('login.id'))->toBe($member->getKey())
        ->and(session('login.remember'))->toBeFalse()
        // `url.intended` is the ONLY handoff that reaches the far side of the challenge: both of Fortify's
        // login responses end in `redirect()->intended(...)`. Without it the destination is silently lost,
        // and only for people with 2FA enabled.
        ->and(session('url.intended'))->toBe('/forms/archive');
});

it('writes no audit row for any refusal, because that table is an amplification primitive', function (): void {
    enterTenant($this->tenant->id);
    $before = Audit::query()->count();

    $this->google->unverifiedEmail();
    $this->get(googleMintUrl('acme.meridian.test'))->assertRedirect();
    $this->get(googleCallbackUrl((string) $this->google->lastState()))->assertRedirect();

    $this->get('http://acme.meridian.test/auth/google/complete/'.str_repeat('a', 64))
        ->assertRedirect('/login?google=failed');

    // `audits` is append-only by RLS and never pruned, so an unauthenticated endpoint writing a row per
    // rejection is a disk-filling primitive anyone on the internet can drive (ADR-0016 §D19).
    enterTenant($this->tenant->id);
    expect(Audit::query()->count())->toBe($before);
});
