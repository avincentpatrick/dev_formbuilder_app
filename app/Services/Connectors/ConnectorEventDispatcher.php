<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Events\DomainEvent;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Models\ConnectionSubscription;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookEventDispatcher;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fans a post-commit {@see DomainEvent} out to the tenant's native-connector subscriptions (H15a) — the
 * {@see WebhookEventDispatcher} twin for the connector channel. Called by the four
 * thin auto-discovered listeners in `App\Listeners\Connectors`, so all four events share one matching +
 * row-creation + enqueue path.
 *
 * Matching adds one condition the webhook side has no analogue for: the subscription's CONNECTION must still
 * be active. A rule whose grant died is not a rule the tenant can fix by editing it, and creating deliveries
 * for it would manufacture a backlog of rows that can only dead-letter.
 *
 * Rows go in the SHARED ledger (`webhook_deliveries`, generalized in H15a), created idempotently on
 * `(connection_subscription_id, event_id)` — a domain event's `event_id` is stable, so a re-emit (the H12a
 * at-least-once sweep events) fans out at most one delivery per subscription. Metering and the monthly quota
 * hard-cap live in the delivery job (first attempt only), not here.
 *
 * Runs with tenant context established in both call contexts — the request path and the H12a sweep's tenant
 * transaction — so the RLS-scoped query and inserts are correct either way.
 */
final class ConnectorEventDispatcher
{
    public function fanOut(DomainEvent $event): void
    {
        $tenantId = $event->tenantId();

        if ($tenantId === null) {
            return; // an off-tenant event has no subscriber scope
        }

        /** @var array<string, mixed> $data */
        $data = $event->data();
        $formId = isset($data['form_id']) ? (string) $data['form_id'] : null;
        $eventType = $event->eventType()->value;

        $subscriptions = ConnectionSubscription::query()
            ->where('status', ConnectorSubscriptionStatus::Active->value)
            ->whereJsonContains('event_types', $eventType)
            ->where(function (Builder $query) use ($formId): void {
                $query->whereNull('form_id');

                if ($formId !== null) {
                    $query->orWhere('form_id', $formId);
                }
            })
            ->whereHas('grant', function (Builder $query): void {
                $query->where('status', ConnectionStatus::Active->value);
            })
            ->get();

        foreach ($subscriptions as $subscription) {
            $delivery = WebhookDelivery::query()->firstOrCreate(
                [
                    'connection_subscription_id' => $subscription->getKey(),
                    'event_id' => $event->eventId,
                ],
                [
                    'tenant_id' => $tenantId,
                    'webhook_endpoint_id' => null, // the ledger's XOR owner check: exactly one side is set
                    'event_type' => $eventType,
                    'payload' => $event->envelope(),
                    'status' => WebhookDeliveryStatus::Pending->value,
                    'max_attempts' => (int) config('webhooks.max_attempts', 10),
                ],
            );

            if ($delivery->wasRecentlyCreated) {
                DeliverConnectorMessageJob::dispatch($tenantId, (string) $delivery->getKey());
            }
        }
    }
}
