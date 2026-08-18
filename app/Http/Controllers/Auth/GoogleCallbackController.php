<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\GoogleAuthRequest;
use App\Models\Tenant;
use App\Services\Auth\GoogleAuthRequestService;
use App\Services\Auth\GoogleSessionStarter;
use App\Services\Auth\GoogleSignInProvisioner;
use App\Support\Auth\GoogleAuthException;
use App\Support\Auth\GoogleAuthState;
use App\Support\Auth\GoogleIdentityProvider;
use App\Support\Auth\GoogleSignInRefusedException;
use App\Support\Sso\SsoReturnTo;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where Google sends the browser back (Increment J3c2, ADR-0009 §D2 as amended, ADR-0019 §D6).
 *
 * ── ONE CENTRAL CALLBACK, BECAUSE GOOGLE REJECTS WILDCARD REDIRECT URIs ─────────────────────────────
 * An OAuth client registers ONE fixed redirect URI, so a per-tenant subdomain callback is unshippable past
 * the first workspace. ADR-0009 §D2 established that for connectors and it is unchanged here — but this
 * route diverges from `routes/connectors.php` in two ways that are written down rather than inherited:
 * it takes `web` (the CENTRAL arm signs in right here, so it needs a session), and it declares no
 * `Route::domain()` (that constraint does not match `localhost`, which would make the flow unexercisable
 * against the fake on a dev box). `RequirePlatformHost` is what keeps a custom domain from serving it.
 *
 * ── THE TWO ARMS ────────────────────────────────────────────────────────────────────────────────────
 * **Central** (`tid` is null, §D12): callback and session share a host, so there is nothing for a handoff
 * to carry and no row to redeem. Provision and sign in on the spot. Replay is bounded by Google — an
 * authorization `code` is single-use at the provider — plus the state's own expiry.
 *
 * **Tenant**: the session cookie is HOST-ONLY (`SESSION_DOMAIN` is null), so a session created here would
 * never be sent to `acme.example.com`. It is the cookie-DOMAIN problem, not the SameSite one that gave
 * SAML its completion hop. So this arm mints a single-use handoff, stores only its SHA-256, and sends the
 * browser to the tenant's own host to redeem it. **Nothing is provisioned here** — the account and the
 * membership are created by the hop that also creates the session, so a callback that the browser never
 * follows leaves no user behind.
 *
 * ── ⚠️ ONE INDISTINGUISHABLE REFUSAL, AND `?google=failed` IS A CLOSED VALUE (§D9) ──────────────────
 * A refused exchange, an unverified address, a replayed state and a vanished workspace all produce the
 * same bounce. Nothing from the provider or the provisioner is ever echoed — that would be both a
 * disclosure oracle and a reflected-content sink. The reason goes to the log, and never to `audits`:
 * that table is append-only by RLS and never pruned, so an unauthenticated endpoint writing a row per
 * rejection is an amplification primitive.
 *
 * ⚠️ The bounce prefers the TENANT's own login page when the state named one, which discloses nothing —
 * the signed state already proved we minted it for that workspace — and is the difference between "try
 * again" and "you are now on a host you have never seen".
 */
final class GoogleCallbackController
{
    public function __construct(
        private readonly GoogleIdentityProvider $google,
        private readonly GoogleAuthRequestService $requests,
        private readonly GoogleSignInProvisioner $provisioner,
        private readonly GoogleSessionStarter $sessions,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var GoogleAuthState $state */
        $state = $request->attributes->get('googleAuthState');

        $redirectUri = (string) config('services.google.redirect');

        try {
            // ⚠️ THE SAME `$redirectUri` STRING THE AUTHORIZE STEP USED. Google compares it byte for byte
            // between the two calls, which is why it is passed rather than re-derived inside the adapter.
            $identity = $this->google->identityFromCode((string) $request->query('code'), $redirectUri);
        } catch (GoogleAuthException $exception) {
            return $this->refuse($state, 'exchange_failed', $request);
        }

        // §D3's first assertion, before anything is stored. The provisioner asserts it again on the far
        // side of the host boundary, because between here and there sits a row somebody else could have
        // written if any of the guarantees above ever failed.
        if (! $identity->emailVerified) {
            return $this->refuse($state, 'email_not_verified', $request);
        }

        if ($state->isCentral()) {
            // ⚠️ THE BROWSER THAT FINISHES MUST BE THE ONE THAT STARTED (see GoogleRedirectController's
            // FLOW_SID docblock). The central arm signs in HERE, so the check belongs here; the tenant arm
            // signs in one hop later and is checked there instead. Comparing the flow's own `state_id`
            // rather than merely requiring some value is what stops an attacker luring the victim through
            // the mint route first to populate their session.
            if ($request->session()->get(GoogleRedirectController::FLOW_SID) !== $state->stateId) {
                return $this->refuse($state, 'flow_not_bound_to_browser', $request);
            }

            try {
                $outcome = $this->provisioner->provision(null, $identity, $request);
            } catch (GoogleSignInRefusedException $exception) {
                return $this->refuse($state, $exception->reason, $request);
            }

            return $this->sessions->start(
                $request,
                $outcome,
                SsoReturnTo::destination($request->session()->pull(GoogleRedirectController::CENTRAL_RETURN_TO)),
            );
        }

        $tenant = Tenant::query()->whereKey($state->tenantId)->first();

        if (! $tenant instanceof Tenant) {
            return $this->refuse($state, 'tenant_not_found', $request);
        }

        $plain = GoogleAuthRequest::mintHandoffToken();

        // The affected-row count is the check. `false` means the state was already spent — a replay.
        if (! $this->requests->attach($state, $identity, GoogleAuthRequest::hashHandoffToken($plain))) {
            return $this->refuse($state, 'state_replayed', $request);
        }

        // `TenantUrl::to()` rather than `route()`: the tenant route groups declare no `->domain()`, so the
        // named-route generator would emit THIS (central) host and the browser would arrive somewhere with
        // neither the workspace's context nor any prospect of a session on it.
        return redirect()->away(TenantUrl::to($tenant, 'auth/google/complete/'.$plain));
    }

    /**
     * One outcome for every cause. The `reason` is a log field and never reaches the response.
     */
    private function refuse(GoogleAuthState $state, string $reason, Request $request): RedirectResponse
    {
        Log::warning('auth.google.callback.rejected', [
            'reason' => $reason,
            'tenant_id' => $state->tenantId,
            'ip' => $request->ip(),
        ]);

        return redirect()->away($this->bounceTarget($state));
    }

    private function bounceTarget(GoogleAuthState $state): string
    {
        if ($state->tenantId !== null) {
            $tenant = Tenant::query()->whereKey($state->tenantId)->first();

            if ($tenant instanceof Tenant) {
                return TenantUrl::to($tenant, 'login?google=failed');
            }
        }

        return rtrim((string) config('app.url'), '/').'/login?google=failed';
    }
}
