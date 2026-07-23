<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Entitlements\FeatureGateException;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\QuotaGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plan feature-gate (H5c / ADR-0008 §D5) — the first consumer of {@see EntitlementService::feature()}.
 * Applied as `feature:<key>` alongside the existing `ability:`/`can:` gates: the ability scopes the token,
 * `can:` re-checks RBAC, and this checks the tenant's plan. A grandfathered tenant's legacy override resolves
 * the key to `true` (it wins over the plan flags), so it passes.
 *
 * Blocks ONLY when a plan RESOLVES and denies the feature. `feature()` fail-closes to `false` off-tenant /
 * with an unseeded catalog, but the gate defers to `currentPlan()`: a null plan means "no catalog to gate
 * against" and passes through — the same "no catalog ⇒ no enforcement" stance as {@see QuotaGuard}'s
 * null-quota. Production always seeds the catalog, so an unsubscribed tenant resolves to the real `free` plan
 * and IS gated; the plan-free test suite stays inert. The gated routes all sit behind tenant context, so a
 * guest never reaches one — this is not a security fail-open, it is a "dev/test has no plans" pass-through.
 */
final class RequireFeature
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        if ($this->entitlements->currentPlan() !== null && ! $this->entitlements->feature($key)) {
            throw FeatureGateException::forKey($key);
        }

        return $next($request);
    }
}
