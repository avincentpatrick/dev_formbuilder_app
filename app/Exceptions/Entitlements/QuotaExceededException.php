<?php

declare(strict_types=1);

namespace App\Exceptions\Entitlements;

use App\Enums\UsageMetric;
use App\Exceptions\Authorization\GrantException;
use App\Services\Entitlements\QuotaGuard;
use RuntimeException;

/**
 * A hard-block quota refused at a create/upload/invite site (H5b / ADR-0008 §D4), raised by
 * {@see QuotaGuard}. Only the three provisioning gauges reach here — `forms_count`, `storage_bytes`,
 * `active_seats`; `submissions_count` is in the never-block class and can NEVER produce this.
 *
 * Carries the metric, the plan limit and the observed usage so the surface can say precisely what was hit
 * and what the ceiling is — an upgrade prompt, not a bare "forbidden". The code is metric-specific
 * (`quota_exceeded_forms_count`) so an integration consumer can branch on exactly which limit bit; the
 * status is 402 Payment Required ("upgrade to proceed"), deliberately distinct from a 403 authorization
 * refusal ({@see GrantException}).
 */
final class QuotaExceededException extends RuntimeException
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
            UsageMetric::FormsCount => "You have reached your plan's limit of {$limit} forms. Archive a form or upgrade your plan to create more.",
            UsageMetric::StorageBytes => "You have reached your plan's storage limit. Free up space or upgrade your plan to upload more.",
            UsageMetric::ActiveSeats => "You have reached your plan's limit of {$limit} members. Remove a member or upgrade your plan to invite more.",
            UsageMetric::WebhookEndpointsCount => "You have reached your plan's limit of {$limit} webhook endpoints. Delete an endpoint or upgrade your plan to add more.",
            default => "You have reached your plan's {$metric->value} limit of {$limit}. Upgrade your plan to continue.",
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

    /** The stable, machine-readable error code (never the HTTP status text, never translated). */
    public function code(): string
    {
        return 'quota_exceeded_'.$this->metric->value;
    }

    /** The HTTP status for the /api/v1 envelope — 402 Payment Required ("upgrade to proceed"). */
    public function status(): int
    {
        return 402;
    }

    /**
     * Endpoint-specific context for the /api/v1 envelope's `details`.
     *
     * @return array{metric: string, limit: int, used: int}
     */
    public function details(): array
    {
        return ['metric' => $this->metric->value, 'limit' => $this->limit, 'used' => $this->used];
    }
}
