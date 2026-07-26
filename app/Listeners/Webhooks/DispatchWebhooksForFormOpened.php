<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\FormOpened;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Auto-discovered synchronous listener for `form.opened`. This event is emitted INSIDE the H12a schedule
 * sweep's per-tenant transaction (at-least-once), so the fan-out here runs in that transaction — delivery
 * rows + enqueued jobs commit atomically with the state flip. Idempotency on `(endpoint, event_id)` absorbs
 * an at-least-once re-emit.
 */
final class DispatchWebhooksForFormOpened
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(FormOpened $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
