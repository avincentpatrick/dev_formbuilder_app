<?php

declare(strict_types=1);

namespace App\Exceptions\Entitlements;

use App\Enums\UsageMetric;
use App\Services\Entitlements\QuotaGuard;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A monthly usage-quota rate limit refused (H5c / ADR-0008 §D4), raised by
 * {@see QuotaGuard::assertWithinRateQuota()}. Only the rate-limit class reaches here — `api_requests` and
 * (H13) `webhook_deliveries`: the metered current-period usage has reached the plan's per-month quota.
 *
 * Distinct from the hard-block 402 ({@see QuotaExceededException}): a rate limit is a 429 with a `Retry-After`
 * pointing at the period reset (the metering window is the calendar month), mirroring the burst-throttle
 * envelope so an integration's existing 429 backoff already handles it. The code is metric-specific
 * (`rate_limit_exceeded_api_requests`) so a consumer can branch on which monthly ceiling it hit.
 */
final class RateLimitExceededException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly UsageMetric $metric,
        private readonly int $limit,
        private readonly int $used,
    ) {
        parent::__construct($message);
    }

    public static function forMetric(UsageMetric $metric, int $limit, int $used): self
    {
        $message = match ($metric) {
            UsageMetric::ApiRequests => "You have reached your plan's monthly limit of {$limit} API requests. It resets at the start of next month — upgrade your plan for a higher limit.",
            UsageMetric::WebhookDeliveries => "You have reached your plan's monthly limit of {$limit} webhook deliveries. It resets at the start of next month — upgrade your plan for a higher limit.",
            default => "You have reached your plan's monthly {$metric->value} limit of {$limit}. It resets at the start of next month.",
        };

        return new self($message, $metric, $limit, $used);
    }

    public function metric(): UsageMetric
    {
        return $this->metric;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function used(): int
    {
        return $this->used;
    }

    /** The stable, machine-readable error code (metric-specific so a consumer branches on which ceiling). */
    public function code(): string
    {
        return 'rate_limit_exceeded_'.$this->metric->value;
    }

    /** The HTTP status for the /api/v1 envelope — 429 Too Many Requests. */
    public function status(): int
    {
        return 429;
    }

    /**
     * @return array{metric: string, limit: int, used: int}
     */
    public function details(): array
    {
        return ['metric' => $this->metric->value, 'limit' => $this->limit, 'used' => $this->used];
    }

    /**
     * `Retry-After` in seconds until the monthly window resets (the metering period is the calendar month, so
     * the quota frees at the start of next month). At least 1 so a client never busy-loops on 0.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $resetsAt = Carbon::now()->startOfMonth()->addMonth()->getTimestamp();
        $retryAfter = max(1, $resetsAt - Carbon::now()->getTimestamp());

        return ['Retry-After' => (string) $retryAfter];
    }
}
