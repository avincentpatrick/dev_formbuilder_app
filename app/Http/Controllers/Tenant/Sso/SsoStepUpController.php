<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Sso;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPassword;
use App\Models\User;
use App\Services\Sso\SsoAuthnRequestBuilder;
use App\Services\Sso\SsoGate;
use App\Services\Sso\SsoStepUpService;
use App\Support\Sso\SsoReturnTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Start an SSO step-up: mint a `ForceAuthn` AuthnRequest about the CURRENT user (P1c — ADR-0016 §D22).
 *
 * Where {@see SsoLoginController} is the unauthenticated front door, this is its authenticated twin. The
 * differences are exactly the three things a step-up is: an `intent` of `StepUp`, a named subject, and
 * `ForceAuthn="true"`. Everything else — the builder, the transport encoding, the row — is shared, which is
 * why this controller is short.
 *
 * ── ⚠️ IT MUST NOT CARRY THE `step-up` MIDDLEWARE ITSELF ────────────────────────────────────────────
 * {@see RequireRecentPassword} redirects HERE when the session is SSO-backed. Mounting the gate on its own
 * escape hatch is an infinite redirect, and it would be a redirect between two routes that each look
 * individually correct — the reason this sentence exists rather than a test comment.
 *
 * ── AUTHENTICATED, AND OPEN TO PASSWORD SESSIONS TOO ────────────────────────────────────────────────
 * Nothing here checks how the session was established. It does not need to: the assertion that comes back
 * must resolve to THIS user or the round trip is refused, so a password-authenticated member who visits
 * this URL is merely choosing the harder of two equally valid ways to prove they are still there. The fork
 * that decides which one people are SENT to lives in the middleware, where the choice belongs.
 *
 * ⚠️ NO `?return_to=`, FOR THE REASON `SsoLoginController` GIVES. The destination comes from the server's
 * own `url.intended` — written by `Redirect::guest()` when the gate bounced the member — never from the
 * query string, which would be an open redirect wearing a column name. {@see SsoReturnTo} then reduces it
 * to a path, so the column cannot hold an off-site destination even if a future caller passes one.
 */
final class SsoStepUpController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly SsoGate $gate,
        private readonly SsoAuthnRequestBuilder $builder,
        private readonly SsoStepUpService $stepUps,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        // The same 404 the protocol surface gives for unentitled, unconfigured, draft and disabled (§D4).
        // Reachable by an authenticated member typing the URL on a workspace with no SSO, which is not a
        // disclosure worth distinguishing — and the middleware never sends anyone here in that state.
        $connection = $this->gate->activeConnectionOrAbort();
        $tenant = $this->currentTenant();

        // Non-null by construction: this route is inside the authenticated group. The annotation is the
        // repo's idiom for saying so to static analysis (AnalyticsController and a dozen others).
        /** @var User $user */
        $user = $request->user();

        $authRequest = $this->stepUps->start(
            connection: $connection,
            user: $user,
            // READ, not pulled: `redirect()->intended()` would forget the key, and the value is still needed
            // after the round trip if anything else in the session wants it. The completion hop redirects
            // from `return_to` on the row instead, which is bound to THIS request rather than to whichever
            // tab wrote `url.intended` last.
            intended: $request->session()->get('url.intended'),
            ip: $request->ip(),
        );

        // `away()` rather than `to()`: this is somebody else's host, and Laravel's own URL generator would
        // otherwise treat the value as a path to be rewritten against ours.
        return redirect()->away($this->builder->redirectUrl($tenant, $connection, $authRequest));
    }
}
