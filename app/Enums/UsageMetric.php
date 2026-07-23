<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seven metered quantities (data-dictionary.md §16/§18 `UsageMetric`, keys inside `plans.quotas` and
 * the `usage_counters.metric` column; ADR-0008). The value ORDER matches the dictionary's enum catalog.
 * The `usage_counters_metric_check` CHECK is generated from {@see values()} so enum and constraint cannot
 * drift.
 *
 * Enforcement (H5b) splits these three ways (ADR-0008 §D4, pricing-feature-gating-matrix.md §2), a
 * classification that lives with the consumer rather than here:
 *   - hard-blocked: `forms_count`, `storage_bytes`, `active_seats` (resource-provisioning limits);
 *   - never hard-blocked: `submissions_count` (a respondent's completed submission is never rejected over
 *     the tenant's billing status — overage notifies/upsells, it never gates data collection);
 *   - rate-limitable: `api_requests`, `webhook_deliveries` (throttled, never destroyed).
 *
 * Flagged in the dictionary as the enum most likely to graduate to a lookup table if per-integration or
 * per-feature metering grows and needs to be tenant/admin-configurable rather than code-defined.
 */
enum UsageMetric: string
{
    case SubmissionsCount = 'submissions_count';
    case StorageBytes = 'storage_bytes';
    case ApiRequests = 'api_requests';
    case WebhookDeliveries = 'webhook_deliveries';
    case ActiveSeats = 'active_seats';
    case FormsCount = 'forms_count';
    case ExportsCount = 'exports_count';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
