<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UsageMetric;
use App\Exceptions\Entitlements\RateLimitExceededException;
use App\Services\Entitlements\QuotaGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the per-month `api_requests` quota (H5c / ADR-0008 §D4) on the /api/v1 Group-B surface — the other
 * half of what {@see MeterApiUsage} started metering in H5b. Pre-checks the metered current-period usage
 * against the plan quota and throws {@see RateLimitExceededException} (429 +
 * Retry-After) when it has been reached.
 *
 * Ordered AFTER `feature:api_access` + `throttle:api` and BEFORE {@see MeterApiUsage}: a no-feature tenant is
 * 402'd first, a burst-abusive one is 429'd by the throttle first, and because this throws before `$next` a
 * request rejected here never reaches the meter — so a 429'd request is not itself counted (the first `limit`
 * requests succeed; request `limit + 1` is refused). A null/0 quota (unlimited, or the Free "no access"
 * sentinel granted only via a grandfather override) never 429s — see {@see QuotaGuard::assertWithinRateQuota()}.
 */
final class EnforceApiRequestQuota
{
    public function __construct(private readonly QuotaGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->guard->assertWithinRateQuota(UsageMetric::ApiRequests);

        return $next($request);
    }
}
