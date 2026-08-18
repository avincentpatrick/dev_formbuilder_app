<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SsoFailureReason;
use App\Models\SsoAuthFailure;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sso\SsoAuthnRequestBuilder;
use App\Services\Sso\SsoMetadataParser;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Sso\FakeIdp;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The tenant-visible sign-in failure log (P1c, ADR-0016 §D26).
|
| §D19 accepted a cost in writing: "a real employee whose IdP clock has drifted sees a bare 404, and their
| admin has no in-app view of why". The ADR's revisit trigger named what closing it needs — a store that is
| NOT `audits`, because `audits` is append-only by RLS and never pruned while the ACS is unauthenticated,
| which together make an audit row per rejection an amplification primitive.
|
| ⚠️ SO THE CASES THAT MATTER MOST HERE ARE THE BOUNDS, NOT THE HAPPY PATH. A panel that records is easy;
| a panel an anonymous caller cannot fill is the whole design. Both limits are asserted, and both are
| enforced on the WRITE path rather than by a scheduled job — nothing runs the scheduler on the production
| box yet, so a nightly prune would be a bound that exists in this repository and not on the machine.
|
| ⚠️ AND THE ONE DISCLOSURE RULE: `subject_email` IS NULL FOR EVERY PRE-VALIDATION REFUSAL. An address only
| exists once a signature over the assertion carrying it has verified; recording one from a document that
| failed validation would let a stranger write chosen text into a tenant's database and onto an admin's
| screen. Asserted in both directions, because only one of them is visible from the happy path.
|
| ⚠️ The 404 posture is untouched and re-asserted alongside: nothing the panel knows reaches the caller.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = committedTenantIdentity('Failure Log Admin');
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    assignPlanTier(PlanTier::Enterprise);

    enterTenant($this->tenant->id, $this->admin->id);
    $this->connection = FakeIdp::connection();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

const FAILURE_LOG_HOST = 'http://acme.meridian.test';

const FAILURE_LOG_ACS = 'http://acme.meridian.test/sso/saml/acs';

const FAILURE_LOG_SP = 'http://acme.meridian.test/sso/saml/metadata';

/** Drive the real outbound leg; the row is identified from the redirect, never as "the newest one". */
function startFailureLogin(Tenant $tenant, User $actor): SsoAuthRequest
{
    $response = test()->get(FAILURE_LOG_HOST.'/sso/saml/login')->assertRedirect();

    $query = [];
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    $document = new DOMDocument;
    $document->loadXML(SsoAuthnRequestBuilder::decodeTransport((string) ($query['SAMLRequest'] ?? '')));

    enterTenant($tenant->id, $actor->id);

    return SsoAuthRequest::query()
        ->where('request_id', $document->documentElement?->getAttribute('ID') ?? '')
        ->firstOrFail();
}

/** The tenant's recorded failures, newest first, read back under tenant context. */
function recordedFailures(Tenant $tenant, User $actor)
{
    enterTenant($tenant->id, $actor->id);

    return SsoAuthFailure::query()->orderByDesc('occurred_at')->orderByDesc('id')->get();
}

/*
|--------------------------------------------------------------------------
| What gets recorded, and what deliberately does not
|--------------------------------------------------------------------------
*/

it('records a clock-drift refusal with the reason an admin can act on', function (): void {
    $request = startFailureLogin($this->tenant, $this->admin);

    // 120 seconds stale: inside php-saml's hard-coded 180 and outside our configured 60, so it is refused
    // by OUR second pass — the arm §D17 exists for, and the one an admin most needs explained.
    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->as($this->admin->email)
            ->conditionsStaleBy(120)
            ->response(),
    ])->assertNotFound();

    $failures = recordedFailures($this->tenant, $this->admin);

    expect($failures)->toHaveCount(1);
    expect($failures[0]->reason)->toBe(SsoFailureReason::AssertionOutsideConditions);
    expect((string) $failures[0]->sso_connection_id)->toBe((string) $this->connection->getKey());
    // The `InResponseTo` the document claimed, kept only because it is shaped like an id we mint — it is
    // what lets an admin line a panel row up against a log line.
    expect($failures[0]->request_id)->toBe($request->request_id);
});

it('records no address for a refusal that happened before any signature was verified', function (): void {
    $request = startFailureLogin($this->tenant, $this->admin);

    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->as('attacker@evil.test')
            ->signedByAnUntrustedKey()
            ->response(),
    ])->assertNotFound();

    $failures = recordedFailures($this->tenant, $this->admin);

    expect($failures)->toHaveCount(1);
    expect($failures[0]->reason)->toBe(SsoFailureReason::InvalidAssertion);
    // THE RULE. The document named an address; nothing had vouched for it, so it is not recorded and never
    // reaches an admin's screen.
    expect($failures[0]->subject_email)->toBeNull();
});

it('records the address once a verified signature has vouched for it', function (): void {
    $stranger = 'ada@acme.test';

    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::query()->update(['jit_provisioning_enabled' => false]);

    $request = startFailureLogin($this->tenant, $this->admin);

    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->as($stranger)
            ->response(),
    ])->assertNotFound();

    $failures = recordedFailures($this->tenant, $this->admin);

    expect($failures[0]->reason)->toBe(SsoFailureReason::JitDisabled);
    // The mirror image of the case above, and the reason the field exists: an invite-only workspace's admin
    // needs to know WHO tried, or the row tells them nothing they can act on.
    expect($failures[0]->subject_email)->toBe($stranger);
});

it('records the adoption refusal under its own reason, which the CHECK must accept', function (): void {
    // ⚠️ THIS TEST IS ALSO THE MIGRATION'S TEST. `sso_auth_failures.reason` is CHECK-constrained to
    // `SsoFailureReason::values()`, so M1's new case needs `2026_08_17_000104` to widen it; without that
    // migration the guard would raise a 23514 *while being recorded* — the refusal would throw on the one
    // endpoint anyone on the internet can post to. Asserting the row exists asserts the constraint accepts
    // the value, which no unit test over the enum could do.
    //
    // JIT stays ON: this refusal outranks the JIT toggle, because it is about whose address it is rather
    // than about whether this workspace provisions automatically.
    enterTenant($this->tenant->id, $this->admin->id);
    $stranger = committedTenantIdentity('Ada Lovelace');

    $request = startFailureLogin($this->tenant, $this->admin);

    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->as($stranger->email)
            ->response(),
    ])->assertNotFound();

    $failures = recordedFailures($this->tenant, $this->admin);

    expect($failures[0]->reason)->toBe(SsoFailureReason::ExistingAccountNotMember)
        // A verified signature vouched for the address, so the admin gets to see WHO tried — which on this
        // reason is the whole point: it is the one row that can mean somebody is probing for an account.
        ->and($failures[0]->subject_email)->toBe($stranger->email)
        ->and($failures[0]->reason->label())->not->toBe('')
        ->and($failures[0]->reason->hint())->not->toBe('');
});

it('records the trust-anchor refusal under its own reason, which the CHECK must accept', function (): void {
    // ⚠️ THIS TEST IS ALSO THE MIGRATION'S TEST — the third time this file has carried one, after M1's
    // `2026_08_17_000104` and K1b's `2026_08_17_000103`. `sso_auth_failures.reason` is CHECK-constrained to
    // `SsoFailureReason::values()`, so M2's new case needs `2026_08_17_000105` to widen it; without that
    // migration the guard would raise a 23514 *while being recorded*, turning the uniform 404 into a 500 on
    // the one endpoint anyone on the internet can post to — which is itself the §D4 disclosure the uniform
    // response exists to prevent. Asserting the row exists asserts the constraint accepts the value, which
    // no unit test over the enum could do.
    enterTenant($this->tenant->id, $this->admin->id);
    $expired = FakeIdp::certificate('expired');
    SsoConnection::query()->firstOrFail()->forceFill([
        'idp_certificates' => [$expired],
        'idp_certificates_fingerprint' => SsoMetadataParser::fingerprint([$expired]),
    ])->save();

    $request = startFailureLogin($this->tenant, $this->admin);

    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->signedByAnExpiredCertificate()
            ->as('grace@acme.test')
            ->response(),
    ])->assertNotFound();

    $failures = recordedFailures($this->tenant, $this->admin);

    expect($failures[0]->reason)->toBe(SsoFailureReason::IdpCertificateUnusable)
        // ⚠️ NULL, AND THE ASSERTION IS THE POINT RATHER THAN A DETAIL. The assertion DID carry a valid
        // signature and DID name an address — but this refusal fires before any of it is read, so nothing
        // has vouched for that address and it must not reach a tenant's database or an admin's screen.
        ->and($failures[0]->subject_email)->toBeNull()
        ->and($failures[0]->reason->label())->not->toBe('')
        ->and($failures[0]->reason->hint())->not->toBe('');

    // ⚠️⚠️ AND THE SAME RENDER CARRIES BOTH HALVES, WHICH IS THE WHOLE ROW IN ONE ASSERTION.
    // The defect M2 closes was not "expiry is unchecked" on its own — it was that `/settings/sso` said the
    // control was FAILING while the control was in fact ABSENT. So the page that shows the expired
    // certificate must now also show the refusal it actually caused, and its warning must say sign-in is
    // refused rather than reading as an errand. Asserting them on one response is what ties the surface an
    // admin consults to the behaviour they are consulting it about.
    $this->actingAs($this->admin)
        ->withoutVite()
        ->get(FAILURE_LOG_HOST.'/settings/sso')
        ->assertOk()
        // `false` disables Inertia's page-file-exists check — see the panel case below for why omitting it
        // is green here and red on CI.
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Sso', false)
            ->where('data.certificates_state', 'expired')
            ->where('failures.0.reason', SsoFailureReason::IdpCertificateUnusable->value)
            ->where('failures.0.reason_label', SsoFailureReason::IdpCertificateUnusable->label())
            ->where('failures.0.hint', SsoFailureReason::IdpCertificateUnusable->hint())
            ->where('failures.0.subject_email', null)
            ->where(
                'data.certificate_warning',
                fn (string $warning): bool => str_starts_with($warning, 'Sign-in is refused'),
            )
        );
});

it('records nothing at all for a successful sign-in', function (): void {
    $request = startFailureLogin($this->tenant, $this->admin);

    // The subject here is the RECORDER, not the session, so the assertion follows the hand-off only far
    // enough to prove the trip succeeded (P1e moved the sign-in one request later).
    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->as($this->admin->email)
            ->response(),
    ])->assertRedirect(FAILURE_LOG_HOST.'/sso/saml/login/complete/'.$request->request_id);

    expect(recordedFailures($this->tenant, $this->admin))->toHaveCount(0);
});

it('keeps a garbage request id out of the column rather than letting it suppress the row', function (): void {
    // ⚠️ THE SUPPRESSION PRIMITIVE THIS GUARDS. `request_id` is char(33) and the value comes from an
    // unvalidated document, so an over-long one would make the INSERT throw, the recorder swallow it, and
    // the panel stay empty for as long as somebody kept sending them. The row must still be written.
    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, str_repeat('A', 200)))
            ->as($this->admin->email)
            ->response(),
    ])->assertNotFound();

    $failures = recordedFailures($this->tenant, $this->admin);

    expect($failures)->toHaveCount(1);
    expect($failures[0]->reason)->toBe(SsoFailureReason::UnknownRequest);
    expect($failures[0]->request_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The bounds — the reason this table may exist at all
|--------------------------------------------------------------------------
*/

it('holds at most the configured number of rows however many arrive', function (): void {
    config(['saml.failure_log_max_rows' => 3]);

    // Six refusals of the cheapest kind, which is exactly the shape a grinder produces.
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->post(FAILURE_LOG_ACS, [
            'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, SsoAuthRequest::mintRequestId()))
                ->as($this->admin->email)
                ->response(),
        ])->assertNotFound();
    }

    // ⚠️ THE CAP IS "NOT AMONG THE NEWEST N", NOT "NEWER THAN THE Nth". All six of these land inside one
    // second, so a timestamp comparison would keep every tied row and the bound would not hold at exactly
    // the volume it exists for.
    expect(recordedFailures($this->tenant, $this->admin))->toHaveCount(3);
});

it('drops rows past the retention window even when the cap is nowhere near', function (): void {
    $request = startFailureLogin($this->tenant, $this->admin);

    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->as($this->admin->email)
            ->conditionsStaleBy(120)
            ->response(),
    ])->assertNotFound();

    expect(recordedFailures($this->tenant, $this->admin))->toHaveCount(1);

    // Data minimisation rather than housekeeping: these rows carry an IP address and sometimes an email,
    // so age alone is enough to remove them. The next write is what applies it.
    $this->travel((int) config('saml.failure_retention_days') + 1)->days();

    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, SsoAuthRequest::mintRequestId()))
            ->as($this->admin->email)
            ->response(),
    ])->assertNotFound();

    $failures = recordedFailures($this->tenant, $this->admin);

    expect($failures)->toHaveCount(1);
    expect($failures[0]->reason)->toBe(SsoFailureReason::UnknownRequest);
});

/*
|--------------------------------------------------------------------------
| Who may read it
|--------------------------------------------------------------------------
*/

it('shows the panel to an admin, with a label and something to do about it', function (): void {
    $request = startFailureLogin($this->tenant, $this->admin);

    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->as($this->admin->email)
            ->conditionsStaleBy(120)
            ->response(),
    ])->assertNotFound();

    $this->actingAs($this->admin)
        ->withoutVite()
        ->get(FAILURE_LOG_HOST.'/settings/sso')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // ⚠️ `false` IS THE SECOND ARGUMENT ON EVERY `component()` CALL IN THIS REPO, AND IT IS NOT
            // BOILERPLATE — IT IS A LINUX-ONLY FAILURE WAITING FOR WHOEVER OMITS IT. It disables Inertia's
            // "does this page file exist on disk?" check, which cannot succeed here: the package's default
            // page path is `resource_path('js/pages')` (LOWERCASE) while this repo's directory is
            // `resources/js/Pages`. Windows and macOS resolve that case-insensitively, so the check passes
            // locally and fails on CI — green here, red there, with an error ("page component file does not
            // exist") that reads as a missing file rather than a config mismatch. Nine existing call sites
            // pass `false`; none of them says why, which is how this cost an extra CI run.
            ->component('Settings/Sso', false)
            ->has('failures', 1)
            ->where('failures.0.reason', SsoFailureReason::AssertionOutsideConditions->value)
            ->where('failures.0.reason_label', SsoFailureReason::AssertionOutsideConditions->label())
            // The hint is what makes the panel worth building — a reason with no instruction sends an
            // admin hunting through a certificate they never touched.
            ->where('failures.0.hint', SsoFailureReason::AssertionOutsideConditions->hint())
        );
});

it('never lets one workspace read another’s failures', function (): void {
    $request = startFailureLogin($this->tenant, $this->admin);

    $this->post(FAILURE_LOG_ACS, [
        'SAMLResponse' => (new FakeIdp(FAILURE_LOG_ACS, FAILURE_LOG_SP, $request->request_id))
            ->as($this->admin->email)
            ->conditionsStaleBy(120)
            ->response(),
    ])->assertNotFound();

    // Strict RLS is the scoping, and this asserts it from the only angle that matters: a second workspace's
    // admin, on their own subdomain, with their own tenant context.
    $other = Tenant::create(['name' => 'Northwind', 'slug' => 'northwind', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'northwind']);

    $otherAdmin = committedTenantIdentity('Northwind Admin');
    enterTenant($other->id, $otherAdmin->id);
    makeActiveMember($otherAdmin, 'admin');
    assignPlanTier(PlanTier::Enterprise);
    enterTenant($other->id, $otherAdmin->id);
    FakeIdp::connection();

    $this->actingAs($otherAdmin)
        ->withoutVite()
        ->get('http://northwind.meridian.test/settings/sso')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('failures', 0));
});
