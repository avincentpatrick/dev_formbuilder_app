<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\SettingKey;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Audit\ImpersonationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Org-level 2FA enforcement — Increment I8a, PRD Feature #14's second acceptance criterion:
 * *"An org-level enforcement policy (a tenant Setting, Feature #10) lets an Owner/Admin require 2FA for
 * all tenant members; unenrolled members are prompted to complete enrolment before continuing."*
 *
 * Mounted on the AUTHENTICATED TENANT GROUP ONLY, and shaped after {@see EnsureSuperAdminMfa}: it checks
 * the ENROLLMENT FLAG (`two_factor_confirmed_at`), never a live TOTP. What it closes is the gap where a
 * workspace has decided everyone must enrol and someone simply has not; re-challenging per request would
 * be theatre on the doors that already challenge.
 *
 * ⚠️ AND "the doors that already challenge" IS NOT ALL OF THEM, WHICH IS WHAT THIS PARAGRAPH USED TO GET
 * WRONG. It read "Fortify already challenges an enrolled user at login" — false for the SSO door, which
 * is one this gate stands behind. The password form challenges, and so does
 * {@see App\Services\Auth\GoogleSessionStarter} by ADR-0019 §D11; a SAML sign-in deliberately does NOT
 * (ADR-0016 §D32 — the identity provider is the authentication authority at that door). So in a
 * workspace with `security.require_two_factor` on, an ENROLLED member arriving through SAML clears this
 * gate on the flag alone and never presents the factor.
 * That is the residual §D32 records rather than a defect here: enrolment is precisely what this middleware
 * checks, and the escape hatch below is why it can only ever be a nudge.
 *
 * ── ⚠️ THE ESCAPE HATCH IS THE WHOLE DESIGN ────────────────────────────────────────────────────────────
 * A gate that redirects everywhere redirects to itself. `GET /two-factor/required` is registered OUTSIDE
 * this middleware's group, which is the same carve-out `admin.mfa.setup` has carried since B2c and for the
 * same reason. Keeping the exemption STRUCTURAL — a route outside the group — rather than a path allow-list
 * inside this class matters: an allow-list has to name `/settings` too (2FA also lives there), and then
 * every future settings sub-route silently inherits an exemption nobody decided to grant. One route out,
 * and it is visible in `routes/tenant.php` rather than buried here.
 *
 * The interstitial also offers sign-out, because "enrol or leave" must have two doors. `POST /logout` is a
 * Fortify route in its own group, so it is naturally outside this gate — do not "tidy" it inside.
 *
 * ── ⚠️ WHERE THIS MUST NOT BE MOUNTED ──────────────────────────────────────────────────────────────────
 *  · the guest/public runtime group — there is no user, and a respondent filling in a public form is not
 *    a member of anything. The null-user early return below is belt to that braces.
 *  · the central `/admin/*` group — {@see EnsureSuperAdminMfa} already rules there, on a flag that is
 *    unconditional rather than per-tenant, and the console runs with NO tenant context, so the settings
 *    read would fail closed and redirect an operator to a tenant route that does not exist centrally.
 *  · the invitation-acceptance group — someone who has not joined yet cannot be bound by the policy of a
 *    workspace they are still deciding whether to enter.
 *
 * ── THE JSON SIDECARS ARE FINE, AND THAT WAS CHECKED RATHER THAN ASSUMED ──────────────────────────────
 * I4's `GET /notifications` poll sits inside the guarded group, so a stale tab belonging to a member who
 * has just been placed under enforcement receives this 302 and follows it to HTML. `notificationsClient`
 * already swallows every error for exactly this shape of failure — its own comment names "a tenant that
 * cannot be identified produces a 302 that builderClient then throws a SyntaxError on" — so the bell holds
 * its last known feed instead of raising an unhandled rejection every sixty seconds. No exemption needed,
 * and none should be added: an exemption would serve notification content to someone being told to enroll.
 *
 * ── ORDER ─────────────────────────────────────────────────────────────────────────────────────────────
 * After `auth` and after {@see EstablishTenantDatabaseContext} (the settings read is tenant-scoped and
 * needs the RLS GUC). Both hold naturally: this is named last in the group's array and is absent from
 * bootstrap/app.php's priority list, so it stays where it is written.
 */
final class EnforceTenantTwoFactor
{
    public function __construct(private readonly TenantSettingRegistry $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        // ⚠️ AN IMPERSONATED SESSION IS EXEMPT, AND THIS ONE EXEMPTION IS NOT STRUCTURAL BECAUSE IT CANNOT
        // BE (I11b). Every other carve-out in this file is a route outside the group; this one depends on
        // SESSION STATE, not on which URL was asked for, so a route list could not express it.
        //
        // Without it the feature is unusable in exactly the workspaces most likely to need support: the
        // operator lands on /dashboard, this redirects them to the enrollment interstitial, and the only
        // action available there is to ENROL A SECOND FACTOR ON SOMEBODY ELSE'S ACCOUNT — a credential the
        // operator would hold and the member would not know existed. Bouncing them is not the safe failure
        // it looks like.
        //
        // The authority being trusted is not the impersonated member's. It is the console stack the grant
        // came through: `superadmin` + `superadmin.mfa` (the operator's OWN confirmed 2FA, unconditional
        // rather than per-tenant) + `step-up` (a password confirmation in the last 15 minutes). The policy
        // this gate enforces — "this workspace requires its MEMBERS to enrol" — has no opinion about
        // platform staff, who are not members. `ImpersonationContext` is the reader rather than a bare
        // session key so the guard cannot drift from the writer, and it is uuid-validated on the way in.
        if (ImpersonationContext::operatorId() !== null) {
            return $next($request);
        }

        // Read LAST, so the common cases (guest, enrolled) cost nothing. The registry memoizes per tenant
        // per request, so the unenrolled path is one query at most regardless of how many times it is hit.
        if ($this->settings->get(SettingKey::SecurityRequireTwoFactor) !== true) {
            return $next($request);
        }

        return redirect()->route('two-factor.required');
    }
}
