<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Jobs\Entitlements\ReconcileTenantUsageJob;
use App\Jobs\MaintenanceJob;

/**
 * The nightly usage-counter rollup (H5b / ADR-0008 §D9) — cross-tenant, so it is a {@see MaintenanceJob},
 * never a single-tenant `applyLocal` job (which would silently no-op on every tenant but the one whose
 * context happened to be set). Its sole tenant-touching side effect is the §D3 fan-out: one
 * {@see ReconcileTenantUsageJob} per active tenant, each of which adopts its own tenant context to
 * reconcile submissions_count, materialize gauges, stamp limit_snapshot, and send the never-block overage
 * upsell.
 *
 * Holds NO tenant context and touches only the RLS-exempt `tenants` table (via {@see activeTenants()}),
 * which is exactly what a MaintenanceJob is permitted to do. The constructor takes no arguments because
 * routes/console.php schedules it by class-string.
 */
final class RollUpUsageCountersJob extends MaintenanceJob
{
    protected function sweep(): void
    {
        foreach ($this->activeTenants() as $tenant) {
            ReconcileTenantUsageJob::dispatch((string) $tenant->getKey());
        }
    }
}
