<?php

declare(strict_types=1);

namespace App\Listeners\Connectors;

use App\Events\FormPublished;
use App\Listeners\Webhooks\DispatchWebhooksForFormPublished;
use App\Services\Connectors\ConnectorEventDispatcher;

/**
 * Auto-discovered SYNCHRONOUS listener fanning `form.published` out to the tenant's native-connector
 * subscriptions (H15a) — the {@see DispatchWebhooksForFormPublished} twin for the connector channel.
 *
 * A SEPARATE listener rather than a second call inside the webhook one: the two channels then fail
 * independently (a connector query error cannot suppress webhook delivery, or vice versa), and adding the
 * channel touched no shipped file. Like its twin it must not be `ShouldQueue` — it only creates ledger rows
 * and enqueues jobs (no outbound I/O), and a queued listener would run under a null tenant GUC.
 */
final class DispatchConnectorsForFormPublished
{
    public function __construct(private readonly ConnectorEventDispatcher $dispatcher) {}

    public function handle(FormPublished $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
