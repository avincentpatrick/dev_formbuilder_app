<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Jobs\Webhooks\SweepTenantWebhookRetriesJob;
use App\Models\WebhookDelivery;
use Carbon\CarbonInterface;

/**
 * Re-dispatches webhook deliveries whose retry-ladder time has arrived (H13a). The one arithmetic-free
 * source shared by {@see SweepTenantWebhookRetriesJob}: it selects the current tenant's
 * `failed` deliveries whose `next_retry_at` has passed and which still have attempts remaining, and enqueues
 * one {@see DeliverWebhookJob} per row. The job itself decides the outcome and schedules the following retry.
 *
 * RLS-scoped: called only from inside a per-tenant job transaction, so the query sees exactly that tenant's
 * deliveries.
 */
final class WebhookRetrySweeper
{
    public function sweep(CarbonInterface $now): void
    {
        WebhookDelivery::query()
            ->where('status', WebhookDeliveryStatus::Failed->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $now)
            ->whereColumn('attempt_count', '<', 'max_attempts')
            ->orderBy('next_retry_at')
            ->each(function (WebhookDelivery $delivery): void {
                DeliverWebhookJob::dispatch((string) $delivery->tenant_id, (string) $delivery->getKey());
            });
    }
}
