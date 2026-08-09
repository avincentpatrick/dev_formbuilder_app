<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Services\Admin\ImpersonationService;
use App\Support\Audit\ImpersonationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant-host half of impersonation — Increment I11b. Redeems the grant the console minted, and ends
 * the session it created.
 *
 * ── WHY THIS LIVES ON THE TENANT HOST AT ALL ────────────────────────────────────────────────────────────
 * `SESSION_DOMAIN` is null, so the session cookie is HOST-ONLY: the operator's console session does not
 * exist here, and there is nothing to "switch". They arrive unauthenticated carrying a 60-second single-use
 * string, and this controller is what turns it into a real logged-in session on this origin. The invitation
 * flow has the same shape for the same reason and is the template — {@see InvitationController}.
 *
 * ── THE ARRIVAL ROUTE IS OUTSIDE `auth` AND OUTSIDE `EnforceTenantTwoFactor` ────────────────────────────
 * Outside `auth` because there is no session yet — requiring one would be circular, exactly as it is for an
 * invitee who is not a member. Tenant context IS established (subdomain identification runs first), which
 * is what makes the strict-RLS token row visible and what scopes the lookup: a token minted for another
 * workspace does not resolve here at all.
 *
 * The 2FA exemption is NOT drawn here, though — it is drawn inside {@see EnforceTenantTwoFactor}, because
 * it depends on session state rather than on a path. See that class for why bouncing an operator to a
 * member's enrollment page is worse than letting them through.
 */
final class ImpersonationSessionController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly ImpersonationService $impersonation) {}

    /**
     * Redeem the grant and become the target user.
     *
     * ⚠️ EVERY FAILURE IS THE SAME 404. Unknown token, expired token, already-consumed token, membership
     * revoked in the intervening minute — one indistinguishable response. An error page that separated
     * "expired" from "never existed" would separate them for anyone holding a guessed string too, and the
     * legitimate holder never sees any of them: they are redirected into this URL in the same tick it was
     * minted.
     */
    public function show(Request $request, string $token): RedirectResponse
    {
        $redeemed = $this->impersonation->redeem($token, $request->ip());

        abort_if($redeemed === null, 404);

        // ⚠️ ORDER IS LOAD-BEARING: `Auth::login()` calls `Session::migrate(true)`, which regenerates the
        // session id. Writing the impersonation keys BEFORE it would put them on the pre-login session —
        // Laravel carries the data across a migrate, so it would appear to work, and would break silently
        // the day that stops being true. After, they land on the session the browser actually holds.
        Auth::login($redeemed->user);

        $request->session()->put(ImpersonationContext::SESSION_KEY, $redeemed->operatorId);

        // The hard cap (decision of record, 2026-08-09). Stamped as an absolute unix timestamp rather than
        // a countdown so it survives the session serializer unambiguously and cannot be extended by
        // activity — {@see EnforceImpersonationTimeout} only ever compares, never rewrites.
        $request->session()->put(
            ImpersonationService::DEADLINE_SESSION_KEY,
            Carbon::now()->addMinutes(ImpersonationService::SESSION_CAP_MINUTES)->getTimestamp(),
        );

        return redirect('/dashboard');
    }

    /**
     * End it: write the closing ledger row, drop the marker, and log out.
     *
     * ⚠️ THE SESSION KEY MUST BE CLEARED, AND THE COST OF FORGETTING IS NOT COSMETIC. Nothing else clears
     * it, and `audits_acting_as_not_self_check` REFUSES a row whose `acting_as_user_id` equals its
     * `user_id` — so a leftover `impersonator_id` belonging to an operator who is also a member of this
     * tenant would make that person's next ordinary save raise a 23514 from inside their own business
     * transaction. `Auth::logout()` + `invalidate()` below would drop it anyway; it is forgotten
     * EXPLICITLY first so the guarantee does not depend on a framework call's side effect.
     *
     * ⚠️ AND THE LEDGER ROW IS WRITTEN BEFORE THE LOGOUT, because it needs the context the logout destroys:
     * the tenant GUC, the authenticated user, and the marker itself.
     */
    public function destroy(Request $request): Response
    {
        $operatorId = ImpersonationContext::operatorId();
        $targetUserId = (string) $request->user()?->getAuthIdentifier();

        if ($operatorId !== null && $targetUserId !== '') {
            $this->impersonation->recordEnded($operatorId, $targetUserId);
        }

        $request->session()->forget([
            ImpersonationContext::SESSION_KEY,
            ImpersonationService::DEADLINE_SESSION_KEY,
        ]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Back to the console, and `Inertia::location()` for the same cross-origin reason the START hop
        // needs it: this is a different host, and an Inertia XHR cannot follow a 302 to one.
        return Inertia::location($this->consoleUrl());
    }

    /** The tenant-detail page the operator started from. */
    private function consoleUrl(): string
    {
        $tenantId = (string) $this->currentTenant()->getKey();

        return rtrim((string) config('app.url'), '/').'/admin/tenants/'.$tenantId;
    }
}
