<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SsoAuthIntent;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sso\SsoAuthnRequestBuilder;
use App\Support\Sso\SsoSession;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Sso\FakeIdp;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| GET /sso/saml/login/complete/{requestId} (P1e, ADR-0016 §D27) — the same-site hop that signs a member in.
|
| ⚠️⚠️ THE CASE THIS FILE EXISTS FOR IS `it refuses a completion hop presented on a browser that never
| started the flow`, AND IT IS THE COMMITTED INVERSE OF A PROBE P1d DELIBERATELY DID NOT COMMIT. P1d
| reproduced the defect — a flow minted in one session, its assertion submitted from a session that had never
| visited the mint endpoint, answering `302 /dashboard` with the poster signed in as the asserted subject —
| and recorded that a test asserting a login-CSRF SUCCEEDS is a bad artefact to leave in a repository. This is
| the assertion that was owed instead.
|
| The attack it models: an attacker holding an account at the tenant's OWN identity provider starts a flow,
| authenticates as themselves, captures the identity provider's auto-POST form WITHOUT submitting it, and
| induces a victim's browser to submit it. Everything the victim then types, uploads or configures lands in
| an account the attacker controls. `InResponseTo` proves this SP minted the flow and says nothing about who
| is holding it.
|
| ⚠️ WHY THE FIX COULD NOT BE A SESSION KEY AT THE ACS, WHICH IS WHAT THE GOOGLE PATH USED. The ACS is a
| genuinely cross-site POST and `session.same_site` is `lax`, so it receives no cookie at all and cannot
| compare one. `SameSite=Lax` DOES send cookies on a top-level GET navigation, which is exactly what the
| ACS's redirect produces — so the comparison happens here, one request later, on the only leg of the flow
| that carries the browser's own session.
|
| ⚠️ EVERY REFUSAL IS 404 AND `assertGuest()`, NEVER A MESSAGE — §D4's posture, carried across from the ACS.
| Unknown id, wrong intent, never verified, already redeemed, past the window and wrong browser are one
| indistinguishable answer.
|
| ⚠️ Enterprise everywhere, and a COMMITTED identity for the member, for the reasons `SsoAcsWebTest`'s header
| gives at length: `SsoUserProvisioner` resolves an existing account on `pgsql_auth`, a separate database
| session that cannot see rows written inside `RefreshDatabase`'s transaction.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    assignPlanTier(PlanTier::Enterprise);

    enterTenant($this->tenant->id, $this->admin->id);
    $this->connection = FakeIdp::connection();

    $this->member = committedTenantIdentity('Ada Lovelace');
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->member, 'form_editor');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

const COMPLETE_HOST = 'http://acme.meridian.test';
const COMPLETE_ACS = 'http://acme.meridian.test/sso/saml/acs';
const COMPLETE_SP = 'http://acme.meridian.test/sso/saml/metadata';

/**
 * Drive the real mint and hand back the row it wrote — which also leaves the browser holding the binding.
 *
 * Names are unique to this file because Pest loads every test file into one process, so a top-level
 * `function` here is global and a collision makes the other file unrunnable on its own.
 */
function beginLogin(Tenant $tenant, User $actor): SsoAuthRequest
{
    $response = test()->get(COMPLETE_HOST.'/sso/saml/login')->assertRedirect();

    $query = [];
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    $document = new DOMDocument;
    $document->loadXML(SsoAuthnRequestBuilder::decodeTransport((string) ($query['SAMLRequest'] ?? '')));
    $requestId = $document->documentElement?->getAttribute('ID') ?? '';

    expect($requestId)->not->toBe('');

    enterTenant($tenant->id, $actor->id);
    $request = SsoAuthRequest::query()->where('request_id', $requestId)->first();

    expect($request)->not->toBeNull();

    return $request;
}

/** Post a valid signed assertion for that row and hand back the completion URL the ACS answers with. */
function handOffFor(SsoAuthRequest $request, string $email): string
{
    $response = test()->post(COMPLETE_ACS, [
        'SAMLResponse' => (new FakeIdp(COMPLETE_ACS, COMPLETE_SP, $request->request_id))->as($email)->response(),
    ])->assertRedirect(COMPLETE_HOST.'/sso/saml/login/complete/'.$request->request_id);

    return (string) $response->headers->get('Location');
}

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('signs the member in on the same-site hop the ACS hands back, and lands them on the dashboard', function (): void {
    $request = beginLogin($this->tenant, $this->admin);

    $location = handOffFor($request, $this->member->email);

    // The ACS finished nothing. It could not: it has no session, and creating one would bind it to whichever
    // browser posted rather than to the one that started.
    $this->assertGuest();

    $this->get($location)->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($this->member);
    expect(session()->has(SsoSession::AUTHENTICATED_AT))->toBeTrue();

    enterTenant($this->tenant->id, $this->admin->id);
    $row = SsoAuthRequest::query()->whereKey($request->getKey())->first();

    expect($row->completed_at)->not->toBeNull()
        ->and((string) $row->resolved_user_id)->toBe((string) $this->member->getKey())
        // Moved here from the ACS: `last_login_at` answers "is sign-in working", so it is stamped where a
        // session actually exists rather than where an assertion merely arrived.
        ->and(SsoConnection::query()->value('last_login_at'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The binding — what P1e exists for
|--------------------------------------------------------------------------
*/

it('refuses a completion hop presented on a browser that never started the flow', function (): void {
    // THE ATTACK, END TO END. The attacker mints in their own browser and gets a real assertion for it.
    $request = beginLogin($this->tenant, $this->admin);

    // ...and the victim's browser is what submits it. `flushSession()` is an exact model of that: a browser
    // that has never visited the mint endpoint holds no binding for this flow.
    $this->flushSession();

    $location = handOffFor($request, $this->member->email);

    $this->get($location)->assertNotFound();

    // The whole point: nobody is signed in, least of all as the subject the attacker's assertion named.
    $this->assertGuest();
    expect(session()->has(SsoSession::AUTHENTICATED_AT))->toBeFalse();
});

it('refuses a flow this browser did not start, even once it has been lured through the mint', function (): void {
    // ⚠️ THE REFINEMENT THAT SEPARATES A CONTROL FROM A DECORATION, and the one `GoogleRedirectController`
    // names on the sibling flow. A hop that merely required the session to hold SOME pending flow would be
    // defeated by first steering the victim to `/sso/saml/login` — a plain top-level GET, which `SameSite=Lax`
    // happily sends the cookie with. The comparison is against THIS flow's own id, so being lured through the
    // mint populates the victim's session with THEIR id and makes the comparison fail rather than pass.
    $attackerFlow = beginLogin($this->tenant, $this->admin);

    $this->flushSession();

    $location = handOffFor($attackerFlow, $this->member->email);

    // The lure: the victim's browser now holds a perfectly good pending flow of its own.
    beginLogin($this->tenant, $this->admin);

    $this->get($location)->assertNotFound();
    $this->assertGuest();
});

it('burns the flow even when the browser is wrong, so a stolen url cannot be retried in the right one', function (): void {
    // `GoogleCompleteController`'s ordering, and its reason: the redeem runs BEFORE the browser comparison,
    // so an assertion tried in the wrong browser is spent. An attacker who induces a victim to submit their
    // assertion therefore destroys their own sign-in rather than merely failing to steal the victim's.
    $request = beginLogin($this->tenant, $this->admin);

    $binding = session(SsoSession::PENDING_LOGIN_IDS);

    $this->flushSession();
    $location = handOffFor($request, $this->member->email);
    $this->get($location)->assertNotFound();

    // The attacker returns to their own browser, which does hold the binding.
    $this->withSession([SsoSession::PENDING_LOGIN_IDS => $binding]);

    $this->get($location)->assertNotFound();
    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Single use, the window, and the states a row can be in
|--------------------------------------------------------------------------
*/

it('refuses a second redemption of the same completion hop', function (): void {
    $request = beginLogin($this->tenant, $this->admin);
    $location = handOffFor($request, $this->member->email);

    $this->get($location)->assertRedirect('/dashboard');
    $this->get($location)->assertNotFound();
});

it('refuses a completion hop presented after the window has closed', function (): void {
    $request = beginLogin($this->tenant, $this->admin);
    $location = handOffFor($request, $this->member->email);

    // The window covers one 302 being followed, not a human authenticating — which is what bounds how long a
    // `request_id` left in a history entry, a referrer header or a proxy log stays worth anything.
    $this->travel((int) config('saml.login_completion_ttl_seconds') + 5)->seconds();

    $this->get($location)->assertNotFound();
    $this->assertGuest();
});

it('refuses a completion hop for a request no assertion ever answered', function (): void {
    // `verified_at` is the ONLY evidence a signed assertion was ever presented. Without it the `request_id` —
    // a value that travels in a URL — would be enough on its own to mint a session.
    $request = beginLogin($this->tenant, $this->admin);

    $this->get(COMPLETE_HOST.'/sso/saml/login/complete/'.$request->request_id)->assertNotFound();
    $this->assertGuest();
});

it('refuses a step-up’s request id at the login hop', function (): void {
    // The two hops must be mutually exclusive by an EXPLICIT predicate at each, not by the shape of the row
    // happening to differ. Otherwise a verified step-up presented here would skip that arm's ForceAuthn,
    // window and subject checks and burn its `completed_at`.
    $this->actingAs($this->admin);
    $this->withSession([SsoSession::AUTHENTICATED_AT => time()]);

    $stepUp = test()->get(COMPLETE_HOST.'/sso/saml/step-up')->assertRedirect();

    $query = [];
    parse_str((string) parse_url((string) $stepUp->headers->get('Location'), PHP_URL_QUERY), $query);
    $document = new DOMDocument;
    $document->loadXML(SsoAuthnRequestBuilder::decodeTransport((string) ($query['SAMLRequest'] ?? '')));
    $requestId = (string) $document->documentElement?->getAttribute('ID');

    $this->get(COMPLETE_HOST.'/sso/saml/login/complete/'.$requestId)->assertNotFound();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->where('request_id', $requestId)->value('completed_at'))->toBeNull();
});

it('refuses a step-up’s request id at the login hop even after its assertion has been verified', function (): void {
    // ⚠️ THE MUTATION PASS IS WHY THIS CASE EXISTS. Removing the intent guard reddened NOTHING, because the
    // sibling case above uses a step-up row no assertion ever answered — which dies on the `verified_at`
    // guard long before intent is consulted. A step-up that HAS been verified is the only state in which the
    // intent check is the thing standing there, and without this case that check was asserted by nobody.
    $this->actingAs($this->member);
    $this->withSession([SsoSession::AUTHENTICATED_AT => time()]);

    $stepUp = $this->get(COMPLETE_HOST.'/sso/saml/step-up')->assertRedirect();

    $query = [];
    parse_str((string) parse_url((string) $stepUp->headers->get('Location'), PHP_URL_QUERY), $query);
    $document = new DOMDocument;
    $document->loadXML(SsoAuthnRequestBuilder::decodeTransport((string) ($query['SAMLRequest'] ?? '')));
    $requestId = (string) $document->documentElement?->getAttribute('ID');

    // A real round trip, so the ACS stamps `verified_at` on a genuine step-up row.
    $this->post(COMPLETE_ACS, [
        'SAMLResponse' => (new FakeIdp(COMPLETE_ACS, COMPLETE_SP, $requestId))->as($this->member->email)->response(),
    ])->assertRedirect(COMPLETE_HOST.'/sso/saml/step-up/complete/'.$requestId);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->where('request_id', $requestId)->value('verified_at'))->not->toBeNull();

    $this->get(COMPLETE_HOST.'/sso/saml/login/complete/'.$requestId)->assertNotFound();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->where('request_id', $requestId)->value('completed_at'))->toBeNull();
});

it('refuses at the database to put a resolved subject on a step-up row', function (): void {
    // ⚠️ ONE ASSERTION AND NOTHING AFTER IT, DELIBERATELY. A raised PostgreSQL error aborts the transaction
    // `RefreshDatabase` wraps this test in, so anything reading afterwards would be pinning the harness
    // rather than the product — the reason P1d left a deadlock case untested.
    $this->actingAs($this->member);
    $this->withSession([SsoSession::AUTHENTICATED_AT => time()]);
    $this->get(COMPLETE_HOST.'/sso/saml/step-up')->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $stepUp = SsoAuthRequest::query()->where('intent', SsoAuthIntent::StepUp)->firstOrFail();

    expect(fn () => SsoAuthRequest::query()->whereKey($stepUp->getKey())->update([
        'verified_at' => now(),
        'resolved_user_id' => (string) $this->member->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses at the database to verify a login row that names nobody', function (): void {
    // ⚠️ THIS IS WHY THE APPLICATION'S OWN `verified_at` GUARD IS UNREACHABLE, AND THE MUTATION PASS SAID SO
    // BEFORE THIS CASE EXISTED. Removing that guard — and even removing its UPDATE predicate too — reddened
    // nothing, because `sso_auth_requests_login_resolution_check` makes "verified login row carrying no
    // subject" a state the database will not hold. The guard is belt and braces over a constraint; this case
    // pins the constraint, which is the half that is actually load-bearing.
    $request = beginLogin($this->tenant, $this->admin);

    expect(fn () => SsoAuthRequest::query()->whereKey($request->getKey())->update([
        'verified_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a completion hop for a request id that was never minted', function (): void {
    $this->get(COMPLETE_HOST.'/sso/saml/login/complete/_'.str_repeat('a', 32))->assertNotFound();
    $this->assertGuest();
});

it('turns away an id that could never have been minted BEFORE the controller runs', function (): void {
    // ⚠️ THE STATUS CODE ALONE CANNOT TELL THESE APART, WHICH IS WHY THIS CASE ASSERTS THE LOG INSTEAD.
    // A router refusal and a `redeem()` returning null both answer 404 — that is §D4's whole point. What
    // separates them is that the router one performs no lookup at all, so it writes nothing.
    Log::spy();

    $this->get(COMPLETE_HOST.'/sso/saml/login/complete/not-a-minted-id')->assertNotFound();

    Log::shouldNotHaveReceived('warning');
});

/*
|--------------------------------------------------------------------------
| The binding's own housekeeping
|--------------------------------------------------------------------------
*/

it('forgets the pending flow once it has been spent, so a session cannot carry a stale binding', function (): void {
    $request = beginLogin($this->tenant, $this->admin);

    expect(SsoSession::hasPendingLogin(session()->driver(), $request->request_id))->toBeTrue();

    $this->get(handOffFor($request, $this->member->email))->assertRedirect('/dashboard');

    expect(SsoSession::hasPendingLogin(session()->driver(), $request->request_id))->toBeFalse();
});

it('lets a second tab finish a sign-in the first tab started', function (): void {
    // ⚠️ A DELIBERATE DIVERGENCE FROM `google.flow_sid`, WHICH HOLDS ONE VALUE. The step-up arm is concurrent
    // for free because its binding is `user_id`; a single-valued key here would make the LOGIN arm strictly
    // less concurrent, so a second tab would silently kill the first and the member would meet a bare 404
    // AFTER their identity provider had already said yes — the one failure §D19 guarantees is never explained.
    $first = beginLogin($this->tenant, $this->admin);
    beginLogin($this->tenant, $this->admin);

    $this->get(handOffFor($first, $this->member->email))->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($this->member);
});

it('holds only the newest few pending flows, because the mint is unauthenticated', function (): void {
    // The stated cost of the list: it is capped, so a browser that starts more sign-ins than the cap without
    // finishing any loses the oldest. `GET /sso/saml/login` writes one entry per hit and anyone holding the
    // hostname can hit it, so an unbounded list would be the same unbounded-growth-on-an-anonymous-write-path
    // problem the row store's own trim exists for.
    $oldest = beginLogin($this->tenant, $this->admin);

    for ($i = 0; $i < 5; $i++) {
        beginLogin($this->tenant, $this->admin);
    }

    expect(SsoSession::hasPendingLogin(session()->driver(), $oldest->request_id))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The destination, and the route's own bounds
|--------------------------------------------------------------------------
*/

it('lands the member where the row says, never where a stored string says', function (): void {
    $request = beginLogin($this->tenant, $this->admin);
    $location = handOffFor($request, $this->member->email);

    // Re-validated on the way OUT even though nothing writes `return_to` on the login arm today: the column
    // is 500 characters of stored text in a database people can edit, and a redirect target is not somewhere
    // to trust a value because of where it was supposed to have come from.
    enterTenant($this->tenant->id, $this->admin->id);
    SsoAuthRequest::query()->whereKey($request->getKey())->update(['return_to' => '//evil.test/steal']);

    $completion = $this->get($location)->assertRedirect('/dashboard');

    // Asserted as a SHAPE rather than as a blocklist: whatever the column held, the browser is sent to a
    // path on the origin it is already on. Nothing here compares hostnames.
    expect(parse_url((string) $completion->headers->get('Location'), PHP_URL_HOST))->toBe('acme.meridian.test');
});

it('carries its own rate limiter and its own pattern, because the id is guessable input', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate) => $candidate->uri() === 'sso/saml/login/complete/{requestId}');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:saml-login-complete')
        // A SEPARATE bucket from the mint's: one completed sign-in costs exactly one hit of each, so sharing
        // would halve whichever ceiling an operator thought they were setting.
        ->and($route->gatherMiddleware())->not->toContain('throttle:saml-login')
        ->and($route->wheres['requestId'] ?? null)->toBe('_[0-9a-f]{32}')
        // ⚠️ AND THE LIMITER MUST ACTUALLY EXIST. A `throttle:` alias naming a limiter nobody registered does
        // not fail loudly — it resolves to an UNLIMITED PASSTHROUGH, so the assertion above would stay green
        // over a bound that is not there.
        ->and(RateLimiter::limiter('saml-login-complete'))->not->toBeNull();
});
