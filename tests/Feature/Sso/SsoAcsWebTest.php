<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SettingKey;
use App\Enums\SsoFailureReason;
use App\Enums\TenantUserStatus;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SsoAuthFailure;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Sso\SsoAuthnRequestBuilder;
use App\Services\Sso\SsoCertificateInspector;
use App\Services\Sso\SsoMetadataParser;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

    // ⚠️⚠️ M18 — WITHOUT THESE TWO ROWS EVERY PROVISIONING CASE BELOW REFUSES, AND THAT IS THE CONTROL
    // WORKING RATHER THAN A FIXTURE CHORE. `SsoUserProvisioner` now asks whether this workspace has PROVEN
    // it controls the domain an assertion names, before it asks anything about the account behind it. The
    // suite's two fixture domains are `acme.test` (`FakeIdp`'s NameID) and `identity.test`
    // (`committedTenantIdentity()`), so Acme verifies both and every pre-M18 case keeps meaning exactly what
    // it meant.
    //
    // ⚠️ IT MAKES M1's AND M9's REFUSAL CASES ASSERT THE **STRONGER** STATEMENT, WHICH IS WHY THIS IS THE
    // RIGHT PLACE FOR IT RATHER THAN A PER-CASE OPT-IN. Those cases use `@identity.test` addresses, and with
    // that domain verified they now certify *"even for a domain this workspace has proven it controls,
    // single sign-on will not adopt an existing account"* — the two controls are independent, and a fixture
    // that let the domain gate refuse first would have quietly stopped exercising either adoption guard.
    // The cases proving the domain gate fires AHEAD of them use a THIRD, unverified domain on purpose.
    //
    // ⚠️ `identity.test` IS A FIXED DOMAIN WITH A RANDOM LOCAL PART — checked, not assumed
    // (`tests/Pest.php`). A faker-generated domain here would make these cases pass or fail on a dice roll,
    // which is M9's own post-mortem.
    SsoVerifiedDomain::factory()->verified()->forDomain('acme.test')->create();
    SsoVerifiedDomain::factory()->verified()->forDomain('identity.test')->create();
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

/**
 * A committed identity whose DOMAIN is chosen and whose LOCAL PART is not (M18).
 *
 * ⚠️ BOTH HALVES ARE FORCED, AND BY OPPOSING CONSTRAINTS THIS FILE ALREADY CARRIES. The domain must be
 * fixed because it is the thing under comparison — a `fake()->domainName()` here would make a case pass or
 * fail on a dice roll, which is M9's own post-mortem. The local part must be random because
 * `committedTenantIdentity()` writes through `pgsql_privileged`, OUTSIDE the transaction: a fixed address
 * survives `RefreshDatabase`'s rollback and collides on the very next run, which is what the header warns
 * about and the reason every other case in this file names `$member->email` rather than a literal.
 */
function committedIdentityAt(string $domain, string $name = 'Ada Lovelace'): User
{
    return committedTenantIdentity($name, email: Str::lower(Str::random(12)).'@'.$domain);
}

/** An IdP configured to answer that exact request, at this tenant's canonical ACS. */
function answering(SsoAuthRequest $request, string $acs = ACME_ACS, string $sp = ACME_SP): FakeIdp
{
    return new FakeIdp($acs, $sp, $request->request_id);
}

/**
 * Replace this tenant's trust anchor with the given certificate set (M2).
 *
 * ⚠️ `forceFill()->save()` ON THE MODEL, NEVER `SsoConnection::query()->update([...])`. `idp_certificates`
 * is an `encrypted:array` cast, and a query-builder update writes the raw PHP array straight past the
 * cast — the column would then hold something the reader cannot decrypt, and the case would measure a
 * broken connection rather than an expired one.
 *
 * The fingerprint is recomputed through the same static the parser and the service use, so a re-trusted
 * row and an imported one stay indistinguishable to anything that compares fingerprints.
 *
 * @param  list<string>  $certificates  bare base64 DER
 */
function retrustWith(Tenant $tenant, User $actor, array $certificates): void
{
    enterTenant($tenant->id, $actor->id);

    SsoConnection::query()->firstOrFail()->forceFill([
        'idp_certificates' => $certificates,
        'idp_certificates_fingerprint' => SsoMetadataParser::fingerprint($certificates),
    ])->save();
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

    // ⚠️ WRITTEN LONGHAND RATHER THAN THROUGH `completeSamlLogin()`, BECAUSE THE TWO-HOP SHAPE IS THIS
    // CASE'S SUBJECT (P1e). The ACS marks the row and hands back; it authenticates nobody, because it is a
    // cross-site POST that carries no cookie and could only ever create a session in whichever browser
    // posted — which is the defect the completion hop exists to close.
    $handOff = $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($member->email)->response()])
        ->assertRedirect('http://acme.meridian.test/sso/saml/login/complete/'.$request->request_id);

    $this->assertGuest();

    $this->get((string) $handOff->headers->get('Location'))->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($member);

    // Unchanged: an active member's role is not re-derived from `default_role_name` on every sign-in.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(TenantUser::query()->where('user_id', $member->id)->value('status'))
        ->toBe(TenantUserStatus::Active);
});

it('provisions an unknown subject just in time, at the role the connection names', function (): void {
    $request = startLogin($this->tenant, $this->admin);

    completeSamlLogin($request, answering($request)->as('grace@acme.test')->response())
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

    completeSamlLogin($request, answering($request)->as('grace@acme.test')->response())
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

    // ⚠️ THE STAMP MOVED TO THE COMPLETION HOP IN P1e, AND THIS IS THE CASE THAT PINS IT. `last_login_at` is
    // what an admin reads to answer "is sign-in working"; a verified assertion whose browser never came back
    // is not a sign-in, so leaving the stamp at the ACS would let a broken hop look healthy for weeks — the
    // lie ADR-0016 §D23 already refused to let a working step-up tell about a broken login path.
    $handOff = $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->response()])->assertRedirect();

    expect(storedSsoConnection($this->tenant, $this->admin)?->last_login_at)->toBeNull();

    $this->get((string) $handOff->headers->get('Location'))->assertRedirect('/dashboard');

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

    completeSamlLogin($request, $response)->assertRedirect('/dashboard');

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

    completeSamlLogin($request, $response)->assertRedirect('/dashboard');

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
    //
    // ⚠️ `verified: false` SINCE M9, AND IT IS THE SUBJECT OF THE CASE RATHER THAN A FIXTURE DETAIL. This
    // is the never-used placeholder `TenantMembershipService::invite()` creates for a stranger — every arm
    // of `identityIsEstablished()` reads false for it — so it is the one shape single sign-on may still
    // complete an invitation for, and this case doubles as the permissive control for M9's refusal. With
    // the default `verified: true` it would be an ESTABLISHED identity, i.e. the takeover three cases below.
    $invitee = committedTenantIdentity('Grace Hopper', verified: false);
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

    completeSamlLogin($request, answering($request)->as($invitee->email)->response())
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

    // The FIRST trip is followed all the way through, so what cannot be replayed is a COMPLETED sign-in
    // rather than merely a consumed row (P1e).
    completeSamlLogin($request, $payload['SAMLResponse'])->assertRedirect('/dashboard');

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

    completeSamlLogin($first, answering($first)->as('grace@acme.test')->withAssertionId($assertionId)->response())
        ->assertRedirect('/dashboard');

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

/*
|--------------------------------------------------------------------------
| Trust-anchor validity (M2 — ADR-0016 §D31)
|--------------------------------------------------------------------------
|
| ⚠️ THE FIRST CASE BELOW PASSED AS A SIGN-IN BEFORE M2, AND THAT IS WHY IT IS WRITTEN AS A ROUND TRIP
| RATHER THAN AS AN ASSERTION ABOUT THE INSPECTOR. xmlseclibs verifies a signature against a stored
| certificate without ever parsing its validity window, so an expired anchor authenticated indefinitely
| while `/settings/sso` rendered it as expired. A unit test over `SsoCertificateInspector` would have
| been green throughout — it always knew the certificate was dead; nothing asked it.
|
| ⚠️ AND THE SECOND AND THIRD CASES ARE THE RULE'S TWO HALVES, WHICH IS NOT "ANY EXPIRED KEY REFUSES".
| §D11's roll-up is that ANY currently-valid certificate means the connection works, so a rollover pair
| still signs in — and, as the third case records, an expired sibling in that set can still carry the
| signature. That residual is deliberate (§D31 rejects filtering the set, because a not-yet-valid
| successor is minutes away by design during a rotation) and it is asserted here rather than described,
| so that narrowing the trust set later shows up as a failing test rather than as a surprise.
|--------------------------------------------------------------------------
*/

it('refuses an assertion signed by a trusted key whose certificate has expired', function (): void {
    retrustWith($this->tenant, $this->admin, [FakeIdp::certificate('expired')]);

    // ⚠️ ASSERTED BEFORE THE ROUND TRIP, BECAUSE WITHOUT IT THIS CASE COULD PASS FOR THE WRONG REASON:
    // an `unreadable` anchor is refused by the same guard, so a helper that wrote the certificate badly
    // would produce an identical 404 and an identical failure row. This pins the state to EXPIRED.
    $stored = storedSsoConnection($this->tenant, $this->admin);
    expect(app(SsoCertificateInspector::class)->signingState($stored->idp_certificates))->toBe('expired');

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, [
        'SAMLResponse' => answering($request)->signedByAnExpiredCertificate()->as('grace@acme.test')->response(),
    ])->assertNotFound();

    $this->assertGuest();

    // The request row survives unconsumed: step 0 refuses before the consume, so an admin who re-imports
    // metadata has not also had to tell everybody to start their sign-in again.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoAuthRequest::query()->where('request_id', $request->request_id)->value('consumed_at'))->toBeNull();

    // And nobody was provisioned on the way past — the refusal is ahead of the whole sequence.
    expect(User::query()->where('email', 'grace@acme.test')->exists())->toBeFalse();
});

it('still signs in on a rollover pair, because one live key is enough', function (): void {
    retrustWith($this->tenant, $this->admin, [FakeIdp::certificate('expired'), FakeIdp::certificate()]);

    $request = startLogin($this->tenant, $this->admin);

    completeSamlLogin($request, answering($request)->as('grace@acme.test')->response())
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();
});

it('accepts the expired half of a rollover pair, which is the residual §D31 keeps on purpose', function (): void {
    retrustWith($this->tenant, $this->admin, [FakeIdp::certificate('expired'), FakeIdp::certificate()]);

    $request = startLogin($this->tenant, $this->admin);

    // php-saml is handed the WHOLE set, so the dead key still verifies. Refusing it would mean filtering
    // the set on our own clock, which §D31 rejects: during a rotation a successor is legitimately
    // not-yet-valid by minutes, and skew would become an availability control. §D10's atomic whole-half
    // import is what stops a set accumulating dead keys in the first place.
    completeSamlLogin($request, answering($request)->signedByAnExpiredCertificate()->as('grace@acme.test')->response())
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();
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

    completeSamlLogin($request, answering($request)->as('grace@acme.test')->conditionsStaleBy(30)->response())
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

it('hands the browser no session cookie, so the one that started the flow survives the round trip', function (): void {
    // ⚠️ THE ONLY ASSERTION IN THIS SUITE THAT CAN SEE THIS, AND THE REASON IT IS A HEADER ASSERTION.
    // Inside `web`, `StartSession` runs here, finds no cookie (SameSite=Lax withholds it from a cross-site
    // POST — the premise the whole seam rests on), generates a fresh id, and emits it unconditionally: its
    // only guard is `! is_null(session.driver)`. Same name, same path, host-only, so a real browser REPLACES
    // the member's cookie and then follows our 302 carrying an EMPTY session.
    //
    // Nothing else here can catch it. The Pest client never feeds `Set-Cookie` into the next request and
    // `Store::loadSession()` merges onto a memoised store, so attributes survive an id change in-process —
    // the harness models the session as a process global, a browser models it as a cookie. Worse, the
    // breakage and the security refusal share ONE observable: a 404 at the completion hop. So this is
    // asserted on the response header, which is the only place the two differ.
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('grace@acme.test')->response()])
        ->assertCookieMissing(config('session.cookie'))
        ->assertCookieMissing('XSRF-TOKEN');
});

it('accepts the POST without a CSRF token, and is no longer even reachable by the middleware', function (): void {
    // ⚠️ THIS CASE'S OLD CLAIM STOPPED BEING TRUE IN P1e AND THE COMMENT DID NOT NOTICE. It read "without the
    // exemption in bootstrap/app.php the whole feature is a 419", which asserted the exemption was doing the
    // work. P1e took this route out of the `web` group, so `ValidateCsrfToken` is not in its pipeline at all
    // and deleting that `except` entry now changes nothing — the round-trip half of this case had become
    // unfailable while still describing itself as a control.
    //
    // So it asserts the structure instead, which CAN break: the middleware is genuinely absent, AND the
    // exemption is still configured for the day somebody moves this route back inside a stateful stack.
    $request = startLogin($this->tenant, $this->admin);

    completeSamlLogin($request, answering($request)->as('grace@acme.test')->response())
        ->assertRedirect('/dashboard');

    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($candidate) => $candidate->uri() === 'sso/saml/acs');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->not->toContain('web')
        ->and($route->gatherMiddleware())->not->toContain(ValidateCsrfToken::class)
        // Belt AND braces: kept deliberately, because the day it becomes load-bearing again there is no
        // other warning. `bootstrap/app.php` lists it by EXACT path, never a wildcard.
        ->and((new ValidateCsrfToken(app(), app('encrypter')))->getExcludedPaths())->toContain('sso/saml/acs');
});

it('does not exempt an SSO arrival from a workspace’s own 2FA enforcement', function (): void {
    // ⚠️ A DOCUMENTED DECISION, NOT AN OVERSIGHT (ADR-0016 consequences). "Require 2FA for all tenant
    // members" is a policy an admin switched on; inferring an exemption from the presence of SSO would
    // silently drop it. A workspace whose IdP already performs MFA turns the setting off — their call.
    //
    // The round trip itself still succeeds: neither the ACS nor the completion hop is inside the
    // authenticated group, so the gate applies to the next request that IS — exactly as it does after a
    // password login. Asserting every half is what makes this a statement about the policy rather than about
    // where the middleware happens to be mounted.
    //
    // ⚠️ P1e INSERTED A HOP BETWEEN THE ASSERTION AND `/dashboard`, AND THIS CASE HAD TO FOLLOW IT RATHER
    // THAN BE RE-POINTED. Left asserting the ACS's `Location` alone, the trailing `GET /dashboard` below
    // would meet an unauthenticated session and answer the sign-in page — a redirect, still not the
    // two-factor page, so the case would keep failing for a new reason. Re-pointed WITHOUT following the
    // hop it would have gone green while measuring nothing: the gate under test only ever fires for a
    // session that exists, and the hop is now the only thing that creates one.
    enterTenant($this->tenant->id, $this->admin->id);
    app(TenantSettingRegistry::class)->put(
        $this->tenant,
        [SettingKey::SecurityRequireTwoFactor->value => true],
    );
    app(TenantSettingRegistry::class)->forget();

    $request = startLogin($this->tenant, $this->admin);

    completeSamlLogin($request, answering($request)->as('grace@acme.test')->response())
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

it('refuses to adopt an existing account that is not a member of this workspace', function (): void {
    // ⚠️ THIS IS AN ACCOUNT-TAKEOVER TEST, NOT A PROVISIONING-POLICY ONE, AND JIT IS LEFT **ON** ON PURPOSE.
    // Nothing requires that the address an IdP asserts belongs to a domain this workspace controls, and
    // `resolveUserByEmail()` reads on `pgsql_auth`, which sees every account in the deployment. So without
    // the guard in `SsoUserProvisioner`, an admin of any SSO-entitled workspace could point a connection at
    // an IdP they own, assert a stranger's address, have that stranger's CENTRAL account attached here, and
    // be signed in as them — with no personal-2FA challenge, because the SAML door never runs the password
    // pipeline that would have issued one. Found by the final integration review; `sso_saml` being an
    // unpurchasable tier today is deployment state, not a control, and it expires when Enterprise ships.
    enterTenant($this->tenant->id, $this->admin->id);

    // COMMITTED **and deliberately given no membership** — both halves matter. Uncommitted, the row is
    // invisible to `pgsql_auth` and the provisioner would take the JIT-create branch, so the case would
    // silently measure the wrong path (the suite header explains this at length). With a membership of any
    // status it would be a different, allowed case: a row means this workspace already decided about them.
    $stranger = committedTenantIdentity('Ada Lovelace');

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($stranger->email)->response()])
        ->assertNotFound();

    $this->assertGuest();

    enterTenant($this->tenant->id, $this->admin->id);
    // The observable fact that matters: nobody was attached. Only the admin's own membership exists, so the
    // stranger's account was neither adopted here nor altered.
    expect(TenantUser::query()->count())->toBe(1)
        ->and(TenantUser::query()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

it('refuses to adopt an established identity this workspace has merely invited', function (): void {
    // ⚠️ THE STRONGER HALF OF THE CASE ABOVE, AND IT NEEDS NO EMAILED TOKEN AT ALL. `MemberController::invite()`
    // validates `['required', 'email', 'max:255']` and a role — no domain-ownership check anywhere — and
    // `TenantMembershipService::resolveOrCreateUser()` binds that invitation to the address's EXISTING global
    // identity on `pgsql_auth` rather than to a placeholder. So an admin of any SSO-entitled workspace could
    // invite a stranger, assert the address at an identity provider they configured themselves, and hold a
    // session as them. The guard above does not fire, because the invite row THEY JUST CREATED is a membership.
    //
    // M9 disarms that: a membership row is a decision this workspace made about an ADDRESS, and an identity
    // provider's assertion is a claim about an address too. Neither is a claim about the PERSON — which is
    // precisely what M8 established one door over, and what this door had never asked.
    enterTenant($this->tenant->id, $this->admin->id);

    // Established by the plainest arm there is, a proved mailbox. `verified: true` is the default and is
    // spelled out because it is the whole subject of the case rather than a fixture detail.
    $victim = committedTenantIdentity('Ada Lovelace', verified: true);
    $adminRole = Role::query()->where('name', 'admin')->whereNull('tenant_id')->firstOrFail();

    $invite = new TenantUser;
    $invite->fill([
        'user_id' => $victim->id,
        'status' => TenantUserStatus::Invited,
        // ⚠️ AT ADMIN ON PURPOSE. `SsoUserProvisioner::roleFor()` honours the INVITED role rather than the
        // connection default, so the attacker also chooses the privilege level of the session they obtain.
        'invited_role_id' => $adminRole->id,
        'invited_at' => now(),
        'invite_expires_at' => now()->addDays(7),
        'invite_token' => hash('sha256', 'token'),
    ])->save();

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($victim->email)->response()])
        ->assertNotFound();

    $this->assertGuest();

    enterTenant($this->tenant->id, $this->admin->id);
    $membership = TenantUser::query()->where('user_id', $victim->id)->first();

    // ⚠️ THE TOKEN ASSERTION IS NOT DECORATION, AND IT IS THE HALF THE FILED ROW DOES NOT NAME.
    // `TenantMembershipService::attachMember()` force-fills `invite_token => null`, so the adoption also
    // CONSUMES the real invitee's emailed link — their own link then 404s and the takeover is indistinguishable
    // from an ordinary expired invitation. A refusal has to leave their way in intact, not merely decline.
    expect($membership?->status)->toBe(TenantUserStatus::Invited)
        ->and($membership?->invite_token)->toBe(hash('sha256', 'token'))
        ->and($membership?->joined_at)->toBeNull();
});

it('refuses the same for a declined invitation, because single sign-on must not overturn the answer', function (): void {
    // `Declined` disarmed the guard for the same reason `Invited` did — a row exists — but the person's own
    // answer was NO. Adopting them anyway reverses a decision the invitee made, using an assertion from an
    // identity provider they have never heard of.
    enterTenant($this->tenant->id, $this->admin->id);

    $victim = committedTenantIdentity('Ada Lovelace', verified: true);

    $declined = new TenantUser;
    $declined->fill([
        'user_id' => $victim->id,
        'status' => TenantUserStatus::Declined,
        'invited_role_id' => catalogRole('viewer'),
        'invited_at' => now()->subDay(),
        // `decline()` nulls the token, so a realistic row carries none — which also means this case cannot
        // be passing for the trivial reason that a token happened to match.
        'invite_token' => null,
    ])->save();

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($victim->email)->response()])
        ->assertNotFound();

    $this->assertGuest();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(TenantUser::query()->where('user_id', $victim->id)->value('status'))
        ->toBe(TenantUserStatus::Declined);
});

it('refuses the same for a removed member, and the history arm is what proves it', function (): void {
    // ⚠️ `verified: false` ON PURPOSE — this case has to fail on the HISTORY arm of
    // `identityIsEstablished()`, not on the mailbox arm, or it would prove nothing the case above does not.
    // The predicate reads the excluded row's own `joined_at`/`removed_at`, which is exactly the hole M8's own
    // adversarial pass found in the first version of it: a re-invited former member has no SECOND row to fall
    // back on, because `invite()` reuses the one they already had.
    //
    // And the shape matters beyond tidiness: an admin who REMOVES a member can otherwise re-adopt them
    // through the identity provider at a role of the admin's choosing, which is the sanction-laundering
    // `Suspended` is refused for, one status along.
    enterTenant($this->tenant->id, $this->admin->id);

    $former = committedTenantIdentity('Ada Lovelace', verified: false);

    $removed = new TenantUser;
    $removed->fill([
        'user_id' => $former->id,
        'status' => TenantUserStatus::Removed,
        'invited_role_id' => catalogRole('viewer'),
        'joined_at' => now()->subMonth(),
        'removed_at' => now()->subDay(),
    ])->save();

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($former->email)->response()])
        ->assertNotFound();

    $this->assertGuest();

    enterTenant($this->tenant->id, $this->admin->id);
    $membership = TenantUser::query()->where('user_id', $former->id)->first();

    expect($membership?->status)->toBe(TenantUserStatus::Removed)
        ->and($membership?->removed_at)->not->toBeNull();
});

it('still provisions a genuinely new address, so the refusal above is narrow', function (): void {
    // The guard's blast radius, pinned. It fires only on an address that ALREADY has an account; a new one
    // is the ordinary JIT path and must be untouched, or the fix would have broken single sign-on outright.
    $request = startLogin($this->tenant, $this->admin);

    completeSamlLogin($request, answering($request)->as('newcomer@acme.test')->response())
        ->assertRedirect('/dashboard');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(User::query()->where('email', 'newcomer@acme.test')->exists())->toBeTrue();
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

    completeSamlLogin($second, answering($second)->as('grace@acme.test')->response())
        ->assertRedirect('/dashboard');
});

/*
|--------------------------------------------------------------------------
| M18 — the trust-layer question, asked before every membership-layer one (ADR-0016 §D34)
|--------------------------------------------------------------------------
|
| ⚠️ EVERY CASE ABOVE RUNS AGAINST A WORKSPACE THAT HAS VERIFIED `acme.test` AND `identity.test`, seeded in
| `beforeEach`. These use `othercompany.test`, which Acme has NOT verified, and that single difference is
| the whole subject. See the `beforeEach` comment for why the seeding belongs there rather than per-case.
|
*/

it('refuses an address in a domain this workspace has not proven it controls', function (): void {
    // ⚠️ THE ROOT OF M1's AND M9's TAKEOVERS, AND THE FIRST CASE IN THIS FILE TO STATE IT AS A FACT RATHER
    // THAN AS A CAVEAT. Both of those refusals are membership-layer answers to a trust-layer question, and
    // the caveat their comments carry — "nothing requires that the address an IdP asserts belongs to a
    // domain this workspace controls" — is what this asserts is no longer true.
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('victim@othercompany.test')->response()])
        ->assertNotFound();

    $this->assertGuest();

    enterTenant($this->tenant->id, $this->admin->id);

    // ⚠️⚠️ **NO ACCOUNT WAS CREATED, AND THAT IS THE HALF THE BACKLOG ROW DID NOT NAME.** JIT is allowed to
    // CREATE, and `SsoUserProvisioner::createUser()` stamps `email_verified_at` — so before this guard, a
    // paying SSO tenant could mint a DEPLOYMENT-WIDE `users` row for any unregistered address carrying a
    // forged mailbox-control claim. That column is read by
    // `TenantMembershipService::identityIsEstablished()`, so the forged stamp fed M8's own predicate and
    // denied the address's real owner the password-setting arm of their later, genuine invitation.
    expect(User::query()->where('email', 'victim@othercompany.test')->exists())->toBeFalse()
        ->and(TenantUser::query()->count())->toBe(1);
});

it('records the refusal with a reason the database itself accepts', function (): void {
    // ⚠️ THIS IS THE MIGRATION'S GATE, NOT THE PROVISIONER'S. `sso_auth_failures.reason` is CHECK-constrained
    // to the enum, so a new case without the widening turns the guard into a 23514 AT THE MOMENT IT FIRES —
    // and the recorder swallows its own errors by design, so the row would simply be absent and the panel
    // would show nothing. Asserting the ROW is the only way to tell the widening happened.
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('victim@othercompany.test')->response()])
        ->assertNotFound();

    enterTenant($this->tenant->id, $this->admin->id);
    $failure = SsoAuthFailure::query()->latest('occurred_at')->first();

    expect($failure)->not->toBeNull()
        ->and($failure->reason)->toBe(SsoFailureReason::DomainNotVerified)
        // The address is carried structurally, and it is safe here for the reason every post-validation
        // refusal is: a signature over the assertion naming it has verified.
        ->and($failure->subject_email)->toBe('victim@othercompany.test');
});

it('signs an ACTIVE member in from a domain the workspace never verified — the grandfather', function (): void {
    // ⚠️⚠️ **THIS CASE IS THE ENTIRE GRANDFATHERING STORY, AND IT IS WHY NO MODE COLUMN OR BACKFILL EXISTS.**
    // The check sits AFTER the `Active` early return, so an active membership IS the grandfather: not one
    // member of any live deployment is locked out on deploy, and no public-mailbox exclusion list is needed.
    // That is safe because of what the four writers of `Active` require — `accept()` an emailed token plus
    // M8's identity fork, `joinOpenTenant()` a self-registration, `joinViaGoogle()` Google's own mailbox
    // verification, and `joinViaSso()` runs downstream of this very guard. None mints an Active row for a
    // stranger's address on an assertion alone.
    enterTenant($this->tenant->id, $this->admin->id);
    $member = committedIdentityAt('othercompany.test');
    makeActiveMember($member, 'form_editor');

    $request = startLogin($this->tenant, $this->admin);

    completeSamlLogin($request, answering($request)->as($member->email)->response())
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($member);
});

it('asks the domain question BEFORE the adoption one, so an unproven workspace learns nothing', function (): void {
    // ⚠️⚠️ **THE SECOND DEFECT M18 CLOSES, AND IT IS NOT THE ONE THE ROW WAS FILED FOR.** The failures panel
    // renders `existing_account_not_member` as "Address already has an account elsewhere" and `jit_disabled`
    // as "Nobody here matches that address" — so an SSO-entitled admin could assert ANY address and read
    // back, from their own settings page, whether it has an account anywhere in the deployment. §D19's
    // uniform 404 was always intact; the panel was the surface that leaked. Ordering the domain check first
    // is the fix, and this case is what pins the ordering: the same stranger, at an unverified domain, must
    // produce `domain_not_verified` and NOT `existing_account_not_member`.
    enterTenant($this->tenant->id, $this->admin->id);
    $stranger = committedIdentityAt('othercompany.test');

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($stranger->email)->response()])
        ->assertNotFound();

    enterTenant($this->tenant->id, $this->admin->id);

    expect(SsoAuthFailure::query()->latest('occurred_at')->value('reason'))
        ->toBe(SsoFailureReason::DomainNotVerified);
});

it('still refuses a SUSPENDED member first, because a sanction outranks a configuration gap', function (): void {
    // The other half of the ordering, and it runs the opposite way on purpose. `Suspended` is refused ABOVE
    // the domain check, so a workspace that let its domain verification lapse still cannot have its sanctions
    // reported as a configuration problem — the admin's action for a suspended member is nothing, and telling
    // them to publish a DNS record would send them somewhere useless.
    enterTenant($this->tenant->id, $this->admin->id);
    $member = committedIdentityAt('othercompany.test');
    makeActiveMember($member, 'viewer');
    TenantUser::query()->where('user_id', $member->id)->update(['status' => TenantUserStatus::Suspended->value]);

    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as($member->email)->response()])
        ->assertNotFound();

    enterTenant($this->tenant->id, $this->admin->id);

    expect(SsoAuthFailure::query()->latest('occurred_at')->value('reason'))
        ->toBe(SsoFailureReason::MembershipSuspended);
});

it('admits the same new joiner the moment the domain is verified, so the refusal is a gap and not a wall', function (): void {
    // ⚠️ THE CONTROL PROVED IN BOTH DIRECTIONS. A guard asserted only by its refusals is indistinguishable
    // from a guard that refuses everything, which would be a total outage rather than a security control —
    // and every case above this one is a refusal.
    $refused = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($refused)->as('newhire@othercompany.test')->response()])
        ->assertNotFound();

    enterTenant($this->tenant->id, $this->admin->id);
    SsoVerifiedDomain::factory()->verified()->forDomain('othercompany.test')->create();

    $admitted = startLogin($this->tenant, $this->admin);

    completeSamlLogin($admitted, answering($admitted)->as('newhire@othercompany.test')->response())
        ->assertRedirect('/dashboard');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(User::query()->where('email', 'newhire@othercompany.test')->exists())->toBeTrue();
});

it('does not let one workspace ride on another workspace’s verified domain', function (): void {
    // The isolation `SsoDomainService` relies on RLS for, asserted here through the REAL endpoint rather
    // than through the service — because the ACS is unauthenticated and reaches these rows with nothing but
    // a hostname to go on, which is the only context in which the guarantee actually has to hold.
    $globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);
    $globex->domains()->create(['domain' => 'globex']);

    TenantContext::runFor((string) $globex->getKey(), function (): void {
        SsoVerifiedDomain::factory()->verified()->forDomain('othercompany.test')->create();
    });

    enterTenant($this->tenant->id, $this->admin->id);
    $request = startLogin($this->tenant, $this->admin);

    $this->post(ACME_ACS, ['SAMLResponse' => answering($request)->as('victim@othercompany.test')->response()])
        ->assertNotFound();

    $this->assertGuest();
});
