<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Sso;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\ImpersonationSessionController;
use App\Http\Middleware\EnforceTenantTwoFactor;
use App\Models\SsoConnection;
use App\Services\Sso\SsoAuthenticationException;
use App\Services\Sso\SsoGate;
use App\Services\Sso\SsoLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The Assertion Consumer Service — where an identity provider's signed assertion becomes a session
 * (Phase 4, P1b — ADR-0016).
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
 * The caller is an identity provider posting cross-origin. There is no session to require — this request
 * creates one — and no CSRF token to present. What replaces the token is stronger: the assertion must be
 * signed by the tenant's own trust anchor AND name a live, unconsumed `sso_auth_requests` row this SP
 * minted. `bootstrap/app.php` carries the exemption by exact path.
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
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $connection = $this->gate->activeConnectionOrAbort();
        $tenant = $this->currentTenant();

        try {
            $user = $this->logins->consumeAssertion(
                $tenant,
                $connection,
                // `input()` rather than `validate()`: a validation failure would answer 302-with-errors,
                // which is a different response from the 404 every other refusal produces and would
                // therefore be a disclosure in itself.
                (string) $request->input('SAMLResponse', ''),
            );
        } catch (SsoAuthenticationException $exception) {
            Log::warning('sso.acs.rejected', [
                'reason' => $exception->reason,
                'detail' => $exception->getMessage(),
                'tenant_id' => (string) $tenant->getKey(),
                'connection_id' => (string) $connection->getKey(),
                'ip' => $request->ip(),
            ]);

            abort(404);
        }

        // ⚠️ ORDER IS LOAD-BEARING, and it is the ImpersonationSessionController lesson verbatim:
        // `Auth::login()` calls `Session::migrate(true)`, which regenerates the session id. Anything
        // written to the session BEFORE it lands on the pre-login session — Laravel carries the data
        // across, so it appears to work and breaks silently the day that stops being true.
        Auth::login($user);

        $this->stampLastLogin($connection);

        // `/dashboard` unconditionally. `sso_auth_requests.return_to` exists so the SP — never the
        // attacker-controllable `RelayState` — chooses the destination, but P1b never populates it: no flow
        // bounces an unauthenticated deep link through SSO yet. P1c's step-up is its first writer.
        return redirect('/dashboard');
    }

    /**
     * Record that this trust relationship is being used.
     *
     * ⚠️ A DIRECT ONE-COLUMN UPDATE, NEVER `$connection->save()`. `SsoConnectionService`'s docblock records
     * why: with `APP_PREVIOUS_KEYS` set — i.e. during any APP_KEY rotation window — `originalIsEquivalent()`
     * short-circuits to false for every `encrypted:` cast, so `idp_certificates` is PERMANENTLY dirty and a
     * model save would rewrite the tenant's trust anchor on every single login. An UPDATE naming one column
     * cannot.
     */
    private function stampLastLogin(SsoConnection $connection): void
    {
        SsoConnection::query()->whereKey($connection->getKey())->update(['last_login_at' => now()]);
    }
}
