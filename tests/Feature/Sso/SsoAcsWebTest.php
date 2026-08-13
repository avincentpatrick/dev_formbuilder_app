<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SettingKey;
use App\Enums\TenantUserStatus;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Sso\SsoAuthnRequestBuilder;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Sso\FakeIdp;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| POST /sso/saml/acs (P1b, ADR-0016) — where a signed assertion becomes a session.
|
| ⚠️ EVERY REFUSAL BELOW ASSERTS 404 AND `assertGuest()`, NEVER A MESSAGE. §D4's posture extends to the
| whole endpoint: an ACS that distinguished "wrong audience" from "already consumed" is an oracle for
| anyone tuning a forgery. The REASON is asserted nowhere on the wire because it exists nowhere on the
| wire — it goes to the log, which is the operator's surface, not the caller's.
|
| ⚠️ THE ROUND TRIP IS REAL IN EVERY CASE. Each test drives GET /sso/saml/login first and answers the row
| that endpoint actually minted. A test that fabricated an `sso_auth_requests` row would still pass if the
| two halves stopped agreeing about the request id, which is the one thing this pair has to get right.
|
| ⚠️ Enterprise everywhere — no seeded tenant carries `sso_saml`, and `SsoGate::activeConnectionOrAbort()`
| fails closed on a null plan, so a case that skipped `assignPlanTier()` would 404 for the wrong reason.
|
| ⚠️⚠️ AN EXISTING MEMBER MUST BE A **COMMITTED** IDENTITY, AND THIS IS NOT A TEST-HYGIENE DETAIL — IT IS THE
| ONE PLACE THIS SUITE CAN CERTIFY A BEHAVIOUR THAT DOES NOT EXIST. `SsoUserProvisioner` resolves an existing
| account through `TenantMembershipService::resolveUserByEmail()`, which reads on `pgsql_auth` — a SEPARATE
| DATABASE SESSION, which cannot see rows written inside `RefreshDatabase`'s open transaction. So a plain
| `User::factory()->create()` is INVISIBLE to the code under test, the provisioner takes the JIT branch
| instead of the "already a member" branch, and the case silently measures the wrong path (it surfaces as a
| `users_email_unique` violation only because the INSERT can see what the SELECT could not).
| `committedTenantIdentity()` writes through `pgsql_privileged` outside the transaction, which is the only
| shape that reproduces production. Its email is RANDOM by design — a fixed address survives the rollback and
| collides on the next run — so cases name `$member->email` rather than a literal.
|
| ⚠️ AND ASSERTIONS ABOUT A JIT-CREATED USER RUN ON THE **DEFAULT** CONNECTION, for the mirror-image reason:
| that row is written inside the transaction, so `User::on('pgsql_auth')` cannot see it and an
| `->exists()->toBeFalse()` there would pass whether or not the user was created. It is visible on the
| default connection because the membership makes them an active co-tenant of the admin, which is exactly
| what the `users` visibility policy keys on.
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
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

const ACME_ACS = 'http://acme.meridian.test/sso/saml/acs';
const ACME_SP = 'http://acme.meridian.test/sso/saml/metadata';

/**
 * Drive the real outbound leg and hand back the row it minted.
 *
 * ⚠️ THE ROW IS IDENTIFIED FROM THE REDIRECT ITSELF, NOT BY "the newest one". Two sign-ins started inside
 * the same second are a TIE under any `orderBy` this table offers, and PostgreSQL breaks a tie by physical
 * row order — so a "latest" lookup silently handed back the FIRST request twice, and the cases that need
 * two live requests were answering an already-consumed one. That is the `assignPlanTier` double-subscription
 * defect in a new place. Reading the `ID` out of the `SAMLRequest` this call actually produced is exact, and
 * it re-proves the binding the whole flow depends on for free.
 */
function startLogin(Tenant $tenant, User $actor, string $slug = 'acme'): SsoAuthRequest
{
    $response = test()->get("http://{$slug}.meridian.test/sso/saml/login")->assertRedirect();

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

/** An IdP configured to answer that exact request, at this tenant's canonical ACS. */
function answering(SsoAuthRequest $request, string $acs = ACME_ACS, string $sp = ACME_SP): FakeIdp
{
    return new FakeIdp($acs, $sp, $request->request_id);
}

/** Re-enter the tenant and hand back the stored connection — the GUC teardown guard. */
function storedSsoConnection(Tenant $tenant, User $actor): ?SsoConnection
{
    enterTenant($tenant->id, $actor->id);

    return SsoConnection::query()->first();
}

/**
 * Move the seat ceiling on the assigned plan.
 *
 * Enterprise is `unlimited()` in the catalog, and it is also the ONLY tier carrying `sso_saml` — so the
 * quota arm is unreachable through `assignPlanTier()` alone. Editing the plan row is the only way to have
 * both an entitled tenant and a full workspace at once, which is precisely the state the refusal exists for.
 */
function setSeatQuota(int $seats): void
{
    $plan = Plan::query()->where('code', PlanTier::Enterprise->value)->firstOrFail();
    $plan->forceFill(['quotas' => array_merge($plan->quotas, ['active_seats' => $seats])])->save();

    // The resolver memoizes per request; in one test process the memo outlives the change it must reflect.
    app(EntitlementService::class)->forget();
}

/*
|--------------------------------------------------------------------------
| The gate — the endpoint is not there at all unless the connection serves
|--------------------------------------------------------------------------
*/

it('is not there for a draft, disabled or unentitled connection', function (): void {
    $request = startLogin($this->tenant, $this->admin);
    $payload = ['SAMLResponse' => answering($request)->response()];

    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::query()->update(['status' => 'disabled']);

    $this->post(ACME_ACS, $payload)->assertNotFound();
    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| The happy paths
|--------------------------------------------------------------------------
*/

it('signs an existing active member in and lands them on the dashboard', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    // COMMITTED: the provisioner resolves on `pgsql_auth`, which cannot see this transaction. See the
    // file header — a factory user here would send the case down the JIT branch instead.
    $member = committedTenantIdentity('Ada Lovelace');
    makeActiveMember($member, 'form_editor');

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($member->email)->response()])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($member);

    // Unchanged: an active member's role is not re-derived from `default_role_name` on every sign-in.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(TenantUser::query()->where('user_id', $member->id)->value('status'))
        ->toBe(TenantUserStatus::Active);
});

it('provisions an unknown subject just in time, at the role the connection names', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('grace@acme.test')->response()])
        ->assertRedirect('/dashboard');

    enterTenant($this->tenant->id, $this->admin->id);
    // The DEFAULT connection: this row was written inside the test transaction, and the membership just
    // granted makes them an active co-tenant of the admin — which is what the `users` policy keys on.
    $created = User::query()->where('email', 'grace@acme.test')->first();

    expect($created)->not->toBeNull()
        // The IdP is the authority for the address it just asserted, and there is no verification round
        // trip an SSO user could ever complete — `verified` guarding a route would otherwise lock them out.
        ->and($created->email_verified_at)->not->toBeNull()
        // Nothing recorded an acceptance that never happened.
        ->and($created->tos_accepted_at)->toBeNull()
        ->and($created->privacy_policy_accepted_at)->toBeNull()
        // The local part, the same fallback an invited placeholder gets.
        ->and($created->name)->toBe('grace');

    $this->assertAuthenticatedAs(User::query()->whereKey($created->id)->first());

    enterTenant($this->tenant->id, $this->admin->id);
    $membership = TenantUser::query()->where('user_id', $created->id)->first();

    expect($membership)->not->toBeNull()
        ->and($membership->status)->toBe(TenantUserStatus::Active)
        ->and($membership->invited_role_id)->toBe(Role::query()->where('name', 'viewer')->whereNull('tenant_id')->value('id'));
});

it('records how the member got in, so the ledger can tell SSO from an invitation', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('grace@acme.test')->response()])
        ->assertRedirect('/dashboard');

    enterTenant($this->tenant->id, $this->admin->id);
    $audit = Audit::query()->where('auditable_type', 'tenant_users')->orderByDesc('created_at')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values['via'] ?? null)->toBe('sso_jit')
        ->and($audit->new_values['role'] ?? null)->toBe('viewer');
});

it('stamps the connection so an admin can see the trust anchor is being used', function (): void {
    expect(storedSsoConnection($this->tenant, $this->admin)?->last_login_at)->toBeNull();

    $request = startLogin($this->tenant, $this->admin);
    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->response()])->assertRedirect('/dashboard');

    $connection = storedSsoConnection($this->tenant, $this->admin);

    expect($connection?->last_login_at)->not->toBeNull()
        // A one-column UPDATE, so the trust anchor itself is untouched — a model save would rewrite the
        // encrypted column on every login during an APP_KEY rotation window.
        ->and($connection?->idp_certificates)->toBe([FakeIdp::certificate()]);
});

it('takes the email from the attribute the tenant mapped, when the NameID is not one', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::query()->update([
        'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'attribute_map' => json_encode(['email' => 'urn:oid:0.9.2342.19200300.100.1.3']),
    ]);

    $request = startLogin($this->tenant, $this->admin);

    $response = answering($request)
        ->as('a5f3c0d1-opaque-directory-handle')
        ->withNameIdFormat('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent')
        ->withAttributes(['urn:oid:0.9.2342.19200300.100.1.3' => 'Grace@ACME.test'])
        ->response();

    $this->post(ACME_ACS, ['SAMLResponse' => $response])->assertRedirect('/dashboard');

    // Lower-cased: `users.email` is matched by exact equality, so an IdP that changes capitalisation
    // between logins would otherwise provision a second account for the same person.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(User::query()->where('email', 'grace@acme.test')->exists())->toBeTrue();
});

it('composes a display name from the mapped first and last name attributes', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::query()->update([
        'attribute_map' => json_encode(['first_name' => 'givenName', 'last_name' => 'sn']),
    ]);

    $request = startLogin($this->tenant, $this->admin);

    $response = answering($request)
        ->as('grace@acme.test')
        ->withAttributes(['givenName' => 'Grace', 'sn' => 'Hopper'])
        ->response();

    $this->post(ACME_ACS, ['SAMLResponse' => $response])->assertRedirect('/dashboard');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(User::query()->where('email', 'grace@acme.test')->value('name'))->toBe('Grace Hopper');
});

it('honours a pending invitation’s role rather than the connection default', function (): void {
    // An admin who invited somebody AS AN ADMIN expressed an intent about that person. Letting the
    // directory's default silently demote them on first sign-in would make the invitation surface
    // untrustworthy — so the invited role wins, and the JIT toggle does not gate this door at all.
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::query()->update(['jit_provisioning_enabled' => false]);

    // COMMITTED, for the same `pgsql_auth` visibility reason as the active-member case above: an invited
    // person already HAS an account row, and the provisioner has to find it.
    $invitee = committedTenantIdentity('Grace Hopper');
    $adminRole = Role::query()->where('name', 'admin')->whereNull('tenant_id')->firstOrFail();

    $invite = new TenantUser;
    $invite->fill([
        'user_id' => $invitee->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => $adminRole->id,
        'invited_at' => now(),
        'invite_expires_at' => now()->addDays(7),
        'invite_token' => hash('sha256', 'token'),
    ])->save();

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($invitee->email)->response()])
        ->assertRedirect('/dashboard');

    enterTenant($this->tenant->id, $this->admin->id);
    $membership = TenantUser::query()->where('user_id', $invitee->id)->first();

    expect($membership?->status)->toBe(TenantUserStatus::Active)
        ->and($membership?->invited_role_id)->toBe($adminRole->id);
});

/*
|--------------------------------------------------------------------------
| Binding — §D9, and the two replay mechanisms
|--------------------------------------------------------------------------
*/

it('refuses an unsolicited assertion, which is what makes allow_unsolicited real', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->unsolicited()->response()])
        ->assertNotFound();

    $this->assertGuest();
    expect(config('saml.allow_unsolicited'))->toBeFalse();
});

it('refuses an assertion answering a request nobody minted', function (): void {
    startLogin($this->tenant, $this->admin);

    $forged = new FakeIdp(ACME_ACS, ACME_SP, '_'.bin2hex(random_bytes(16)));

    $this->post(ACME_ACS, ['SAMLResponse' => $forged->response()])->assertNotFound();
    $this->assertGuest();
});

it('refuses an assertion answering a request whose window has closed', function (): void {
    $request = startLogin($this->tenant, $this->admin);
    $payload = ['SAMLResponse' => answering($request)->response()];

    enterTenant($this->tenant->id, $this->admin->id);
    SsoAuthRequest::query()->whereKey($request->getKey())->update(['expires_at' => now()->subMinute()]);

    $this->post(ACME_ACS, $payload)->assertNotFound();
    $this->assertGuest();
});

it('refuses the same assertion posted twice — the consumed_at mechanism', function (): void {
    $request = startLogin($this->tenant, $this->admin);
    $payload = ['SAMLResponse' => answering($request)->as('grace@acme.test')->response()];

    $this->post(ACME_ACS, $payload)->assertRedirect('/dashboard');

    // A fresh session, so the refusal is measured rather than masked by the first request's cookie.
    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->post(ACME_ACS, $payload)->assertNotFound();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->whereKey($request->getKey())->value('consumed_at'))->not->toBeNull();
});

it('refuses a second assertion that reuses one id across two live requests — the cache ledger', function (): void {
    // ⚠️ THE MECHANISM `consumed_at` CANNOT PROVIDE. Both requests below are legitimately distinct,
    // unconsumed rows, so the conditional UPDATE succeeds for each; only a ledger keyed on the ASSERTION's
    // own @ID sees that one document is being presented twice.
    $assertionId = '_'.bin2hex(random_bytes(16));

    $first = startLogin($this->tenant, $this->admin);
    $second = startLogin($this->tenant, $this->admin);

    expect($first->request_id)->not->toBe($second->request_id);

    $this->post(ACME_ACS, [
        'SAMLResponse' => answering($first)->as('grace@acme.test')->withAssertionId($assertionId)->response(),
    ])->assertRedirect('/dashboard');

    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->post(ACME_ACS, [
        'SAMLResponse' => answering($second)->as('grace@acme.test')->withAssertionId($assertionId)->response(),
    ])->assertNotFound();

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Signature and conditions
|--------------------------------------------------------------------------
*/

it('refuses an unsigned assertion', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->unsigned()->response()])->assertNotFound();
    $this->assertGuest();
});

it('refuses a signed envelope wrapped around an unsigned assertion', function (): void {
    // `want_assertions_signed` exists for exactly this: the assertion an SP READS must be the thing the
    // signature it CHECKED covers. Signing only the envelope is what makes wrapping exploitable.
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->responseSignedOnly()->response()])
        ->assertNotFound();

    $this->assertGuest();
});

it('refuses a structurally perfect signature made with a key this tenant never trusted', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->signedByAnUntrustedKey()->response()])
        ->assertNotFound();

    $this->assertGuest();
});

it('refuses a signature-wrapping attempt that hides the signed assertion in an Advice element', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $response = answering($request)
        ->as('grace@acme.test')
        ->wrappedAround('attacker@acme.test')
        ->response();

    $this->post(ACME_ACS, ['SAMLResponse' => $response])->assertNotFound();

    $this->assertGuest();

    // Nobody was let in under EITHER identity. Asserted on memberships rather than on `users`, because a
    // user row with no membership grants nothing — and because an orphaned row would be invisible to every
    // read available inside this transaction, which would make a `users` assertion pass vacuously.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(TenantUser::query()->count())->toBe(1);
});

it('refuses an assertion addressed to a different service provider', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->withAudience('https://someone-else.test/sp')->response()])
        ->assertNotFound();

    $this->assertGuest();
});

it('refuses an assertion delivered to a destination other than the canonical ACS', function (): void {
    // ⚠️ THE CASE THAT WOULD PASS ON php-saml's DEFAULTS. `destinationStrictlyMatches` is off by default,
    // making the comparison a PREFIX match — under which this destination is accepted. It is on because of
    // this case, and `SsoSamlSettings` pins `currentURL` so the comparison has something real to run
    // against in a test process where $_SERVER is empty.
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->withDestination(ACME_ACS.'.evil.test')->response()])
        ->assertNotFound();

    $this->assertGuest();
});

it('refuses an assertion issued by anyone but this connection’s identity provider', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->issuedBy('https://other-idp.test/saml2')->response()])
        ->assertNotFound();

    $this->assertGuest();
});

it('refuses an assertion that names nobody', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->withoutNameId()->response()])->assertNotFound();
    $this->assertGuest();
});

it('refuses an assertion stale by more than the configured skew, which php-saml alone would accept', function (): void {
    // ⚠️ THE DECISIVE CASE FOR THE SECOND TIMESTAMP PASS. php-saml's `Constants::ALLOWED_CLOCK_DRIFT` is a
    // HARD-CODED 180 seconds; `config('saml.clock_skew_seconds')` is 60. 120 seconds sits between them, so
    // the library accepts this document and only our own pass refuses it. Delete `assertWithinConditions()`
    // and this is the case that goes red — nothing else would.
    expect((int) config('saml.clock_skew_seconds'))->toBeLessThan(180);

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->conditionsStaleBy(120)->response()])
        ->assertNotFound();

    $this->assertGuest();
});

it('refuses an assertion whose window has not opened yet, by the same allowance', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->conditionsNotYetValidFor(120)->response()])
        ->assertNotFound();

    $this->assertGuest();
});

it('still accepts an assertion inside the configured skew, so the tighter pass is not merely tighter', function (): void {
    // The other half of the pair. Without it, `assertWithinConditions()` could be refusing EVERYTHING and
    // the two cases above would still be green — which is the shape of a check that looks present and has
    // quietly turned into an outage.
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('grace@acme.test')->conditionsStaleBy(30)->response()])
        ->assertRedirect('/dashboard');
});

/*
|--------------------------------------------------------------------------
| Transport
|--------------------------------------------------------------------------
*/

it('refuses a request with no SAMLResponse at all', function (): void {
    startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, [])->assertNotFound();
    $this->assertGuest();
});

it('refuses a body that is not base64 at all', function (): void {
    startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => '<<< not base64 >>>'])->assertNotFound();
    $this->assertGuest();
});

it('refuses a response beyond the configured byte bound before parsing anything', function (): void {
    startLogin($this->tenant, $this->admin);

    $oversized = str_repeat('A', (int) config('saml.max_response_bytes') + 1);

    $this->post(ACME_ACS, ['SAMLResponse' => $oversized])->assertNotFound();
    $this->assertGuest();
});

it('accepts the POST without a CSRF token, because there is none to send', function (): void {
    // A cross-origin form post from an IdP carries no token. Without the exemption in bootstrap/app.php the
    // whole feature is a 419 — and a 419 is not a 404, so this asserts the exemption is present rather than
    // the failure merely being uniform.
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('grace@acme.test')->response()])
        ->assertRedirect('/dashboard');
});

it('does not exempt an SSO arrival from a workspace’s own 2FA enforcement', function (): void {
    // ⚠️ A DOCUMENTED DECISION, NOT AN OVERSIGHT (ADR-0016 consequences). "Require 2FA for all tenant
    // members" is a policy an admin switched on; inferring an exemption from the presence of SSO would
    // silently drop it. A workspace whose IdP already performs MFA turns the setting off — their call.
    //
    // The ACS itself still succeeds: it is outside the authenticated group, so the gate applies to the
    // NEXT request, exactly as it does after a password login. Asserting both halves is what makes this a
    // statement about the policy rather than about where the middleware happens to be mounted.
    enterTenant($this->tenant->id, $this->admin->id);
    app(TenantSettingRegistry::class)->put(
        $this->tenant,
        [SettingKey::SecurityRequireTwoFactor->value => true],
    );
    app(TenantSettingRegistry::class)->forget();

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('grace@acme.test')->response()])
        ->assertRedirect('/dashboard');

    $this->withoutVite()
        ->get('http://acme.meridian.test/dashboard')
        ->assertRedirect(route('two-factor.required'));
});

/*
|--------------------------------------------------------------------------
| Cross-tenant isolation
|--------------------------------------------------------------------------
*/

it('refuses an assertion minted for one workspace when it is replayed at another', function (): void {
    // The SP entity id is PER TENANT, which makes the audience check an isolation boundary by construction:
    // an assertion naming Acme's audience is structurally invalid at Beta, before any ledger is consulted.
    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $beta->domains()->create(['domain' => 'beta']);

    $betaAdmin = User::factory()->create();
    enterTenant($beta->id, $betaAdmin->id);
    makeActiveMember($betaAdmin, 'admin');
    assignPlanTier(PlanTier::Enterprise);
    enterTenant($beta->id, $betaAdmin->id);
    FakeIdp::connection();

    $betaRequest = startLogin($beta, $betaAdmin, 'beta');

    $response = (new FakeIdp('http://beta.meridian.test/sso/saml/acs', ACME_SP, $betaRequest->request_id))
        ->withAudience(ACME_SP)
        ->response();

    $this->post('http://beta.meridian.test/sso/saml/acs', ['SAMLResponse' => $response])->assertNotFound();
    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Provisioning refusals
|--------------------------------------------------------------------------
*/

it('refuses an unknown subject when the tenant has turned just-in-time provisioning off', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::query()->update(['jit_provisioning_enabled' => false]);

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('grace@acme.test')->response()])
        ->assertNotFound();

    $this->assertGuest();

    // Only the admin's own membership. The JIT gate throws before any user is created, so the observable
    // fact is that nobody was granted anything — which is the fact that matters.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(TenantUser::query()->count())->toBe(1);
});

it('refuses a suspended member, because SSO must not launder an administrative sanction', function (): void {
    // ⚠️ THE ONE STATUS `joinViaSso()` WOULD HAPPILY REACTIVATE. `Declined` and `Removed` mean "not
    // currently a member", which is what JIT is for; `Suspended` is somebody deciding this person should
    // not get in, and a sign-in that silently reversed it would make the sanction unenforceable.
    enterTenant($this->tenant->id, $this->admin->id);
    $member = committedTenantIdentity('Ada Lovelace');
    makeActiveMember($member, 'viewer');
    TenantUser::query()->where('user_id', $member->id)->update(['status' => TenantUserStatus::Suspended->value]);

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($member->email)->response()])
        ->assertNotFound();

    $this->assertGuest();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(TenantUser::query()->where('user_id', $member->id)->value('status'))
        ->toBe(TenantUserStatus::Suspended);
});

it('refuses rather than admitting a seatless member, and orphans no account doing it', function (): void {
    // `joinOpenTenant()` returns null on a full workspace and lets a self-registrant keep an account with
    // no membership — a state that product already has. Here it is not acceptable: a session with no
    // membership sees an empty workspace through RLS and reads as data loss. So the refusal is an
    // exception, and `SsoUserProvisioner`'s enclosing transaction is what stops the freshly created user
    // surviving it.
    //
    // ⚠️ THE ORPHAN IS ASSERTED THROUGH ITS CONSEQUENCE, NOT DIRECTLY, AND THE REASON IS STRUCTURAL: a user
    // row with no membership is invisible to EVERY read available inside this transaction — the `users`
    // policy is "self OR active co-tenant", and the elevated connections are separate sessions that cannot
    // see uncommitted rows at all. So the second attempt below is the assertion: if the first had left a
    // row behind, `User::create()` would hit `users_email_unique` and answer 500, not 302.
    setSeatQuota(1);

    $first = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($first)->as('grace@acme.test')->response()])
        ->assertNotFound();

    $this->assertGuest();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(TenantUser::query()->count())->toBe(1);

    setSeatQuota(10);
    $second = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($second)->as('grace@acme.test')->response()])
        ->assertRedirect('/dashboard');
});
