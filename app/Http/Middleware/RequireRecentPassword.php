<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

/**
 * Step-up re-authentication for high-blast-radius actions — Increment I8a, PRD Feature #14.
 *
 * PRD Feature #14: *"Step-up re-authentication gates high-blast-radius actions (ownership transfer, role
 * changes, billing changes, and the super-admin console) — a recent credential/2FA confirmation is
 * required, via Laravel's `password.confirm` mechanism, not just a live session."* This is that
 * mechanism, narrowed. Aliased `step-up` in bootstrap/app.php.
 *
 * ── Why a subclass rather than `password.confirm:,900` at each call site ────────────────────────────────
 * The framework's middleware takes the window as a route parameter, so the narrower policy COULD be
 * spelled inline. Three reasons it is not:
 *   · A route string is where the number would drift. `password.confirm:,900` on four route groups is
 *     four places to change and four places to forget; here the policy has one home and one name.
 *   · `route:cache` serialises the resolved middleware string, so a config-derived parameter would be
 *     BAKED IN at cache time — changing `AUTH_STEP_UP_TIMEOUT` in production would then silently do
 *     nothing until someone re-ran `route:cache`. Reading config inside `handle()` cannot go stale.
 *   · `step-up` reads as a policy at the call site; `password.confirm:,900` reads as a magic number.
 *
 * ── ⚠️ NEVER PUT THIS ON A ROUTE A JSON SIDECAR CALLS ───────────────────────────────────────────────────
 * {@see RequirePassword::handle()} forks on `expectsJson()`: a browser navigation is redirected to the
 * confirm-password page, but anything sent with `Accept: application/json` gets a bare **423 with a JSON
 * body** that a `fetch` will happily hand to `res.json()`. That is precisely the live defect I8a fixed in
 * `TwoFactorSetup.vue`, and it is one careless mount away from returning. Every route this guards is an
 * Inertia visit (`Accept: text/html`), which takes the redirect arm; the app's own JSON sidecars
 * (`/notifications`, `/scopes/{n}/impact`, the connector channel lists) must stay ungated.
 *
 * ── ⚠️ ORDER ON THE SUPER-ADMIN CONSOLE IS LOAD-BEARING ────────────────────────────────────────────────
 * `auth → superadmin → superadmin.mfa → step-up`. This must sit INSIDE the `superadmin.mfa` group, never
 * ahead of it: an un-enrolled operator would otherwise be made to confirm a password and then bounced
 * straight to enrolment, having gained nothing. `GET /admin/two-factor` stays outside both, which is the
 * same anti-loop carve-out {@see EnsureSuperAdminMfa} already depends on.
 */
final class RequireRecentPassword extends RequirePassword
{
    /**
     * Refuse unless this session confirmed its password within `auth.step_up_timeout` seconds.
     *
     * The parent's own `$passwordTimeoutSeconds` parameter is deliberately still honoured when a caller
     * passes one, so `step-up:,60` remains possible for a future action that warrants an even tighter
     * window without needing a third class.
     *
     * Signature and return type mirror the parent's untyped ones on purpose — narrowing either would make
     * this class disagree with the contract Laravel's pipeline actually calls.
     *
     * @param  Request  $request
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     * @return mixed
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        return parent::handle(
            $request,
            $next,
            $redirectToRoute,
            $passwordTimeoutSeconds ?? (int) config('auth.step_up_timeout', 900),
        );
    }
}
