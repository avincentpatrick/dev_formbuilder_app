<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Jobs\Webhooks\SweepTenantWebhookRetriesJob;
use App\Models\WebhookDelivery;
use Carbon\CarbonInterface;

/**
 * Re-dispatches deliveries whose retry-ladder time has arrived (H13a). The one arithmetic-free
 * source shared by {@see SweepTenantWebhookRetriesJob}: it selects the current tenant's
 * `failed` deliveries whose `next_retry_at` has passed and which still have attempts remaining, and enqueues
 * one delivery job per row. The job itself decides the outcome and schedules the following retry.
 *
 * BOTH OUTBOUND CHANNELS (H15a): `webhook_deliveries` is the shared ledger, so a row is owned by either a
 * webhook endpoint or a connector subscription and the sweep dispatches the matching job. The owner columns
 * are mutually exclusive by DB CHECK, which is what makes this a total two-way branch rather than a guess.
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
                $tenantId = (string) $delivery->tenant_id;
                $deliveryId = (string) $delivery->getKey();

                if ($delivery->webhook_endpoint_id !== null) {
                    DeliverWebhookJob::dispatch($tenantId, $deliveryId);

                    return;
                }

                DeliverConnectorMessageJob::dispatch($tenantId, $deliveryId);
            });
    }
}
