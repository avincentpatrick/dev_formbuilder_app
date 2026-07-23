<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Enums\UsageMetric;
use App\Jobs\Entitlements\ReconcileTenantUsageJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records FLOW metering (H5b / ADR-0008 §D8) — a monotonic per-period increment into the current
 * calendar-month `usage_counters` row for the current tenant. Drives `submissions_count` (never-block),
 * `exports_count`, and `api_requests`. GAUGE metrics are NEVER metered here — they are computed live
 * ({@see EntitlementService::countGauge()}) because a running aggregate drifts.
 *
 * NEVER-BLOCK BY CONSTRUCTION: the whole body is wrapped in a swallow-and-log guard, so a metering failure
 * can never surface into the caller — a respondent's submission must persist even if its meter throws. For
 * `submissions_count` it also runs post-commit, so it can never roll a submission back; and the nightly
 * {@see ReconcileTenantUsageJob} reconciles `submissions_count` from the
 * authoritative `submissions` table, so any swallowed increment self-heals within a day.
 *
 * Not `final`: a test substitutes a throwing double to prove a broken meter still cannot lose a respondent's
 * already-committed submission.
 */
class UsageMeter
{
    /**
     * Add `$by` to the current tenant's current-period counter for `$metric`, creating the period row on
     * first use. Concurrency-safe via Postgres `ON CONFLICT`, so two simultaneous submissions cannot 23505
     * on the unique `(tenant_id, metric, period_start)`. A no-op off-tenant. `$by` is 1 for a count and the
     * byte size for a byte metric.
     */
    public function increment(UsageMetric $metric, int $by = 1): void
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return;
        }

        try {
            $now = Carbon::now();

            // Bound raw upsert: the strict-RLS WITH CHECK is satisfied by passing the current tenant id, and
            // the DO UPDATE only ever touches the same tenant's row (tenant_id is in the unique key). The
            // `id` bigint identity, created_at/updated_at and the period_end are set here; limit_snapshot is
            // left for the rollup to stamp (keeping the hot path free of an entitlement query).
            DB::insert(
                'INSERT INTO usage_counters '
                .'(tenant_id, metric, period_start, period_end, value, last_incremented_at, created_at, updated_at) '
                .'VALUES (?, ?, ?, ?, ?, now(), now(), now()) '
                .'ON CONFLICT (tenant_id, metric, period_start) DO UPDATE SET '
                .'value = usage_counters.value + EXCLUDED.value, last_incremented_at = now(), updated_at = now()',
                [
                    $tenantId,
                    $metric->value,
                    $now->copy()->startOfMonth()->toDateString(),
                    $now->copy()->endOfMonth()->toDateString(),
                    $by,
                ],
            );
        } catch (Throwable $e) {
            // Metering must never break a caller (the never-block guarantee). Logged, not rethrown.
            Log::warning('Usage metering increment failed.', [
                'metric' => $metric->value,
                'tenant_id' => $tenantId,
                'exception_class' => $e::class,
            ]);
        }
    }
}
