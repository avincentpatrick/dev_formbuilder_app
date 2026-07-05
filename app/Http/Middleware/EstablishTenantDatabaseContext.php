<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the database's view of "who is asking" for the duration of a request (ADR-0002 §D3,
 * application/session-context row). Runs immediately after tenant identification and before any
 * controller code, issuing the Postgres session variables the RLS policies read:
 *   - app.current_tenant_id — always, from the resolved tenant.
 *   - app.current_user_id  — only for authenticated requests (left NULL for guests, who never read
 *     users/user_ui_preferences). Required by those two tables' policies (RBAC doc §6 / ADR-0002 §D2).
 *
 * It ALSO sets Spatie's permissions team id (Increment B2a) to the same tenant, so RBAC authorizes
 * against the exact tenant that RLS isolates data by — two concerns, one trigger (RBAC doc §2, §10).
 * A request that set tenant context but forgot the team would fail every hasRole()/hasPermissionTo()
 * check silently (Spatie finds no roles for the "no team" context) — fail-closed, but invisible; hence
 * they are set together here and covered by an explicit test.
 *
 * Without this step RLS has nothing to check against and every tenant-scoped table fails closed.
 *
 * NOTE (Increment B1): registered as the `tenant.context` middleware alias and attached to the
 * authenticated subdomain group in routes/tenant.php.
 *
 * The session-scoped set here is deliberately paired with a forget() in terminate() so a value can
 * never survive onto a pooled/persistent connection reused by the next request (ADR-0002 §D2 hazard);
 * the Spatie team id (in-process registrar state) is reset there for the same reason.
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

        // RBAC's "which tenant am I authorizing against" — the same answer as isolation's "which tenant
        // am I querying against". Null on the central domain ⇒ no team ⇒ role checks fail closed.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant?->getKey());

        return $next($request);
    }

    /**
     * Reset the session variables once the response is sent, so no tenant/user context lingers on a
     * connection that may be reused by a later request. The Spatie permissions team id (in-process
     * registrar state) is reset for the same reason.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * The resolved tenant is bound into the container by stancl/tenancy's identification middleware,
     * which runs immediately before this one. stancl binds the current tenant under the
     * Stancl\Tenancy\Contracts\Tenant key (what the `tenant()` helper reads); our App\Models\Tenant
     * implements that contract. Returns null on the central domain, where no tenant is bound.
     */
    private function resolveTenant(Request $request): ?Tenant
    {
        if (! app()->bound(TenantContract::class)) {
            return null;
        }

        $tenant = app(TenantContract::class);

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
