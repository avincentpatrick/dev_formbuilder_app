<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\GoogleAuthStateService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the PostgreSQL RLS tenant context for the Google sign-in CALLBACK (J3c2, ADR-0019 §D6) —
 * {@see EstablishConnectorOauthContext}'s sibling, for a request that arrives on the CENTRAL host with
 * neither a session nor a resolvable tenant.
 *
 * Under FORCE RLS with no `app.current_tenant_id`, the callback's UPDATE would match no policy and affect
 * ZERO ROWS WITHOUT ERRORING — the failure this product has now paid for three times. The tenant therefore
 * has to come from something the request itself proves, which is the signed `state`.
 *
 * The state is verified — structure, signature, claim shape, expiry — BEFORE any context is set, so a
 * forged or tampered token never engages RLS at all. The decoded value is stashed for the controller rather
 * than verified twice.
 *
 * ── ⚠️ TWO DIFFERENCES FROM THE CONNECTOR TWIN, BOTH FORCED BY THIS BEING A SIGN-IN ──────────────────
 * 1. **NO `userId` IS APPLIED.** The connector middleware sets both GUCs because a connector names the
 *    member who started the flow. Here the user is the OUTCOME — nobody is authenticated yet, and the row
 *    this callback writes is keyed on the tenant alone. That makes it structurally
 *    {@see EstablishGuestTenantContext}'s shape rather than the connector's, and it means every write
 *    downstream of here runs with `app.current_user_id` unset. Anything touching `users` must say so.
 * 2. **A NULL TENANT IS A VALID VERIFIED OUTCOME, NOT A FAILURE.** A sign-in begun on the central host
 *    belongs to no workspace (§D12), so `tid` is legitimately null and no context is applied at all. The
 *    controller forks on the same value. A connector state has no such case.
 *
 * Registered by class-string in `routes/google-auth.php`, like the guest and connector middlewares and not
 * as an alias: its route has no model binding, so it needs no middleware-priority entry either.
 *
 * The session-scoped {@see TenantContext::apply()} is paired with a {@see TenantContext::forget()} in
 * `terminate()`, so the context can never survive onto a pooled connection reused by the next request
 * (ADR-0002 §D2).
 */
final class EstablishGoogleAuthContext
{
    public function __construct(private readonly GoogleAuthStateService $states) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->states->verify((string) $request->query('state'));

        if ($state->tenantId !== null) {
            TenantContext::apply(tenantId: $state->tenantId);
        }

        $request->attributes->set('googleAuthState', $state);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
    }
}
