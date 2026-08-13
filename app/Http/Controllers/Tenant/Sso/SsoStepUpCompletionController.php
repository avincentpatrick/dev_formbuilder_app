<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Sso;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPassword;
use App\Services\Sso\SsoStepUpService;
use App\Support\Sso\SsoReturnTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The same-site hop that finishes a step-up, and the only place `auth.password_confirmed_at` is stamped by
 * SSO (P1c — ADR-0016 §D24).
 *
 * ── ⚠️ WHY THIS ROUTE EXISTS AT ALL — IT IS A COOKIE POLICY, NOT A PROTOCOL REQUIREMENT ─────────────
 * `config/session.php` sets `same_site` to `lax`, so the browser withholds the session cookie from the
 * cross-site POST the identity provider makes to the ACS. `SameSite=Lax` DOES send cookies on a top-level
 * GET navigation, which is exactly what the ACS's redirect produces — so this request, unlike the one before
 * it, arrives on the member's ORIGINAL session. That is what makes the third of {@see SsoAuthIntent}'s three
 * conditions answerable: `sso_auth_requests.user_id` is compared against the user actually signed in here.
 *
 * Finishing at the ACS instead would mean calling `Auth::login()` there and stamping the fresh session it
 * creates. That *works*, and it is wrong: it silently replaces the session the step-up was being performed
 * for, discards whatever that session held, leaves the old one live server-side, and downgrades the subject
 * check from a statement about the SESSION to a statement about the ASSERTION.
 *
 * ── EVERY REFUSAL IS THE SAME 404 ───────────────────────────────────────────────────────────────────
 * §D4's posture, carried across from the ACS: unknown id, wrong intent, no `ForceAuthn`, never verified,
 * already redeemed, past the completion window, or a session belonging to somebody else are one
 * indistinguishable response. {@see SsoStepUpService::redeem()} evaluates all seven and returns null.
 *
 * ⚠️ NOT `step-up`-GATED, and it must never become so — {@see RequireRecentPassword} redirects INTO this
 * flow, so gating its exit is a redirect loop.
 */
final class SsoStepUpCompletionController extends Controller
{
    public function __construct(private readonly SsoStepUpService $stepUps) {}

    public function __invoke(Request $request, string $requestId): RedirectResponse
    {
        $user = $request->user();
        $authRequest = $this->stepUps->redeem($requestId, $user);

        if ($authRequest === null) {
            // Logged, never audited, for the reason `SsoAuthenticationException` gives about `audits` being
            // append-only and never pruned. Unlike the ACS's refusals this one HAS an authenticated actor,
            // so the log line can name them — which is what makes "somebody else's step-up landed on my
            // session" investigable rather than merely counted.
            Log::warning('sso.step_up.rejected', [
                'request_id' => $requestId,
                'user_id' => (string) $user->getKey(),
                'ip' => $request->ip(),
            ]);

            abort(404);
        }

        // The step-up clock itself — the same session key `Illuminate\Auth\Middleware\RequirePassword` reads
        // and Fortify's confirm-password form writes. Written HERE and nowhere else in the SSO seam: this is
        // the only point in the flow where all three conditions have been shown to hold at once.
        $request->session()->put('auth.password_confirmed_at', time());

        // Re-validated on the way out even though `SsoReturnTo::sanitise()` already ran on the way in. The
        // column is a `varchar` in a database people can edit, and a redirect target is not somewhere to
        // trust a value because of where it was supposed to have come from.
        return redirect(SsoReturnTo::destination($authRequest->return_to));
    }
}
