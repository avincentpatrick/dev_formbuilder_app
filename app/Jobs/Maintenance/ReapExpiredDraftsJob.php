<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Jobs\MaintenanceJob;
use App\Jobs\Submissions\ReapTenantDraftsJob;

/**
 * The nightly draft-expiry reaper (Increment H9b) — cross-tenant, so it is a {@see MaintenanceJob}, never a
 * single-tenant `applyLocal` job (which would silently no-op on every tenant but the one whose context
 * happened to be set). Its sole tenant-touching side effect is the §D3 fan-out: one
 * {@see ReapTenantDraftsJob} per active tenant, each of which adopts its own tenant context and hard-deletes
 * the drafts whose `draft_expires_at` has passed.
 *
 * Holds NO tenant context and touches only the RLS-exempt `tenants` table (via {@see activeTenants()}). The
 * constructor takes no arguments because routes/console.php schedules it by class-string.
 */
final class ReapExpiredDraftsJob extends MaintenanceJob
{
    protected function sweep(): void
    {
        foreach ($this->activeTenants() as $tenant) {
            ReapTenantDraftsJob::dispatch((string) $tenant->getKey());
        }
    }
}
