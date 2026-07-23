<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UsageMetric;
use App\Services\Entitlements\UsageMeter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meters `api_requests` (H5b / ADR-0008 §D4) for the /api/v1 Group-B surface. METER ONLY — it does NOT
 * enforce the per-month quota with a 429: that binding lands in H5c, alongside the `api_access` feature
 * gate and the grandfather override, because Free's `api_requests` quota is 0 and enforcing it now would
 * back-door an API feature-gate before existing tenants are grandfathered (a merge-day regression). The
 * per-minute burst ceiling (`throttle:api`, 600/min) already governs abuse; this only records volume.
 *
 * Sits AFTER `AuthenticateApiToken` + `throttle:api` in the Group-B stack, so tenant context is established
 * and the strict-RLS increment lands on the right tenant. Metering runs after the response is produced so a
 * failed request is not counted differently from a successful one, and {@see UsageMeter} swallows its own
 * failures so metering can never affect the response.
 */
final class MeterApiUsage
{
    public function __construct(private readonly UsageMeter $meter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->meter->increment(UsageMetric::ApiRequests);

        return $response;
    }
}
