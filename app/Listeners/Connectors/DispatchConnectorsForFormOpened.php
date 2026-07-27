<?php

declare(strict_types=1);

namespace App\Listeners\Connectors;

use App\Events\FormOpened;
use App\Listeners\Webhooks\DispatchWebhooksForFormOpened;
use App\Services\Connectors\ConnectorEventDispatcher;

/**
 * Auto-discovered SYNCHRONOUS listener fanning `form.opened` out to the tenant's native-connector
 * subscriptions (H15a) — the {@see DispatchWebhooksForFormOpened} twin for the connector channel.
 *
 * A SEPARATE listener rather than a second call inside the webhook one: the two channels then fail
 * independently (a connector query error cannot suppress webhook delivery, or vice versa), and adding the
 * channel touched no shipped file. Like its twin it must not be `ShouldQueue` — it only creates ledger rows
 * and enqueues jobs (no outbound I/O), and a queued listener would run under a null tenant GUC. This event is
 * emitted from inside the H12a schedule sweep's tenant transaction, where that context is established.
 */
final class DispatchConnectorsForFormOpened
{
    public function __construct(private readonly ConnectorEventDispatcher $dispatcher) {}

    public function handle(FormOpened $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
