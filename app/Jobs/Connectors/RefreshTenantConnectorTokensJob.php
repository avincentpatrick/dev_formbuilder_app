<?php

declare(strict_types=1);

namespace App\Jobs\Connectors;

use App\Enums\QueueName;
use App\Jobs\Maintenance\RefreshConnectorTokensJob;
use App\Jobs\TenantAwareJob;
use App\Services\Connectors\ConnectionTokenRefresher;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;

/**
 * The per-tenant child of {@see RefreshConnectorTokensJob} (ADR-0009 §D6, H15a) — one dispatched per active
 * tenant, so renewing grants is the ADR-0007 §D3 cross-tenant fan-out shape. Runs inside the base's
 * transaction under the tenant's adopted context, so {@see ConnectionTokenRefresher}'s query is RLS-scoped
 * to that tenant and a refreshed token can only ever be written back to the tenant that owns it.
 *
 * Runs on `scheduled-maintenance` (the sweep queue), like its webhook-retry sibling — the outbound calls it
 * makes are provider token endpoints, not deliveries, so they do not belong on the delivery queue.
 */
#[Queue(QueueName::ScheduledMaintenance)]
final class RefreshTenantConnectorTokensJob extends TenantAwareJob
{
    public function __construct(public readonly string $tenantId) {}

    protected function handleForTenant(): void
    {
        app(ConnectionTokenRefresher::class)->sweep(Carbon::now());
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['queue' => QueueName::ScheduledMaintenance->value];
    }
}
