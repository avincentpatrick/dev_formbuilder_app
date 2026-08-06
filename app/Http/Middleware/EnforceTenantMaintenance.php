<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Branding\GuestBrandingPresenter;
use App\Support\Http\MaintenanceResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-tenant maintenance mode (Increment I5, PRD Feature #10: "a tenant-level toggle that blocks new guest
 * submissions and shows a configurable message on that tenant's public forms **while leaving the
 * authenticated admin app usable** (so an admin can turn it back off)").
 *
 * That parenthesis is the whole design. This middleware is mounted ONLY on the guest surfaces — the public
 * runtime shell in routes/tenant.php and both `api/v1/public` groups in routes/api.php — and never on the
 * authenticated tenant group. A tenant cannot lock itself out.
 *
 * ⚠️ IT IS MOUNTED AFTER THE TENANT-RESOLVING MIDDLEWARE, AND THE ORDER IS LOAD-BEARING. On the shell path
 * the flag is read off the tenant stancl has ALREADY resolved and bound — zero extra queries, which is the
 * entire reason `maintenance_mode` is a `tenants` column rather than a `settings` row (see
 * 2026_08_06_000004's docblock). If this ever sorts ahead of the identification middleware there is no
 * bound tenant, {@see resolveTenant()} finds nothing, every request passes through, and the feature is
 * DEAD WITH A GREEN BUILD — no error, no log line, just forms that keep serving. `TenancyMiddlewarePriorityTest`
 * asserts the resolved order for exactly that reason; do not add this class to `$middleware->priority()`
 * without re-reading it (an unlisted middleware keeps its declared slot, which is what we want).
 *
 * ── THE API PATH COSTS ONE QUERY, AND THAT IS UNAVOIDABLE ──────────────────────────────────────────────
 * {@see EstablishGuestTenantContext} and `EstablishGuestDraftContext` verify a signed token and set the RLS
 * GUC; they never load a Tenant MODEL, because nothing downstream needed one. So this falls back to a
 * single primary-key lookup on the RLS-exempt `tenants` table. It is a POST-shaped path, not a hot render.
 *
 * ── THE WHOLE GUEST GROUP IS BLOCKED, NOT JUST THE SUBMIT ROUTE ────────────────────────────────────────
 * PRD #10 says "blocks new guest submissions", and blocking only `POST .../submissions` would satisfy the
 * letter of that while leaving `GET f/{shareToken}` open — so an already-mounted SPA (the offline PWA
 * keeps one alive for as long as the tab is) would keep fetching schema and queueing responses against a
 * form the tenant has taken down, and the respondent would find out at upload time.
 */
final class EnforceTenantMaintenance
{
    public function __construct(private readonly GuestBrandingPresenter $branding) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant();

        if ($tenant === null || ! $tenant->isUnderMaintenance()) {
            return $next($request);
        }

        // Branded, because this page REPLACES a form the respondent expected to see branded — an abrupt
        // switch to product-default colours reads as "wrong site", which is the opposite of reassuring.
        // Deferred behind a closure: only the HTML arm renders a document, so a paused API request never
        // pays for a branding lookup it would discard.
        return MaintenanceResponse::make(
            $request,
            $tenant->maintenanceNotice(),
            fn (): ?array => $this->branding->forGuest()['tokens'],
        );
    }

    /**
     * The already-resolved tenant when there is one (the shell path), else one PK lookup (the API path).
     *
     * Returns null rather than throwing when neither is available: this middleware is an availability
     * gate, not an identification one, and every surface it guards already has its own identification
     * failure mode (a 404 for an unknown slug, a 401 for a bad token) that says something more useful.
     */
    private function resolveTenant(): ?Tenant
    {
        if (app()->bound(TenantContract::class)) {
            $bound = app(TenantContract::class);

            if ($bound instanceof Tenant) {
                return $bound;
            }
        }

        $tenantId = TenantContext::currentTenantId();

        return $tenantId === null ? null : Tenant::query()->find($tenantId);
    }
}
