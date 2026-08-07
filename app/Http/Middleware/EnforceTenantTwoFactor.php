<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\SettingKey;
use App\Services\Settings\TenantSettingRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Org-level 2FA enforcement — Increment I8a, PRD Feature #14's second acceptance criterion:
 * *"An org-level enforcement policy (a tenant Setting, Feature #10) lets an Owner/Admin require 2FA for
 * all tenant members; unenrolled members are prompted to complete enrolment before continuing."*
 *
 * Mounted on the AUTHENTICATED TENANT GROUP ONLY, and shaped after {@see EnsureSuperAdminMfa}: it checks
 * the ENROLLMENT FLAG (`two_factor_confirmed_at`), never a live TOTP. Fortify already challenges an
 * enrolled user at login, so re-challenging per request would be theatre; what this closes is the gap
 * where a workspace has decided everyone must enrol and someone simply has not.
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

        // Read LAST, so the common cases (guest, enrolled) cost nothing. The registry memoizes per tenant
        // per request, so the unenrolled path is one query at most regardless of how many times it is hit.
        if ($this->settings->get(SettingKey::SecurityRequireTwoFactor) !== true) {
            return $next($request);
        }

        return redirect()->route('two-factor.required');
    }
}
