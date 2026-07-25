<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Jobs\Forms\SweepTenantScheduledFormsJob;
use App\Jobs\MaintenanceJob;
use App\Services\Submissions\FormAcceptanceGuard;

/**
 * The scheduled-forms state-flip sweep (Increment H12a) — cross-tenant, so it is a {@see MaintenanceJob}, never
 * a single-tenant `applyLocal` job. Its sole tenant-touching side effect is the §D3 fan-out: one
 * {@see SweepTenantScheduledFormsJob} per active tenant, each of which adopts its own context and advances that
 * tenant's published forms across their open/close boundaries (emitting `form.opened`/`form.closed`).
 *
 * Runs every five minutes (routes/console.php), far more often than the nightly reaper/rollup, because it
 * announces time boundaries. That cadence only affects EVENT latency: submission acceptance is enforced live at
 * write time ({@see FormAcceptanceGuard}), so a form closes for respondents the
 * instant `closes_at` passes regardless of when this next runs.
 *
 * Holds NO tenant context and touches only the RLS-exempt `tenants` table (via {@see activeTenants()}). The
 * constructor takes no arguments because routes/console.php schedules it by class-string.
 */
final class SweepScheduledFormsJob extends MaintenanceJob
{
    protected function sweep(): void
    {
        foreach ($this->activeTenants() as $tenant) {
            SweepTenantScheduledFormsJob::dispatch((string) $tenant->getKey());
        }
    }
}
