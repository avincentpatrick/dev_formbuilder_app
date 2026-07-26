<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Enums\QueueName;
use App\Jobs\Maintenance\SweepWebhookRetriesJob;
use App\Jobs\TenantAwareJob;
use App\Services\Webhooks\WebhookRetrySweeper;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;

/**
 * The per-tenant child of {@see SweepWebhookRetriesJob} (H13a) — one dispatched per active tenant, so
 * re-dispatching due retries is the §D3 cross-tenant fan-out shape. Runs inside the base's transaction under
 * the tenant's adopted context, so {@see WebhookRetrySweeper}'s query is RLS-scoped to that tenant. Runs on
 * `scheduled-maintenance` (the sweep queue), while the deliveries it enqueues run on `webhooks`.
 */
#[Queue(QueueName::ScheduledMaintenance)]
final class SweepTenantWebhookRetriesJob extends TenantAwareJob
{
    public function __construct(public readonly string $tenantId) {}

    protected function handleForTenant(): void
    {
        app(WebhookRetrySweeper::class)->sweep(Carbon::now());
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['queue' => QueueName::ScheduledMaintenance->value];
    }
}
