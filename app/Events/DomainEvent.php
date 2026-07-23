<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Ramsey\Uuid\Uuid;

/**
 * Base for every post-commit domain event — the ONE catalog webhooks (H13), notifications and the realtime
 * push consume (technical-architecture.md §4.1/§7.4, data-dictionary §22). A concrete subclass names its
 * {@see DomainEventType} and exposes an all-scalar payload; this base stamps the stable envelope fields.
 *
 * Two invariants make the base worth its existence:
 *
 *  1. **`event_id` is generated ONCE, at construction** (§7.4: "a stable `event_id` … generated once per
 *     event, not once per delivery attempt"). The event object is built once at the emission site and the
 *     same instance is handed to every listener and serialized once onto the queue, so a UUIDv7 assigned
 *     in the constructor is fixed for the life of the event across all delivery attempts.
 *
 *  2. **The payload is SCALARS ONLY, never an Eloquent model** (ADR-0007 §D5). A domain event is designed to
 *     be carried by a `ShouldQueue` listener that runs post-commit under a NULL tenant GUC — a serialized
 *     model would be restored before context is re-established and fail closed under RLS. H4 dispatches
 *     these synchronously-after-commit with no queued listener yet, but shaping the payload as scalars now
 *     is what lets H13 attach a queued webhook listener without touching a single emission site.
 *
 * Dispatched post-commit by call-site ordering (the emission site raises the event AFTER its `DB::transaction`
 * closure returns), matching the existing pipeline convention — never inside the transaction.
 */
abstract class DomainEvent
{
    use Dispatchable;

    /** Mirrors the `api/v1` route prefix; travels in the envelope so a consumer can version its handling. */
    public const string API_VERSION = 'v1';

    public readonly string $eventId;

    public readonly CarbonImmutable $occurredAt;

    public function __construct()
    {
        $this->eventId = Uuid::uuid7()->toString();
        $this->occurredAt = CarbonImmutable::now();
    }

    /** The catalog entry this event represents. */
    abstract public function eventType(): DomainEventType;

    /** The tenant this event belongs to (null only for a platform-level event with no single tenant). */
    abstract public function tenantId(): ?string;

    /**
     * The event-specific payload — SCALARS ONLY (ids, counts, enum values, pre-built strings), never a model.
     *
     * @return array<string, scalar|null>
     */
    abstract public function data(): array;

    /**
     * The stable delivery envelope (§7.4). H13's webhook dispatcher and the notification builder both read
     * this — one shape, so a webhook payload and a notification's context cannot diverge.
     *
     * @return array{event_id: string, event_type: string, occurred_at: string, tenant_id: ?string, api_version: string, data: array<string, scalar|null>}
     */
    public function envelope(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType()->value,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'tenant_id' => $this->tenantId(),
            'api_version' => self::API_VERSION,
            'data' => $this->data(),
        ];
    }
}
