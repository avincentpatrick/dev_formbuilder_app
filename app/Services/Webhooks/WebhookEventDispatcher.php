<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEndpointStatus;
use App\Events\DomainEvent;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fans a post-commit {@see DomainEvent} out to the tenant's subscribed webhook endpoints (H13a). Called by
 * the eight thin auto-discovered listeners in `App\Listeners\Webhooks`, so all eight events share one
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
 * ⚠️ THE TENANT SCOPE IS THE EVENT'S, TAKEN FROM THE EVENT, AND THIS CLASS ESTABLISHES IT (M3). Until
 * 2026-08-19 this docblock instead stated a PRECONDITION — "runs with tenant context established in both
 * call contexts" — and nothing enforced it: `$tenantId` was computed and then used only for a null check and
 * to stamp the delivery row, while the query deciding WHICH endpoints receive the event was a bare
 * `WebhookEndpoint::query()` resolved by the ambient RLS GUC. It happened to be right because all eight
 * listeners are synchronous. Add `ShouldQueue` to one of them — one line, with a plausible motive — or call
 * this from a console sweep that tears the GUC down between tenants, and the read-side RLS trap returns
 * ZERO endpoints with no exception and no log line: every tenant's webhooks stop, totally and silently,
 * with the suite green. {@see TenantContext::runFor()} makes the scope explicit instead, so the caller's
 * context no longer decides whether this works. The MISMATCH case never had this problem — an ambient
 * tenant B with an event for tenant A made the INSERT fail 42501 — so what is closed here is specifically
 * the UNSET case.
 */
final class WebhookEventDispatcher
{
    public function __construct(private readonly WebhookPayloadArchive $archive) {}

    public function fanOut(DomainEvent $event): void
    {
        $tenantId = $event->tenantId();

        if ($tenantId === null) {
            return; // an off-tenant event has no subscriber scope
        }

        TenantContext::runFor($tenantId, function () use ($event, $tenantId): void {
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
                    $this->maybeArchivePayload($delivery);

                    DeliverWebhookJob::dispatch($tenantId, (string) $delivery->getKey());
                }
            }
        });
    }

    /**
     * Off-load an oversized envelope to attachment storage (H13b), trimming the inline `payload` to a marker
     * and pointing `payload_attachment_id` at the archive; the delivery job reads it back before signing. A
     * sub-threshold payload (today's ID-only envelopes) stays fully inline — no archive row is written.
     */
    private function maybeArchivePayload(WebhookDelivery $delivery): void
    {
        $threshold = (int) config('webhooks.payload_archive_threshold_bytes', 32768);

        $encoded = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        if ($threshold <= 0 || strlen($encoded) <= $threshold) {
            return;
        }

        $attachmentId = $this->archive->archive($delivery, $delivery->payload);

        $delivery->forceFill([
            'payload_attachment_id' => $attachmentId,
            'payload' => ['archived' => true],
        ])->save();
    }
}
