<?php

declare(strict_types=1);

namespace App\Jobs\Entitlements;

use App\Enums\QueueName;
use App\Enums\UsageMetric;
use App\Jobs\Maintenance\RollUpUsageCountersJob;
use App\Jobs\TenantAwareJob;
use App\Listeners\Entitlements\MeterSubmissionUsage;
use App\Models\Submission;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Entitlements\QuotaOverageNotification;
use App\Services\Entitlements\EntitlementService;
use App\Support\Branding\BrandPalette;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The per-tenant child of {@see RollUpUsageCountersJob} (H5b / ADR-0008 §D8/§D9) —
 * one dispatched per active tenant, so cross-tenant metering is the §D3 fan-out shape (never a single-tenant
 * job that would silently no-op on every tenant but one). Runs inside the base's transaction under the
 * tenant's adopted context, so every read/write below is RLS-scoped to that tenant.
 *
 * Four jobs, per the current calendar-month period:
 *   1. RECONCILE `submissions_count` from the authoritative `submissions` table — the never-block self-heal:
 *      the synchronous {@see MeterSubmissionUsage} is a best-effort fast path,
 *      and any drift/loss it swallowed is corrected here, so metering can never permanently skew the read model.
 *   2. MATERIALIZE the gauge levels (forms/storage/seats) into the period row for period-end historical
 *      reporting ({@see EntitlementService::usage()} reads gauges live, so these rows are reporting-only).
 *   3. STAMP `limit_snapshot` (+ `subscription_id`) so a historical report stays correct after a plan change.
 *   4. UPSELL: when `submissions_count` newly crosses its quota this reconcile, send the never-block overage
 *      notification once (monotonic submissions ⇒ the "newly crossed" test fires exactly once per period).
 */
#[Queue(QueueName::ScheduledMaintenance)]
final class ReconcileTenantUsageJob extends TenantAwareJob
{
    public function __construct(public readonly string $tenantId) {}

    protected function handleForTenant(): void
    {
        $entitlements = app(EntitlementService::class);
        $entitlements->forget($this->tenantId); // fresh plan + live gauges under the adopted context

        $now = Carbon::now();
        $periodStart = $now->copy()->startOfMonth()->toDateString();
        $periodEnd = $now->copy()->endOfMonth()->toDateString();

        /** @var string|null $subscriptionId the active subscription this period's rows FK to (nullable) */
        $subscriptionId = Subscription::query()->active()->latest('created_at')->value('id');

        // 1. submissions_count — authoritative COUNT over the period (never-block self-heal).
        $priorSubmissions = $this->currentValue(UsageMetric::SubmissionsCount, $periodStart);
        $submissionsCount = Submission::query()
            ->whereBetween('submitted_at', [$periodStart.' 00:00:00', $periodEnd.' 23:59:59'])
            ->count();
        $submissionsLimit = $entitlements->quota(UsageMetric::SubmissionsCount);
        $this->writeCounter(
            UsageMetric::SubmissionsCount, $submissionsCount, $submissionsLimit, $subscriptionId, $periodStart, $periodEnd,
        );

        // 2. Gauge levels (forms/storage/seats), materialized for period-end reporting + limit_snapshot.
        foreach ([UsageMetric::FormsCount, UsageMetric::StorageBytes, UsageMetric::ActiveSeats] as $gauge) {
            $this->writeCounter(
                $gauge, $entitlements->countGauge($gauge), $entitlements->quota($gauge), $subscriptionId, $periodStart, $periodEnd,
            );
        }

        // 3. Accumulated flow rows (api/exports/webhook) — the meter owns their value; only stamp the limit.
        foreach ([UsageMetric::ApiRequests, UsageMetric::ExportsCount, UsageMetric::WebhookDeliveries] as $flow) {
            $this->stampLimit($flow, $entitlements->quota($flow), $subscriptionId, $periodStart);
        }

        // 4. Never-block overage upsell — once, when submissions_count first crosses the quota this period.
        if ($submissionsLimit !== null && $submissionsCount > $submissionsLimit && $priorSubmissions <= $submissionsLimit) {
            $this->notifyOverage($submissionsCount, $submissionsLimit);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['queue' => QueueName::ScheduledMaintenance->value];
    }

    /** The existing metered value for a metric in the period, or 0. RLS-scoped to the adopted tenant. */
    private function currentValue(UsageMetric $metric, string $periodStart): int
    {
        return (int) DB::table('usage_counters')
            ->where('metric', $metric->value)
            ->where('period_start', $periodStart)
            ->value('value');
    }

    /**
     * Upsert the authoritative value + limit_snapshot for a metric's current-period row. `value` is
     * OVERWRITTEN (this is reconciliation, not accumulation); the strict-RLS WITH CHECK passes because
     * `tenant_id` is the adopted context; `last_incremented_at` is deliberately untouched (a reconcile is
     * not an increment).
     */
    private function writeCounter(
        UsageMetric $metric, int $value, ?int $limit, ?string $subscriptionId, string $periodStart, string $periodEnd,
    ): void {
        DB::insert(
            'INSERT INTO usage_counters '
            .'(tenant_id, subscription_id, metric, period_start, period_end, value, limit_snapshot, created_at, updated_at) '
            .'VALUES (?, ?, ?, ?, ?, ?, ?, now(), now()) '
            .'ON CONFLICT (tenant_id, metric, period_start) DO UPDATE SET '
            .'value = EXCLUDED.value, limit_snapshot = EXCLUDED.limit_snapshot, '
            .'subscription_id = EXCLUDED.subscription_id, updated_at = now()',
            [$this->tenantId, $subscriptionId, $metric->value, $periodStart, $periodEnd, $value, $limit],
        );
    }

    /** Stamp limit_snapshot + subscription_id on an accumulated flow row, if one exists (value untouched). */
    private function stampLimit(UsageMetric $metric, ?int $limit, ?string $subscriptionId, string $periodStart): void
    {
        DB::update(
            'UPDATE usage_counters SET limit_snapshot = ?, subscription_id = ?, updated_at = now() '
            .'WHERE metric = ? AND period_start = ?',
            [$limit, $subscriptionId, $metric->value, $periodStart],
        );
    }

    /**
     * Send the never-block overage upsell to the tenant's owner. Scalar-only, on-demand notifiable (§D5).
     * The owner is an active member, so their `users` row is visible under the tenant's RLS.
     */
    private function notifyOverage(int $used, int $limit): void
    {
        $tenant = Tenant::query()->whereKey($this->tenantId)->first(); // RLS-exempt central table
        $ownerId = $tenant?->owner_user_id;

        if ($tenant === null || $ownerId === null) {
            return;
        }

        $email = User::query()->whereKey($ownerId)->value('email');

        if (! is_string($email) || $email === '') {
            return;
        }

        // Inside handleForTenant(), so the GUC is live and BrandPalette can read the plan (H23a4). The
        // notification itself is delivered by SendQueuedNotifications, which is NOT a TenantAwareJob and
        // would have no context to read it from.
        Notification::route('mail', $email)
            ->notify(
                (new QuotaOverageNotification($tenant->name, $used, $limit))
                    ->withBrand(BrandPalette::forTenant($tenant))
            );
    }
}
