<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Jobs\MaintenanceJob;
use App\Jobs\Webhooks\SweepTenantWebhookRetriesJob;

/**
 * The webhook retry-ladder sweep (H13a) — cross-tenant, so it is a {@see MaintenanceJob}, never a
 * single-tenant `applyLocal` job. Its sole tenant-touching side effect is the §D3 fan-out: one
 * {@see SweepTenantWebhookRetriesJob} per active tenant, each re-dispatching that tenant's `failed`
 * deliveries whose `next_retry_at` has arrived.
 *
 * Runs every five minutes (routes/console.php) — finer than the retry ladder's 1-minute first step is
 * unnecessary, and coarser would add avoidable latency to a due retry. Holds NO tenant context and touches
 * only the RLS-exempt `tenants` table (via {@see activeTenants()}); the constructor takes no arguments
 * because routes/console.php schedules it by class-string.
 */
final class SweepWebhookRetriesJob extends MaintenanceJob
{
    protected function sweep(): void
    {
        foreach ($this->activeTenants() as $tenant) {
            SweepTenantWebhookRetriesJob::dispatch((string) $tenant->getKey());
        }
    }
}
