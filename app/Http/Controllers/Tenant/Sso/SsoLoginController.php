<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Sso;

use App\Enums\SsoAuthIntent;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Services\Sso\SsoAuthnRequestBuilder;
use App\Services\Sso\SsoAuthRequestService;
use App\Services\Sso\SsoGate;
use App\Support\Sso\SsoSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SP-initiated sign-in: mint an AuthnRequest and bounce the browser at the tenant's identity provider
 * (Phase 4, P1b — ADR-0016 §D9).
 *
 * ── THIS IS THE ONLY LEGITIMATE ENTRY POINT INTO THE FLOW, AND THAT IS THE SECURITY PROPERTY ─────────
 * `config('saml.allow_unsolicited')` is a permanent `false`: an assertion arriving with no `InResponseTo`
 * has nothing binding it to a request this SP minted, and accepting one is a login-CSRF primitive. So every
 * assertion the ACS will ever accept traces back to a row this controller wrote. There is no second door.
 *
 * ── UNAUTHENTICATED BY NECESSITY ────────────────────────────────────────────────────────────────────
 * Outside `auth`, because on the login path there is by definition no session yet — requiring one would be
 * circular. Tenant context IS established first (subdomain identification runs ahead of it), which is what
 * makes the strict-RLS connection row visible and confines the whole request to one workspace.
 *
 * ⚠️ NO `?return_to=`, AND ITS ABSENCE IS DELIBERATE. `sso_auth_requests.return_to` exists so the SP can
 * choose where a completed login lands, but nothing in the product currently bounces an unauthenticated
 * deep link through SSO — so there is no server-side origin to record, and accepting the destination from
 * the query string would be a plain open redirect wearing a column name. P1c's step-up is the first caller
 * with a legitimate answer, because it knows the page the already-authenticated user was on.
 *
 * ⚠️ AN ALREADY-AUTHENTICATED VISITOR IS NOT SHORT-CIRCUITED. Sending them straight to `/dashboard` would
 * look tidy and would quietly break the one case that matters: re-authenticating as somebody else on a
 * shared machine. The IdP is the right place to decide whether an existing session answers this request —
 * that is what single sign-on IS — and it can only decide if it is asked.
 */
final class SsoLoginController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly SsoGate $gate,
        private readonly SsoAuthRequestService $requests,
        private readonly SsoAuthnRequestBuilder $builder,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        // 404 for unentitled, unconfigured, draft and disabled alike (§D4) — the same answer the metadata
        // endpoint gives, so nothing here discloses another organisation's plan to an anonymous caller.
        $connection = $this->gate->activeConnectionOrAbort();
        $tenant = $this->currentTenant();

        $authRequest = $this->requests->mint(
            connection: $connection,
            intent: SsoAuthIntent::Login,
            // Null for a login: there is no authenticated subject to name, and the
            // `sso_auth_requests_step_up_user_check` CHECK refuses a login row that carries one.
            user: null,
            forceAuthn: false,
            ip: $request->ip(),
        );

        // ⚠️ THE ONE THING THIS ENDPOINT DID NOT DO UNTIL P1e, AND ITS ABSENCE WAS A LIVE DEFECT.
        // The row above proves that THIS SP MINTED THIS FLOW. Nothing proved who was HOLDING it — so an
        // attacker with an account at the tenant's own directory could start a flow, collect a real signed
        // assertion for it, withhold the identity provider's auto-POST form, and induce a victim's browser to
        // submit it. The ACS would then have signed the victim's browser in as the attacker.
        //
        // The ACS cannot make this comparison: it is a cross-site POST, `SameSite=Lax` sends it no cookie,
        // and since P1e it starts no session at all. This request CAN — it is same-site and the browser is
        // holding the cookie right now — so the binding is written here and read one hop after the ACS, by
        // `SsoLoginCompletionController`. `request_id` rather than a second secret: it is already 128 bits
        // and already single-use, and the step-up arm already accepts the identical exposure in a URL.
        SsoSession::rememberPendingLogin($request->session(), $authRequest->request_id);

        // `away()` rather than `to()`: this is somebody else's host, and Laravel's own URL generator would
        // otherwise treat the value as a path to be rewritten against ours.
        return redirect()->away($this->builder->redirectUrl($tenant, $connection, $authRequest));
    }
}
