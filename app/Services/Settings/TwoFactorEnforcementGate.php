<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\SettingKey;
use App\Http\Middleware\EnforceTenantTwoFactor;
use App\Http\Middleware\EnforceTenantTwoFactorOnFortify;
use App\Support\Audit\ImpersonationContext;
use App\Support\Tenancy\PlatformHost;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Must this request enrol a second factor before it proceeds (Increment I8a, extracted in M68)?
 *
 * ── ONE gate, TWO consumers, so they cannot disagree ───────────────────────────────────────────────────
 * {@see EnforceTenantTwoFactor} guards the authenticated tenant group and the `/api/v1` token mint;
 * {@see EnforceTenantTwoFactorOnFortify} guards two routes of the Fortify group. Extracted here rather
 * than copied for the reason {@see RegistrationGate} states about the same pair of surfaces: a security
 * policy written down twice is a policy with two answers. There are four parts to get wrong — the
 * enrolment-flag check, the impersonation exemption, which tenant is asked, and the shape of the refusal —
 * and only the third differs between the callers.
 *
 * ── ⛔ THE THIRD PART IS WHY THIS CLASS EXISTS, AND IT IS THE THING THE BACKLOG ROW DID NOT KNOW ────────
 * The row that produced `M68`'s Fortify gate reads as though the fix were a mount. It is not, and mounting
 * `EnforceTenantTwoFactor` on `config/fortify.php` would have shipped a gate that CANNOT EVER FIRE —
 * measured, by writing exactly that and watching three behavioural cases fail.
 *
 * The Fortify group carries no tenancy middleware at all. `EstablishTenantDatabaseContext` resolves the
 * tenant out of the container binding stancl's identification middleware makes, so on that group it
 * resolves **null** and applies `(null, userId)`. {@see TenantSettingRegistry::all()} returns `[]` with no
 * ambient tenant, so `security.require_two_factor` reads as its sparse default — `false` — for every
 * workspace, forever. The gate would be green, mounted, and blind.
 *
 * ⚠️ AND FAILING THAT WAY IS NOT AN ACCIDENT OF THIS KEY. Under `settings`'s nullable_global SELECT policy
 * a tenant's own rows are INVISIBLE rather than absent without its context, so the fallback is silent by
 * construction. `RegistrationGate` hit the identical wall on `/register` and
 * {@see TenantSettingRegistry::forTenant()} exists because of it. This class reuses that answer:
 * {@see PlatformHost::tenantFor()} resolves the workspace from the HOST, and the read goes through
 * `getFor()`.
 *
 * ── WHICH TENANT IS THE SUBJECT ───────────────────────────────────────────────────────────────────────
 * The host, not the membership. A person may belong to several workspaces and the policy is a property of
 * ONE of them: a request to `acme.meridian.test/user/password` is governed by acme's switch and by nothing
 * else. On the CENTRAL host there is no workspace to have an opinion, and an account created there belongs
 * to none yet — the pre-existing shape of Fortify registration in this product — so the answer there is
 * "no", exactly as `RegistrationGate` answers it.
 */
final class TwoFactorEnforcementGate
{
    public function __construct(private readonly TenantSettingRegistry $settings) {}

    /**
     * For a surface that HAS tenant context: the ambient workspace's switch decides.
     *
     * The read is last, so the common cases (guest, enrolled) cost nothing, and the registry memoizes per
     * tenant per request.
     */
    public function blocksAmbient(Request $request): bool
    {
        return $this->actorNeedsEnrolment($request)
            && $this->settings->get(SettingKey::SecurityRequireTwoFactor) === true;
    }

    /**
     * For a surface with NO tenant context: the workspace the HOST addresses decides.
     *
     * ⛔ Not interchangeable with {@see blocksAmbient()} — see this class's docblock. Calling that one from
     * the Fortify group answers `false` for every workspace in the deployment.
     */
    public function blocksForHost(Request $request): bool
    {
        if (! $this->actorNeedsEnrolment($request)) {
            return false;
        }

        $tenant = PlatformHost::tenantFor($request->getHost());

        if ($tenant === null) {
            return false;
        }

        return $this->settings->getFor($tenant, SettingKey::SecurityRequireTwoFactor) === true;
    }

    /**
     * The refusal, in a shape the caller can act on.
     *
     * M66 — mirrors {@see EnsureVerifiedEmail}, which has carried this arm since J3a. A redirect is an
     * instruction to go and do something; a JSON client cannot go anywhere, so it gets the refusal instead
     * of markup it will try to parse. On `/api/v1/*` `bootstrap/app.php` turns this into the documented
     * `forbidden` envelope, and everywhere else into a plain 403.
     */
    public function refuse(Request $request): Response
    {
        if ($request->expectsJson()) {
            throw new AccessDeniedHttpException('This workspace requires two-factor authentication, and this account has not enrolled.');
        }

        return redirect()->route('two-factor.required');
    }

    /**
     * Is there an actor here who has not enrolled and is not exempt?
     *
     * ⚠️ AN IMPERSONATED SESSION IS EXEMPT, AND THIS ONE EXEMPTION IS NOT STRUCTURAL BECAUSE IT CANNOT BE
     * (I11b). Every other carve-out is a route outside a group; this one depends on SESSION STATE rather
     * than on which URL was asked for, so a route list could not express it.
     *
     * Without it the feature is unusable in exactly the workspaces most likely to need support: the
     * operator lands on /dashboard, the gate sends them to the enrolment interstitial, and the only action
     * available there is to ENROL A SECOND FACTOR ON SOMEBODY ELSE'S ACCOUNT — a credential the operator
     * would hold and the member would not know existed. Bouncing them is not the safe failure it looks
     * like. The authority being trusted is not the impersonated member's; it is the console stack the grant
     * came through (`superadmin` + `superadmin.mfa` + `step-up`). The policy this gate enforces — "this
     * workspace requires its MEMBERS to enrol" — has no opinion about platform staff, who are not members.
     * {@see ImpersonationContext} is the reader rather than a bare session key so the guard cannot drift
     * from the writer, and it is uuid-validated on the way in.
     */
    private function actorNeedsEnrolment(Request $request): bool
    {
        $user = $request->user();

        if ($user === null || $user->two_factor_confirmed_at !== null) {
            return false;
        }

        return ImpersonationContext::operatorId() === null;
    }
}
