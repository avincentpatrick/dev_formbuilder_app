<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Jobs\Connectors\RefreshTenantConnectorTokensJob;
use App\Jobs\MaintenanceJob;

/**
 * The OAuth token-refresh sweep (ADR-0009 §D6, H15a) — cross-tenant, so it is a {@see MaintenanceJob}, never
 * a single-tenant `applyLocal` job (which would silently no-op on every tenant but the one whose context
 * happened to be set). Its sole tenant-touching side effect is the ADR-0007 §D3 fan-out: one
 * {@see RefreshTenantConnectorTokensJob} per active tenant.
 *
 * Runs hourly (routes/console.php), against a refresh lead time of two hours by default — so a grant is
 * renewed with a full sweep cycle to spare and a single failed sweep cannot expire a token. Holds NO tenant
 * context and touches only the RLS-exempt `tenants` table; the constructor takes no arguments because
 * routes/console.php schedules it by class-string.
 */
final class RefreshConnectorTokensJob extends MaintenanceJob
{
    protected function sweep(): void
    {
        foreach ($this->activeTenants() as $tenant) {
            RefreshTenantConnectorTokensJob::dispatch((string) $tenant->getKey());
        }
    }
}
