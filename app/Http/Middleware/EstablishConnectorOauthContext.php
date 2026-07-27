<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Connectors\ConnectorOAuthStateService;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the Postgres RLS tenant context for a native-connector OAuth CALLBACK (ADR-0009 §D4, H15a) —
 * the {@see EstablishGuestDraftContext} sibling, for a request that arrives on the CENTRAL domain with
 * neither a session nor a resolvable tenant.
 *
 * WHY THIS EXISTS AT ALL: an OAuth app registers one fixed redirect URI, so every tenant's callback lands on
 * the central host; and the app's session cookie is host-only, so the tenant's session is not readable there
 * (ADR-0009 §D2/§D3). Under FORCE RLS with no `app.current_tenant_id`, the callback's INSERT would match no
 * policy and write ZERO ROWS WITHOUT ERRORING — a silent, invisible failure. The tenant therefore has to
 * come from something the request itself proves, which is the signed `state`.
 *
 * The state is verified — signature, claim shape, provider binding, expiry — BEFORE any context is set: a
 * forged, tampered, expired or wrong-provider state throws (→ a bounce to the app URL, mapped in
 * bootstrap/app.php) and never engages RLS. On success it applies BOTH the tenant and the user who started
 * the flow, so the connection records a real `connected_by` and the audit entry names a human rather than a
 * system write. The decoded state is stashed for the controller.
 *
 * Registered by class-string in routes/connectors.php (like the guest middlewares), not as an alias. Its
 * `{provider}` route parameter is a plain string, so it needs no middleware-priority entry — nothing
 * downstream touches the database before the controller.
 *
 * The session-scoped apply() is paired with a forget() in terminate(), so the tenant context can never
 * survive onto a pooled/persistent connection reused by the next request (ADR-0002 §D2).
 */
final class EstablishConnectorOauthContext
{
    public function __construct(
        private readonly ConnectorOAuthStateService $states,
        private readonly ConnectorRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The provider the CALLBACK is bound to, not one the state claims — resolving it here also refuses a
        // request for a provider with no configured adapter before any verification work happens.
        $provider = $this->registry->keyFor((string) $request->route('provider'));

        $state = $this->states->verify((string) $request->query('state'), $provider);

        TenantContext::apply(tenantId: $state->tenantId, userId: $state->userId);

        $request->attributes->set('connectorOauthState', $state);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
    }
}
