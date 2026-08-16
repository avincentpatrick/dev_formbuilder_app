<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Sso;

use App\Enums\SsoAuthIntent;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\ImpersonationSessionController;
use App\Http\Middleware\EnforceTenantTwoFactor;
use App\Models\SsoAuthRequest;
use App\Models\Tenant;
use App\Services\Sso\SsoAssertionValidator;
use App\Services\Sso\SsoAuthenticationException;
use App\Services\Sso\SsoAuthFailureRecorder;
use App\Services\Sso\SsoGate;
use App\Services\Sso\SsoLoginService;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The Assertion Consumer Service — where an identity provider's signed assertion is validated and written
 * down (Phase 4, P1b; it stopped creating the session in P1e — ADR-0016 §D27, §D29).
 *
 * ── ⚠️ EVERY FAILURE IS THE SAME 404, AND THE COST IS STATED RATHER THAN HIDDEN ──────────────────────
 * §D4 fixed this posture for the protocol surface and it extends to the whole of this endpoint. An ACS
 * that explains why an assertion failed is an oracle for anyone tuning a forgery: "wrong audience" says
 * the signature verified, "already consumed" says the request id was real. So unknown request, stale
 * assertion, bad signature, suspended member and exhausted seat quota are one indistinguishable response —
 * the {@see ImpersonationSessionController} posture.
 *
 * **The cost:** a real employee whose identity provider's clock has drifted sees a bare 404, and their
 * admin has no in-app view of why. The reason is written to the log with a stable machine token, and a
 * tenant-facing "recent sign-in failures" panel is owed work rather than an oversight.
 *
 * ⚠️ THE FAILURES ARE LOGGED, NOT AUDITED. `audits` is append-only by RLS policy and never pruned; an
 * unauthenticated endpoint that wrote to it on every rejection would be an amplification primitive. The
 * SUCCESS path does leave a ledger row, but only because provisioning genuinely changes a model —
 * `tenant_users` with `via => 'sso_jit'`.
 *
 * ── UNAUTHENTICATED, CSRF-EXEMPT, AND NEITHER IS A GAP ──────────────────────────────────────────────
 * The caller is an identity provider posting cross-origin. There is no session to require — and since P1e
 * none to create either — and no CSRF token to present. ⚠️ Nor, since P1e, is there a CSRF middleware to
 * be exempt FROM: this route left the stateful stack, so `bootstrap/app.php`'s `except` entry is belt and
 * braces held for the day somebody moves it back, rather than the thing standing between here and a 419.
 *
 * What replaces the token is stronger: the assertion must be signed by the tenant's own trust anchor AND
 * name a live, unconsumed `sso_auth_requests` row this SP minted.
 *
 * ── ⚠️ "THERE IS NO SESSION" IS NOW LITERAL IN BOTH DIRECTIONS (P1c, then P1e) ──────────────────────
 * `config/session.php` sets `same_site` to `lax`, and a cross-site top-level POST does not carry a Lax
 * cookie. So this request never sees the session the browser already has. `Auth::id()` here is null even when
 * the person is signed in two tabs away, so the third of {@see SsoAuthIntent}'s three conditions cannot be
 * evaluated at this endpoint at all — which is why the step-up arm marks the row and hands off to a same-site
 * GET instead of finishing here, and why it must NOT call `Auth::login()`: doing so would replace the
 * member's real session with a fresh one, orphaning whatever it held, in the name of confirming identity.
 *
 * P1b called that "invisible for a login". It was not, and the sentence was hiding two defects at once.
 * **(1)** Creating the session here binds it to whichever browser POSTed, and nothing said that had to be the
 * browser that STARTED the flow — so an attacker with an account at the tenant's own directory could obtain a
 * real assertion, withhold the identity provider's auto-POST form, and have a victim's browser complete the
 * attacker's sign-in. The login arm now hands off exactly as the step-up arm does, and
 * {@see SsoLoginCompletionController} makes the comparison on the one leg that carries a cookie.
 * **(2)** This endpoint did not merely fail to READ the browser's session — inside `web` it silently
 * REPLACED it, because `StartSession` mints a fresh id when no cookie arrives and emits it unconditionally.
 * The route group's comment carries the measurement. This route is no longer stateful, so both are closed.
 *
 * ⚠️ THE TENANT COMES FROM THE HOST, NEVER FROM THE BODY. An attacker choosing which workspace to address
 * chooses only which public subdomain to visit; the assertion's audience is then checked against THAT
 * tenant's SP entity id, so a response minted for another tenant dies on the audience check before any
 * replay ledger is consulted.
 *
 * ── WHAT THIS CONTROLLER DELIBERATELY DOES NOT DECIDE ───────────────────────────────────────────────
 * Org-level 2FA is NOT exempted here. {@see EnforceTenantTwoFactor} guards the authenticated group, so a
 * workspace that requires enrolment will send an SSO arrival to the enrolment interstitial exactly as it
 * sends a password arrival — which is the honest reading of "require 2FA for all tenant members". A tenant
 * whose IdP already performs MFA can turn the setting off; that is a policy they own, and inferring it from
 * the presence of SSO would silently drop a control an admin switched on.
 */
final class SsoAcsController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly SsoGate $gate,
        private readonly SsoLoginService $logins,
        private readonly SsoAssertionValidator $assertions,
        private readonly SsoAuthFailureRecorder $failures,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $connection = $this->gate->activeConnectionOrAbort();
        $tenant = $this->currentTenant();

        try {
            $outcome = $this->logins->consumeAssertion(
                $tenant,
                $connection,
                // `input()` rather than `validate()`: a validation failure would answer 302-with-errors,
                // which is a different response from the 404 every other refusal produces and would
                // therefore be a disclosure in itself.
                (string) $request->input('SAMLResponse', ''),
            );
        } catch (SsoAuthenticationException $exception) {
            Log::warning('sso.acs.rejected', [
                'reason' => $exception->reason->value,
                'detail' => $exception->getMessage(),
                'tenant_id' => (string) $tenant->getKey(),
                'connection_id' => (string) $connection->getKey(),
                'ip' => $request->ip(),
            ]);

            // The tenant's own half of the same event (P1c). Beside the log line rather than instead of it:
            // the log is the operator's surface and holds everything, this is the admin's and holds what
            // they can act on. It cannot change the response — see the recorder's docblock.
            $this->failures->record($connection, $exception, $request->ip(), $this->claimedRequestId($request));

            abort(404);
        }

        return $this->handOff($tenant, $outcome->request);
    }

    /**
     * The `InResponseTo` the document CLAIMED, when it is shaped like an id we could have minted.
     *
     * For the panel only, and it is the one field there that comes from unvalidated input — which is why it
     * passes {@see SsoAuthRequest::isMintedShape()} first and is null the moment it does not. A malformed
     * document has no id to report and must not be able to make the recorder throw.
     */
    private function claimedRequestId(Request $request): ?string
    {
        try {
            $claimed = $this->assertions->inResponseTo((string) $request->input('SAMLResponse', ''));
        } catch (SsoAuthenticationException) {
            return null;
        }

        return SsoAuthRequest::isMintedShape($claimed) ? $claimed : null;
    }

    /**
     * Both arms end the same way: mark the row, hand the browser back to a same-site GET on this workspace's
     * own host, and finish nothing here.
     *
     * ⚠️ NO `Auth::login()`, NO SESSION WRITE, NO `last_login_at` — ON EITHER ARM SINCE P1e. Each absence is
     * a decision, and P1c had already taken all three for the step-up:
     *   · logging in on the step-up arm would replace the session the step-up is being performed FOR; on the
     *     LOGIN arm it would create a session in whichever browser posted, which is precisely the defect P1e
     *     exists to close — `InResponseTo` proves this SP minted the flow and says nothing about who holds it;
     *   · there is no session here to write into anyway. This request carries no cookie and, since P1e, starts
     *     no session at all — see the route group's comment for the clobber that discovery cost;
     *   · `last_login_at` is what an admin reads to answer "is sign-in working". A verified assertion whose
     *     browser never came back is not a sign-in, and letting it refresh that column would let a broken
     *     completion hop look healthy for weeks — §D23's argument, now pointed at the arm it was written to
     *     protect. It moves to {@see SsoLoginCompletionController}, where a session actually exists.
     *
     * Provisioning stays upstream on the login arm, and that DIVERGES from ADR-0019 §D7 ("a callback the
     * browser never follows leaves no account behind") on purpose: Google's callback is reachable with any
     * consumer account, while reaching this line at all requires an assertion signed by the tenant's own trust
     * anchor. A half-followed flow therefore leaves an account for somebody that directory genuinely
     * authenticated, which is what JIT provisioning is for. Recorded as a residual rather than omitted.
     *
     * The redirect target is composed with {@see TenantUrl::to()} rather than `route()`: the tenant route
     * groups declare no `->domain()`, so the named-route generator would emit the CENTRAL host and the
     * browser would arrive somewhere with neither this tenant's context nor this member's cookie.
     */
    private function handOff(Tenant $tenant, SsoAuthRequest $authRequest): RedirectResponse
    {
        $path = $authRequest->intent === SsoAuthIntent::StepUp
            ? 'sso/saml/step-up/complete/'
            : 'sso/saml/login/complete/';

        return redirect()->away(TenantUrl::to($tenant, $path.$authRequest->request_id));
    }
}
