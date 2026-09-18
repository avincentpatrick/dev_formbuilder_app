<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Tenant\Sso\SsoStepUpController;
use App\Services\Sso\SsoGate;
use App\Support\Sso\SsoSession;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

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
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ── ⚠️ P1c: THIS GATE FORKS ON IDENTITY SOURCE. IT DOES NOT GRANT AN EXEMPTION. ────────────────────────
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * P1b made a latent hole reachable. A JIT-provisioned member's password is `Hash::make(Str::random(64))`,
 * discarded by the only process that ever held it, so `auth.password_confirmed_at` had exactly one writer
 * and that writer asks for a string nobody can produce. Every route below was therefore a dead end for the
 * people SSO exists to serve — including `PUT /settings/sso/idp-metadata`, i.e. the surface an admin would
 * use to fix SSO.
 *
 * An exemption ("SSO sessions skip step-up") would have been half a line and would have said the opposite
 * of what the gate is for. So the *mechanism* forks and the *policy* does not: an SSO-established session
 * re-proves itself at its identity provider, with `ForceAuthn`, via {@see SsoStepUpController}. The window,
 * the routes and the requirement are untouched.
 *
 * THREE PROPERTIES OF THE FORK, each load-bearing:
 *   1. **It is keyed on the SESSION, not the account** — {@see SsoSession} argues why at length. A member
 *      who holds a real password and signed in with it keeps the password prompt even in an SSO workspace.
 *   2. **It falls back to `password.confirm` whenever SSO cannot serve** — no connection, draft, disabled,
 *      or downgraded off Enterprise. That fallback IS the lockout escape hatch: `/user/confirm-password`
 *      stays routed on the tenant subdomain, and a member with no usable password reaches it through
 *      password reset, which `SsoUserProvisioner` documents as the reason the account is a real one.
 *   3. **The gate query runs only when a redirect is about to happen.** `shouldConfirmPassword()` is asked
 *      first, so the overwhelmingly common case — a confirmed session passing through — costs nothing, and
 *      the super-admin console (central host, no tenant GUC, no SSO marker) never reaches the query at all.
 */
final class RequireRecentPassword extends RequirePassword
{
    /**
     * The parent's three constructor arguments are re-declared and forwarded verbatim — Laravel resolves
     * middleware through the container, so `$sso` is injected alongside them. Dropping any of the three
     * would leave `$this->urlGenerator` null and break the password arm this class still delegates to.
     */
    public function __construct(
        ResponseFactory $responseFactory,
        UrlGenerator $urlGenerator,
        private readonly SsoGate $sso,
        $passwordTimeout = null,
    ) {
        parent::__construct($responseFactory, $urlGenerator, $passwordTimeout);
    }

    /**
     * Refuse unless this session confirmed its password within `auth.step_up_timeout` seconds — or, for an
     * SSO-established session, unless it re-authenticated at its identity provider within the same window.
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
        // Cast the whole expression, not just the config read. A caller-supplied window arrives from a route
        // string (`step-up:,60`) and is therefore `string|int`, which
        // {@see RequirePassword::shouldConfirmPassword()} does not accept — and the parent only ever passed
        // it straight through, so nothing had needed to narrow it until this class started asking the
        // question itself.
        $timeout = (int) ($passwordTimeoutSeconds ?? config('auth.step_up_timeout', 900));

        // An explicit `$redirectToRoute` is a caller overriding the destination; honour it rather than
        // silently sending them somewhere else. `expectsJson()` is excluded because the parent answers those
        // with a 423 and no redirect at all — there is nothing to fork.
        // ⛔ THE PAYLOAD IS ABOUT TO BE DROPPED, AND UNTIL NOW NOTHING SAID SO. `Redirector::guest()`
        //    records `previous()` rather than the request for any NON-GET, so the ten writes behind this
        //    gate bounce to a GET of the page they came from, re-rendered from the database, with the
        //    body gone and no message. On the tenant side it is worse than a lapse: `password_confirmed_at`
        //    has one non-SSO writer — Fortify's own controller — and signing in never stamps it, while
        //    `GET /members` is deliberately ungated, so a workspace Owner loses their FIRST role change of
        //    every session, not merely one made fifteen minutes after opening the page.
        //
        // ⚠️ NON-GET ONLY, AND THE TEST IS NOT DECORATION. A gated GET is recorded as its own intended URL
        //    and loses nothing, so announcing a discarded write there would be a lie on every console page
        //    load. `expectsJson()` is excluded for the same reason the fork below is: the parent answers
        //    those with a 423 and no redirect, so there is no bounce to explain.
        $confirming = ! $request->expectsJson() && $this->shouldConfirmPassword($request, $timeout);

        if ($confirming && ! $request->isMethod('GET')) {
            $this->rememberDiscardedWrite($request);
        }

        if ($redirectToRoute === null && $confirming) {
            $ssoStepUp = $this->ssoStepUpUrl($request);

            if ($ssoStepUp !== null) {
                // `Redirect::guest()`, exactly as the parent uses it, so `url.intended` is written the same
                // way and `SsoStepUpController` can read the destination the member was heading for.
                return Redirect::guest($ssoStepUp);
            }
        }

        return parent::handle($request, $next, $redirectToRoute, $timeout);
    }

    /**
     * Where to send an SSO-established session to re-prove itself, or null to use the password prompt.
     *
     * ⚠️ THE SESSION CHECK COMES FIRST AND SHORT-CIRCUITS BEFORE ANY QUERY. `RequireRecentPassword` also
     * guards the super-admin console on the CENTRAL host, where there is no tenant GUC — an unguarded
     * `SsoConnection::query()` there would be a pointless round trip on every operator's step-up.
     *
     * ⚠️ THE HOST COMES FROM THE REQUEST, NOT FROM `route()` AND NOT FROM {@see TenantUrl::to()}. The tenant
     * route groups declare no `->domain()`, so the named-route generator emits `APP_URL` — the central host
     * — and the member would arrive with neither tenant context nor a matching cookie. `TenantUrl::to()`
     * would compose the right host, but the REQUEST's host is the stronger answer for a redirect whose whole
     * purpose is to keep the browser on the origin holding the session cookie: it is same-origin by
     * construction rather than by agreeing with a second derivation. (The two are equal today — ADR-0012
     * §D1 confines custom hosts to the public guest runtime, so an authenticated page is always on the
     * canonical subdomain.)
     */
    private function ssoStepUpUrl(Request $request): ?string
    {
        if (! SsoSession::isSsoSession($request->session())) {
            return null;
        }

        if (! TenantContext::hasTenant() || $this->sso->activeConnection() === null) {
            return null;
        }

        return $request->getSchemeAndHttpHost().'/sso/saml/step-up';
    }

    /**
     * Record that a write was discarded by this gate, so the member can be told.
     *
     * ⛔ `put()`, NEVER `flash()`, AND THAT IS THE WHOLE DESIGN. Delivering this costs FOUR requests —
     * the bounced write, the GET of the confirmation page, the POST that confirms, then the redirect to
     * `url.intended` — and a flash is aged out after the second. The row's own remedy said "flash a
     * warning on the confirm page and after it" and could not have worked: it would have rendered on the
     * confirm page and then vanished before the page the member actually came from. This key is put, read
     * twice, and forgotten by {@see HandleInertiaRequests} once it has been shown somewhere other than
     * the confirmation page itself.
     *
     * ⚠️ WHAT IS STORED IS A DESCRIPTION, NEVER THE PAYLOAD. Replaying the request is not on the table —
     * these ten routes carry `can:` gates, a `feature:sso_saml` gate, FormRequest validation and
     * route-model binding, and a replay that skipped any of them would turn a security gate into a
     * confused deputy — so storing the body would be keeping credentials-adjacent form data in the
     * session for no reachable purpose. The method and the URL are what a human needs to redo it.
     */
    protected function rememberDiscardedWrite(Request $request): void
    {
        $request->session()->put('step_up.discarded', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
        ]);
    }
}
