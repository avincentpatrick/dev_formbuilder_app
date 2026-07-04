<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the database's view of "who is asking" for the duration of a request (ADR-0002 §D3,
 * application/session-context row). Runs immediately after tenant identification and before any
 * controller code, issuing the Postgres session variables the RLS policies read:
 *   - app.current_tenant_id — always, from the resolved tenant.
 *   - app.current_user_id  — only for authenticated requests (left NULL for guests, who never read
 *     users/user_ui_preferences). Required by those two tables' policies (RBAC doc §6 / ADR-0002 §D2).
 *
 * Without this step RLS has nothing to check against and every tenant-scoped table fails closed.
 *
 * NOTE (Increment A): registered as the `tenant.context` middleware alias but not yet attached to any
 * route group — there are no authenticated/tenant HTTP routes until Increment B wires auth + the
 * subdomain tenant group, which is where this attaches and gets exercised end-to-end. The context
 * used by the Increment-A fuzz pack is set directly via TenantContext (transaction-scoped), so the
 * DB-level DoD does not depend on this HTTP path.
 *
 * The session-scoped set here is deliberately paired with a forget() in terminate() so a value can
 * never survive onto a pooled/persistent connection reused by the next request (ADR-0002 §D2 hazard).
 */
class EstablishTenantDatabaseContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);
        $userId = $request->user()?->getAuthIdentifier();

        TenantContext::apply(
            tenantId: $tenant?->getKey(),
            userId: $userId !== null ? (string) $userId : null,
        );

        return $next($request);
    }

    /**
     * Reset the session variables once the response is sent, so no tenant/user context lingers on a
     * connection that may be reused by a later request.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
    }

    /**
     * The resolved tenant is bound into the container by the identification layer that runs before
     * this middleware (stancl/tenancy's subdomain identification, wired in Increment B). Kept
     * package-agnostic: this class only depends on a bound Tenant instance, not on how it got there.
     */
    private function resolveTenant(Request $request): ?Tenant
    {
        return app()->bound(Tenant::class) ? app(Tenant::class) : null;
    }
}
