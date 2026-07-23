<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seven metered quantities (data-dictionary.md §16/§18 `UsageMetric`, keys inside `plans.quotas` and
 * the `usage_counters.metric` column; ADR-0008). The value ORDER matches the dictionary's enum catalog.
 * The `usage_counters_metric_check` CHECK is generated from {@see values()} so enum and constraint cannot
 * drift.
 *
 * Enforcement (H5b) splits these three ways (ADR-0008 §D4, pricing-feature-gating-matrix.md §2), promoted
 * from prose into {@see enforcementMode()} so the split is code the guard/meter read, not a comment they
 * can drift from (the {@see ResourceCapacity::anyPermission()} precedent — a metric → semantics map that
 * belongs with the enum):
 *   - hard-blocked: `forms_count`, `storage_bytes`, `active_seats` (resource-provisioning limits);
 *   - never hard-blocked: `submissions_count` (a respondent's completed submission is never rejected over
 *     the tenant's billing status — overage notifies/upsells, it never gates data collection);
 *   - rate-limitable: `api_requests`, `webhook_deliveries` (throttled, never destroyed);
 *   - `exports_count` is metered but unclassified (§D4 places it in none of the three).
 *
 * Orthogonally, {@see isGauge()} splits current-LEVEL gauges (enforced by a live COUNT/SUM under RLS at
 * the mutation site — they move down as well as up) from monotonic per-period FLOW counters (metered by
 * increment). The two happen to coincide with the hard-block set today, but are declared independently so
 * a future non-hard-blocked gauge does not silently mis-resolve.
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

    /** How this metric's quota is enforced (H5b / ADR-0008 §D4). See {@see EnforcementMode}. */
    public function enforcementMode(): EnforcementMode
    {
        return match ($this) {
            self::FormsCount, self::StorageBytes, self::ActiveSeats => EnforcementMode::HardBlock,
            self::SubmissionsCount => EnforcementMode::NeverBlock,
            self::ApiRequests, self::WebhookDeliveries => EnforcementMode::RateLimit,
            self::ExportsCount => EnforcementMode::Unclassified,
        };
    }

    /**
     * A GAUGE is a current-level quantity (forms that exist, bytes stored, seats occupied) — enforced and
     * reported by a live COUNT/SUM under RLS, never a maintained running aggregate, because it decreases on
     * archive/delete/remove. Everything else is a monotonic per-period FLOW counter metered by increment.
     */
    public function isGauge(): bool
    {
        return match ($this) {
            self::FormsCount, self::StorageBytes, self::ActiveSeats => true,
            self::SubmissionsCount, self::ApiRequests, self::WebhookDeliveries, self::ExportsCount => false,
        };
    }
}
