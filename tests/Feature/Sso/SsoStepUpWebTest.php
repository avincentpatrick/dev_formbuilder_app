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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Sso\FakeIdp;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| SSO step-up re-authentication (P1c, ADR-0016 §D22–§D25).
|
| P1b made a hole reachable: a JIT-provisioned member's password is 64 random bytes nobody holds, and
| `auth.password_confirmed_at` had exactly one writer — a form asking for that password. So the four
| `step-up` routes were dead ends for the people SSO exists to serve. These cases pin the fork that closes
| it, and every one of the three conditions that guards the stamp.
|
| ⚠️ THE STAMP NEEDS ALL THREE OF `intent === StepUp`, `ForceAuthn`, AND A `user_id` MATCHING THE SESSION.
| One case per condition, each removing exactly one of them, because a matrix that only tests the happy path
| would pass just as well against a gate that checked nothing.
|
| ⚠️ THE THIRD CONDITION IS ASKED ON A SECOND REQUEST, AND THAT IS A COOKIE POLICY RATHER THAN A DESIGN
| PREFERENCE. `same_site` is `lax`, so the identity provider's cross-site POST to the ACS carries no session
| cookie — there is no `Auth::id()` there to compare against. The ACS therefore marks `verified_at` and
| redirects to a same-site GET, which DOES carry the cookie. Tests reach the completion hop the same way a
| browser does: by following the ACS's redirect.
|
| ⚠️ THE ADMIN IS A **COMMITTED** IDENTITY. `SsoStepUpService::matchSubject()` resolves the assertion's
| subject through `TenantMembershipService::resolveUserByEmail()`, which reads on `pgsql_auth` — a separate
| database session that cannot see `RefreshDatabase`'s open transaction. A `User::factory()` admin would be
| invisible to the code under test, and every step-up would refuse as `step_up_unknown_subject` while
| looking like a subject-mismatch bug.
|
| ⚠️ Enterprise everywhere — no seeded tenant carries `sso_saml`, and the gate fails closed on a null plan,
| so a case that skipped `assignPlanTier()` would 404 for the wrong reason and prove nothing.
|
| ⚠️ HELPER NAMES ARE UNIQUE TO THIS FILE. Pest loads every test file into ONE process, so `startLogin()`
| and `ACME_ACS` — declared at the top level of SsoAcsWebTest.php — cannot be redeclared here, and calling
| them would make this suite unrunnable on its own.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = committedTenantIdentity('Step Up Admin');
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    assignPlanTier(PlanTier::Enterprise);

    enterTenant($this->tenant->id, $this->admin->id);
    $this->connection = FakeIdp::connection();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

const STEP_UP_HOST = 'http://acme.meridian.test';

const STEP_UP_ACS = 'http://acme.meridian.test/sso/saml/acs';

const STEP_UP_SP = 'http://acme.meridian.test/sso/saml/metadata';

/** The step-up-gated route these cases drive. A PUT, so `RequirePassword` takes its redirect arm. */
const STEP_UP_GATED = 'http://acme.meridian.test/settings/sso/idp-metadata';

/**
 * Drive an outbound leg and hand back the row it minted, identified FROM THE REDIRECT ITSELF.
 *
 * ⚠️ NEVER "the newest row". Two requests minted inside one second are a tie, and PostgreSQL breaks ties by
 * physical order — the defect P1b found in its own helper. Reading the `ID` out of the `SAMLRequest` this
 * call actually produced is exact, and it re-proves the binding the whole flow depends on for free.
 */
function driveSsoRedirect(Tenant $tenant, User $actor, string $path): array
{
    $response = test()->get(STEP_UP_HOST.$path)->assertRedirect();

    $query = [];
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    $document = new DOMDocument;
    $document->loadXML(SsoAuthnRequestBuilder::decodeTransport((string) ($query['SAMLRequest'] ?? '')));
    $element = $document->documentElement;
    $requestId = $element?->getAttribute('ID') ?? '';

    expect($requestId)->not->toBe('');

    enterTenant($tenant->id, $actor->id);
    $request = SsoAuthRequest::query()->where('request_id', $requestId)->first();

    expect($request)->not->toBeNull();

    return ['request' => $request, 'element' => $element];
}

/** Begin a step-up and return the `sso_auth_requests` row plus the AuthnRequest element it produced. */
function beginStepUp(Tenant $tenant, User $actor): array
{
    return driveSsoRedirect($tenant, $actor, '/sso/saml/step-up');
}

/** An identity provider configured to answer exactly that request, at this tenant's canonical ACS. */
function answeringStepUp(SsoAuthRequest $request): FakeIdp
{
    return new FakeIdp(STEP_UP_ACS, STEP_UP_SP, $request->request_id);
}

/** Put the session in the state the ACS's login arm leaves it in, without driving the whole round trip. */
function asSsoSession(User $user): void
{
    test()->actingAs($user);
    test()->withSession([SsoSession::AUTHENTICATED_AT => time()]);
}

/*
|--------------------------------------------------------------------------
| The session marker — what the ACS's login arm records about identity source
|--------------------------------------------------------------------------
*/

it('marks the session as identity-provider established when a login round trip completes', function (): void {
    $login = driveSsoRedirect($this->tenant, $this->admin, '/sso/saml/login')['request'];

    $this->post(STEP_UP_ACS, [
        'SAMLResponse' => (new FakeIdp(STEP_UP_ACS, STEP_UP_SP, $login->request_id))
            ->as($this->admin->email)
            ->response(),
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($this->admin);
    $this->assertTrue(session()->has(SsoSession::AUTHENTICATED_AT));
});

/*
|--------------------------------------------------------------------------
| The fork — RequireRecentPassword picks a mechanism, never grants an exemption
|--------------------------------------------------------------------------
*/

it('sends an identity-provider session to its identity provider rather than the password prompt', function (): void {
    asSsoSession($this->admin);

    $this->put(STEP_UP_GATED, ['metadata_xml' => idpMetadataXml()])
        ->assertRedirect(STEP_UP_HOST.'/sso/saml/step-up');
});

it('leaves a password-established session on the confirm-password prompt', function (): void {
    $this->actingAs($this->admin);

    $this->put(STEP_UP_GATED, ['metadata_xml' => idpMetadataXml()])
        ->assertRedirect(route('password.confirm'));
});

it('falls back to the password prompt when the connection can no longer serve', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::query()->update(['status' => 'disabled']);

    asSsoSession($this->admin);

    // The escape hatch, and the reason the fork is not an exemption: a workspace that turned SSO off — or
    // was downgraded off Enterprise — must still be able to reach its own trust anchor to turn it back on.
    $this->put(STEP_UP_GATED, ['metadata_xml' => idpMetadataXml()])
        ->assertRedirect(route('password.confirm'));
});

it('falls back to the password prompt when the workspace is no longer entitled', function (): void {
    // The other half of the escape hatch, and the ADR-0012 §D9 shape: a tenant downgraded off Enterprise
    // keeps a configured, Active connection it can no longer serve. The protocol gate says no, so the
    // fork must say password rather than send them at an endpoint that will 404.
    //
    // ⚠️ DRIVEN THROUGH THE MEMBERS ROUTE, NOT THE METADATA IMPORT. That route also carries
    // `feature:sso_saml`, which is priority-ordered ahead of `step-up` and answers the downgraded tenant
    // first — so the case would assert RequireFeature's refusal while appearing to assert this one.
    $member = committedTenantIdentity('Downgrade Witness');
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($member, 'form_editor');
    assignPlanTier(PlanTier::Free);

    asSsoSession($this->admin);

    $this->patch(STEP_UP_HOST."/members/{$member->id}/role", ['role' => 'viewer'])
        ->assertRedirect(route('password.confirm'));
});

it('never asks the identity provider when the step-up clock is already fresh', function (): void {
    asSsoSession($this->admin);
    confirmPasswordNow();

    $this->put(STEP_UP_GATED, ['metadata_xml' => idpMetadataXml()])->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->where('intent', SsoAuthIntent::StepUp->value)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The outbound leg — a step-up asks a different question from a login
|--------------------------------------------------------------------------
*/

it('mints a ForceAuthn request naming the current user and remembers where they were going', function (): void {
    asSsoSession($this->admin);
    $this->withSession(['url.intended' => STEP_UP_HOST.'/settings/sso?tab=policy']);

    ['request' => $request, 'element' => $element] = beginStepUp($this->tenant, $this->admin);

    // ForceAuthn is the only part of the protocol that asks "is this person at the keyboard NOW"; without
    // it a compliant IdP may answer from the session the member already has there.
    expect($element->getAttribute('ForceAuthn'))->toBe('true');
    expect($request->intent)->toBe(SsoAuthIntent::StepUp);
    expect((string) $request->user_id)->toBe((string) $this->admin->getKey());
    expect($request->force_authn)->toBeTrue();
    // Stored as a PATH — never the absolute URL it arrived as — so the column cannot hold an off-site
    // destination even if a future caller passes one.
    expect($request->return_to)->toBe('/settings/sso?tab=policy');
});

it('is not there at all when the workspace has no live connection', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::query()->update(['status' => 'disabled']);

    asSsoSession($this->admin);

    $this->get(STEP_UP_HOST.'/sso/saml/step-up')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The round trip — and the gate it opens
|--------------------------------------------------------------------------
*/

it('stamps the step-up clock and lands the member back where they were', function (): void {
    asSsoSession($this->admin);
    $this->withSession(['url.intended' => STEP_UP_HOST.'/settings/sso']);

    $request = beginStepUp($this->tenant, $this->admin)['request'];

    $handOff = $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as($this->admin->email)->response(),
    ])->assertRedirect(STEP_UP_HOST.'/sso/saml/step-up/complete/'.$request->request_id);

    // The ACS must NOT have logged anybody in: it has no session to log them into, and doing so would
    // replace the very session this step-up is being performed for.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->where('request_id', $request->request_id)->value('verified_at'))->not->toBeNull();

    $this->get((string) $handOff->headers->get('Location'))->assertRedirect('/settings/sso');

    $this->assertTrue(session()->has('auth.password_confirmed_at'));

    // The gate is now genuinely open — the point of the whole exercise.
    $this->put(STEP_UP_GATED, ['metadata_xml' => idpMetadataXml()])->assertSessionHasNoErrors();
});

it('does not touch last_login_at, so a working step-up cannot mask a broken sign-in', function (): void {
    asSsoSession($this->admin);
    $request = beginStepUp($this->tenant, $this->admin)['request'];

    $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as($this->admin->email)->response(),
    ])->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoConnection::query()->value('last_login_at'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Condition 1 — the intent. A plain login assertion must never clear the gate.
|--------------------------------------------------------------------------
*/

it('refuses to stamp the clock from a plain login round trip', function (): void {
    // The attack `SsoAuthIntent`'s docblock names: an attacker who could induce an ordinary login round
    // trip — unauthenticated by definition, and satisfiable from an existing IdP session without
    // re-prompting — would otherwise silently clear the fifteen-minute gate.
    $login = driveSsoRedirect($this->tenant, $this->admin, '/sso/saml/login')['request'];

    $this->post(STEP_UP_ACS, [
        'SAMLResponse' => (new FakeIdp(STEP_UP_ACS, STEP_UP_SP, $login->request_id))
            ->as($this->admin->email)
            ->response(),
    ])->assertRedirect('/dashboard');

    $this->assertFalse(session()->has('auth.password_confirmed_at'));

    $this->get(STEP_UP_HOST.'/sso/saml/step-up/complete/'.$login->request_id)->assertNotFound();
    $this->assertFalse(session()->has('auth.password_confirmed_at'));
});

/*
|--------------------------------------------------------------------------
| Condition 2 — ForceAuthn. Defence in depth against a row we could not have written.
|--------------------------------------------------------------------------
*/

it('refuses a step-up whose request never carried ForceAuthn', function (): void {
    asSsoSession($this->admin);
    $request = beginStepUp($this->tenant, $this->admin)['request'];

    // Unreachable through the controller, which always sets it. Written directly because the check is what
    // stands between us and a future caller that forgets — and because the CHECK constraint pins the
    // intent/user pair but nothing at the database pins this one.
    enterTenant($this->tenant->id, $this->admin->id);
    SsoAuthRequest::query()->where('request_id', $request->request_id)->update(['force_authn' => false]);

    $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as($this->admin->email)->response(),
    ])->assertNotFound();

    $this->assertFalse(session()->has('auth.password_confirmed_at'));
});

/*
|--------------------------------------------------------------------------
| Condition 3 — the subject, asked twice: of the assertion, then of the session
|--------------------------------------------------------------------------
*/

it('refuses when the identity provider re-authenticated somebody else', function (): void {
    $other = committedTenantIdentity('Somebody Else');

    asSsoSession($this->admin);
    $request = beginStepUp($this->tenant, $this->admin)['request'];

    // Legitimate on a shared machine — and it must still refuse: the pending action belongs to the session
    // that asked, not to whoever happened to sign in at the IdP.
    $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as($other->email)->response(),
    ])->assertNotFound();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->where('request_id', $request->request_id)->value('verified_at'))->toBeNull();
});

it('refuses a subject this deployment has never seen, and never provisions one', function (): void {
    asSsoSession($this->admin);
    $request = beginStepUp($this->tenant, $this->admin)['request'];

    $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as('stranger@acme.test')->response(),
    ])->assertNotFound();

    // A step-up is defined against a session that already exists, so an unknown subject is a mismatch —
    // never a new joiner. `jit_provisioning_enabled` is not what guards this; the absence of a create is.
    expect(User::query()->where('email', 'stranger@acme.test')->exists())->toBeFalse();
});

it('refuses a completion hop presented on somebody else’s session', function (): void {
    $other = committedTenantIdentity('Other Member');
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($other, 'admin');

    asSsoSession($this->admin);
    $request = beginStepUp($this->tenant, $this->admin)['request'];

    $handOff = $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as($this->admin->email)->response(),
    ])->assertRedirect();

    // THE CONDITION THAT ONLY THE SAME-SITE HOP CAN ASK. The row is verified and unredeemed; the only thing
    // wrong is who is holding the browser.
    asSsoSession($other);
    $this->get((string) $handOff->headers->get('Location'))->assertNotFound();

    $this->assertFalse(session()->has('auth.password_confirmed_at'));
});

/*
|--------------------------------------------------------------------------
| Single use, and the window
|--------------------------------------------------------------------------
*/

it('refuses a second redemption of the same completion hop', function (): void {
    asSsoSession($this->admin);
    $request = beginStepUp($this->tenant, $this->admin)['request'];

    $handOff = $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as($this->admin->email)->response(),
    ])->assertRedirect();

    $location = (string) $handOff->headers->get('Location');

    $this->get($location)->assertRedirect('/dashboard');
    $this->get($location)->assertNotFound();
});

it('refuses a completion hop presented after the window has closed', function (): void {
    asSsoSession($this->admin);
    $request = beginStepUp($this->tenant, $this->admin)['request'];

    $handOff = $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as($this->admin->email)->response(),
    ])->assertRedirect();

    // The window covers one 302 being followed, not a human authenticating — so it is two orders of
    // magnitude tighter than `authn_request_ttl_seconds`, and this is what bounds how long a `request_id`
    // left in a history entry or a referrer header stays worth anything.
    $this->travel((int) config('saml.step_up_completion_ttl_seconds') + 5)->seconds();

    $this->get((string) $handOff->headers->get('Location'))->assertNotFound();
    $this->assertFalse(session()->has('auth.password_confirmed_at'));
});

it('refuses a completion hop for a request no assertion ever answered', function (): void {
    asSsoSession($this->admin);
    $request = beginStepUp($this->tenant, $this->admin)['request'];

    // `verified_at` is the ONLY evidence that a signed assertion was ever presented. Without it a
    // `request_id` — a value that travels in a URL — would be enough on its own to stamp the clock.
    $this->get(STEP_UP_HOST.'/sso/saml/step-up/complete/'.$request->request_id)->assertNotFound();
    $this->assertFalse(session()->has('auth.password_confirmed_at'));
});

it('refuses a completion hop for a request id that was never minted', function (): void {
    asSsoSession($this->admin);

    $this->get(STEP_UP_HOST.'/sso/saml/step-up/complete/'.SsoAuthRequest::mintRequestId())->assertNotFound();
});

it('turns away an id that could never have been minted BEFORE the controller runs (P1d)', function (): void {
    asSsoSession($this->admin);

    // ⚠️ THE STATUS CODE ALONE CANNOT TELL THESE APART, WHICH IS WHY THIS CASE ASSERTS THE LOG INSTEAD.
    // Both a router refusal and `redeem()` returning null answer 404 — that is §D4's whole point. What the
    // pattern constraint changes is whether an unbounded guessing surface exists behind `auth` at all: with
    // it, a value that is not `_` plus 32 hex characters never reaches a lookup and writes no line; without
    // it, every guess costs a query and a log entry, which is also how an attacker drowns the operator's
    // surface in noise. The Google handoff has pinned its token at the router since J3c2.
    Log::spy();

    $this->get(STEP_UP_HOST.'/sso/saml/step-up/complete/not-a-minted-id')->assertNotFound();

    Log::shouldNotHaveReceived('warning');
});

it('carries its own rate limiter and its own pattern, because the id is guessable input (P1d)', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->getName() === 'sso.step-up.complete');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:saml-step-up-complete')
        // A SEPARATE bucket from `saml-step-up`: one completed step-up costs exactly one hit of each, so
        // sharing would halve whichever ceiling an operator thought they were setting.
        ->and($route->gatherMiddleware())->not->toContain('throttle:saml-step-up')
        ->and($route->wheres['requestId'] ?? null)->toBe('_[0-9a-f]{32}');
});

/*
|--------------------------------------------------------------------------
| The destination — a redirect target is never trusted for where it came from
|--------------------------------------------------------------------------
*/

it('never leaves this origin, whatever the intended url held', function (string $intended, string $expected): void {
    asSsoSession($this->admin);
    $this->withSession(['url.intended' => $intended]);

    $request = beginStepUp($this->tenant, $this->admin)['request'];
    // Cast because the refused shape stores NULL, and `toContain` has no opinion about null.
    expect((string) $request->return_to)->not->toContain('evil.test');

    $handOff = $this->post(STEP_UP_ACS, [
        'SAMLResponse' => answeringStepUp($request)->as($this->admin->email)->response(),
    ])->assertRedirect();

    $completion = $this->get((string) $handOff->headers->get('Location'))->assertRedirect($expected);

    // The invariant, asserted as a shape rather than as a list of blocked hosts: whatever went in, the
    // browser is sent to a path on the origin it is already on. Nothing here compares hostnames, which is
    // what makes it a property rather than a blocklist.
    expect(parse_url((string) $completion->headers->get('Location'), PHP_URL_HOST))->toBe('acme.meridian.test');
})->with([
    // A foreign absolute URL contributes ONLY its path, and that path lands on our own host. Not an open
    // redirect, and deliberately not an error either: `url.intended` can only hold one of these if a
    // browser sent a foreign `Referer` on a request that had already passed CSRF.
    'another origin' => ['https://evil.test/steal', '/steal'],
    // A protocol-relative URL: `Location: //evil.test/x` leaves the origin while reading, in a log and in
    // a review, exactly like a path. `parse_url` sees the authority, so only `/steal` survives.
    'protocol-relative' => ['//evil.test/steal', '/steal'],
    // The same attack after a browser normalises backslashes. `parse_url` does NOT see an authority here,
    // so the path itself starts `/\` — which the shape check refuses outright, dropping the destination.
    'backslash authority' => ['/\\evil.test/steal', '/dashboard'],
]);
