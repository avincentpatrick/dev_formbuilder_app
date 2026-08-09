<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Admin\ImpersonationService;
use App\Support\Audit\ImpersonationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 30-minute cap on an impersonated session — Increment I11b, decision of record 2026-08-09.
 *
 * ── WHY A CAP AT ALL, WHEN THE OPERATOR HAS AN EXIT BUTTON ──────────────────────────────────────────────
 * Because the button is the happy path and the ledger must not depend on it. An operator who closes the tab
 * or walks away leaves a live, fully-authorised session on a customer's workspace for
 * `config('session.lifetime')` — hours — and, worse, leaves the `impersonation_ended` row unwritten, so the
 * tenant's own audit log shows platform access that never appears to have finished. A compliance record
 * with an open bracket is not a record of the access; it is a record of half of it.
 *
 * ── IT ENDS THE SESSION THE SAME WAY THE EXIT BUTTON DOES ───────────────────────────────────────────────
 * Same order, same ledger row, same cleared keys — deliberately, so there is exactly one description of
 * "an impersonation ended" and not two that can drift. The only difference is where the operator lands:
 * the exit button returns them to the console they came from, while this fires on whatever request happened
 * to cross the deadline, so it sends them to the tenant's login page like any other expired session.
 *
 * ── ⚠️ THE DEADLINE IS COMPARED, NEVER REWRITTEN ────────────────────────────────────────────────────────
 * This is a HARD cap, not an idle timeout. Refreshing it on activity would let a working session run
 * indefinitely, which is the property the cap exists to remove — and it would do so invisibly, since every
 * request an operator makes is "activity". The stamp is written once, at redemption, and only ever read
 * here.
 *
 * ── ORDER, AND THE ONE PLACE IT MUST NOT BE MOUNTED ─────────────────────────────────────────────────────
 * After `auth` (it needs the user it is logging out) and after `EstablishTenantDatabaseContext` (the audit
 * row it writes is tenant-scoped and needs the RLS GUC). Both hold by being named late in the group array.
 *
 * NOT on the impersonation ARRIVAL route: at that moment the deadline has not been written yet, and a
 * middleware that treats "no stamp" as expired would kill every session in the same request that created
 * it. The absent-stamp case is an ordinary non-impersonated request and returns immediately.
 */
final class EnforceImpersonationTimeout
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $operatorId = ImpersonationContext::operatorId();

        // The overwhelmingly common case: nobody is impersonating. One session read, no query.
        if ($operatorId === null) {
            return $next($request);
        }

        // ⚠️ `ImpersonationContext::operatorId()` ABOVE AND `$request->session()` HERE ARE NOT THE SAME
        // STORE, and assuming they were threw a 500 the first time this was exercised. That reader falls
        // back to the session MANAGER when the request has no attached store; this one would then throw
        // "Session store not set on request". On a `web` route the two always agree, because `StartSession`
        // has run — so a request without one is not an impersonated session, it is a route this middleware
        // has no business on, and passing it through is the correct answer rather than a swallowed error.
        if (! $request->hasSession()) {
            return $next($request);
        }

        $deadline = $request->session()->get(ImpersonationService::DEADLINE_SESSION_KEY);

        // ⚠️ A MARKER WITH NO DEADLINE IS TREATED AS EXPIRED, WHICH IS THE FAIL-CLOSED DIRECTION. The pair
        // is written together in one place, so a session carrying only the marker is either a session that
        // predates this middleware or one somebody has edited — and "impersonating, with no expiry" is not
        // a state this feature has any use for.
        if (! is_int($deadline)) {
            return $this->end($request, $operatorId);
        }

        if (Carbon::now()->getTimestamp() < $deadline) {
            return $next($request);
        }

        return $this->end($request, $operatorId);
    }

    /** Write the closing ledger row and tear the session down — the exit path, minus the console redirect. */
    private function end(Request $request, string $operatorId): Response
    {
        $targetUserId = (string) $request->user()?->getAuthIdentifier();

        if ($targetUserId !== '') {
            $this->impersonation->recordEnded($operatorId, $targetUserId);
        }

        $request->session()->forget([
            ImpersonationContext::SESSION_KEY,
            ImpersonationService::DEADLINE_SESSION_KEY,
        ]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // An ordinary same-origin redirect, unlike the exit button's `Inertia::location()`: /login is on
        // THIS host, so there is no cross-origin hop for an Inertia XHR to choke on.
        return redirect()->route('login');
    }
}
