<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Models\GoogleAuthRequest;
use App\Services\Auth\GoogleAuthRequestService;
use App\Services\Auth\GoogleSessionStarter;
use App\Services\Auth\GoogleSignInProvisioner;
use App\Support\Auth\GoogleIdentity;
use App\Support\Auth\GoogleSignInRefusedException;
use App\Support\Sso\SsoReturnTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The same-site hop that actually creates the session (Increment J3c2, ADR-0019 §D7).
 *
 * ── WHY THIS EXISTS, AND IT IS NOT SAML's REASON ────────────────────────────────────────────────────
 * `SESSION_DOMAIN` is null, so the session cookie is HOST-ONLY: a session created at the central callback
 * is simply never sent to `acme.example.com`. SAML's completion hop exists because `same_site` is `lax`
 * and a cross-site POST carries no cookie; this one exists because the cookie has the wrong DOMAIN. Two
 * different problems with the same shape of answer, and the ADR says so rather than letting a later reader
 * assume one implies the other.
 *
 * ── ⚠️ EVERYTHING HAPPENS HERE, NOT AT THE CALLBACK, AND THAT IS THE POINT ──────────────────────────
 * Provisioning, membership and the session are one hop, so a callback the browser never follows leaves no
 * account and no membership behind. This route carries the FULL tenant pipeline, so unlike the mint route
 * it has a real `app.current_tenant_id` and nothing here borrows one.
 *
 * ⚠️ THE WRONG-TENANT REFUSAL IS RLS RATHER THAN A COMPARISON. A handoff minted for workspace A and
 * presented on workspace B's host matches no row, because B's context was established before
 * {@see GoogleAuthRequestService::redeem()} ran. There is deliberately no `tenant_id` check in this file:
 * writing one would suggest the guarantee lived in PHP.
 *
 * ⚠️ NO `auth` MIDDLEWARE, and no `verified` either — this route is how a person BECOMES authenticated,
 * and the account it signs in was stamped `email_verified_at` at creation on Google's own claim.
 */
final class GoogleCompleteController
{
    use ResolvesTenant;

    public function __construct(
        private readonly GoogleAuthRequestService $requests,
        private readonly GoogleSignInProvisioner $provisioner,
        private readonly GoogleSessionStarter $sessions,
    ) {}

    public function __invoke(Request $request, string $handoff): RedirectResponse
    {
        $row = $this->requests->redeem($handoff);

        // Null covers unknown, expired, already redeemed and minted-for-another-workspace, and they are
        // deliberately indistinguishable from each other and from every other refusal (§D9).
        if ($row === null) {
            return $this->refuse('handoff_not_redeemable', $request);
        }

        // ⚠️ THE BROWSER THAT FINISHES MUST BE THE ONE THAT STARTED. This hop is same-host with the mint,
        // so the tenant session that recorded the flow's `state_id` is readable here — which is the whole
        // reason the binding needs no cross-host cookie. Without it the handoff URL is a bearer token
        // accepted in ANY browser: capture it from the callback's `Location` and hand it to a victim, and
        // they are signed in as somebody else with an attacker-chosen `return_to` picking the landing page.
        // Checked AFTER the redeem, deliberately: the handoff is burned either way, so a stolen URL cannot
        // be retried in the right browser once it has been tried in the wrong one.
        if ($request->session()->get(GoogleRedirectController::FLOW_SID) !== $row->state_id) {
            return $this->refuse('flow_not_bound_to_browser', $request);
        }

        try {
            $outcome = $this->provisioner->provision($this->currentTenant(), $this->identityFrom($row), $request);
        } catch (GoogleSignInRefusedException $exception) {
            return $this->refuse($exception->reason, $request);
        }

        // Re-validated on the way out even though `SsoReturnTo::sanitise()` already ran at the mint: the
        // column is 500 characters of stored text, and the cost of asking twice is one string comparison.
        return $this->sessions->start($request, $outcome, SsoReturnTo::destination($row->return_to));
    }

    /**
     * Rebuild the identity from the row the callback stamped.
     *
     * ⚠️ `google_email_verified` IS COMPARED WITH `=== true` HERE TOO. The column is a nullable boolean, so
     * it can be null on a row that was never consumed — and the provisioner refuses on that value, which
     * means a row that somehow reached this point without an identity produces a refusal rather than a
     * sign-in as a half-known person. The migration's `google_auth_requests_identity_check` makes that
     * unreachable; this is what happens if it is ever dropped.
     */
    private function identityFrom(GoogleAuthRequest $row): GoogleIdentity
    {
        return new GoogleIdentity(
            subject: (string) $row->google_sub,
            email: (string) $row->google_email,
            emailVerified: $row->google_email_verified === true,
            name: (string) $row->google_name,
        );
    }

    /**
     * The same closed `?google=failed` the callback produces, on this workspace's own login page.
     *
     * A relative redirect, because the browser is already on the right host — which is the whole reason
     * this hop exists.
     */
    private function refuse(string $reason, Request $request): RedirectResponse
    {
        Log::warning('auth.google.complete.rejected', [
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);

        return redirect('/login?google=failed');
    }
}
