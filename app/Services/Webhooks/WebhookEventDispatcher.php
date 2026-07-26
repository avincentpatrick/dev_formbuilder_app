<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEndpointStatus;
use App\Events\DomainEvent;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fans a post-commit {@see DomainEvent} out to the tenant's subscribed webhook endpoints (H13a). Called by
 * the four thin auto-discovered listeners in `App\Listeners\Webhooks`, so all four events share one
 * matching + row-creation + enqueue path.
 *
 * Matching: an endpoint receives the event when it is `active`, subscribes to the event type
 * (`event_types` contains it), and is either tenant-wide (`form_id` null) or scoped to the event's form. For
 * each match a `webhook_deliveries` row is created idempotently on `(endpoint, event_id)` — a domain event's
 * `event_id` is stable, so a re-emit (e.g. the at-least-once sweep events) fans out at most one delivery per
 * endpoint — carrying the full event envelope as the payload; a newly-created row is dispatched to the
 * `webhooks` queue for delivery. Metering + the monthly quota hard-cap live in the delivery job (first
 * attempt only), not here.
 *
 * Runs with tenant context established in BOTH call contexts: the request path (submission/publish events,
 * post-commit) and inside the H12a sweep's tenant transaction (form.opened/closed), so the RLS-scoped
 * endpoint query and inserts are correct either way.
 */
final class WebhookEventDispatcher
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

        $endpoints = WebhookEndpoint::query()
            ->where('status', WebhookEndpointStatus::Active->value)
            ->whereJsonContains('event_types', $eventType)
            ->where(function (Builder $query) use ($formId): void {
                $query->whereNull('form_id');

                if ($formId !== null) {
                    $query->orWhere('form_id', $formId);
                }
            })
            ->get();

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->firstOrCreate(
                [
                    'webhook_endpoint_id' => $endpoint->getKey(),
                    'event_id' => $event->eventId,
                ],
                [
                    'tenant_id' => $tenantId,
                    'event_type' => $eventType,
                    'payload' => $event->envelope(),
                    'status' => WebhookDeliveryStatus::Pending->value,
                    'max_attempts' => (int) config('webhooks.max_attempts', 10),
                ],
            );

            if ($delivery->wasRecentlyCreated) {
                DeliverWebhookJob::dispatch($tenantId, (string) $delivery->getKey());
            }
        }
    }
}
