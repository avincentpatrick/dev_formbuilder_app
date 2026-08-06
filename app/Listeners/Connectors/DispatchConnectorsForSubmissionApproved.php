<?php

declare(strict_types=1);

namespace App\Listeners\Connectors;

use App\Events\SubmissionApproved;
use App\Listeners\Webhooks\DispatchWebhooksForSubmissionApproved;
use App\Services\Connectors\ConnectorEventDispatcher;

/**
 * The {@see DispatchWebhooksForSubmissionApproved} twin for the native-connector channel (H15a shape).
 *
 * A SEPARATE listener rather than a second call inside the webhook one, so the two channels fail
 * independently. Never `ShouldQueue` — see the twin.
 */
final class DispatchConnectorsForSubmissionApproved
{
    public function __construct(private readonly ConnectorEventDispatcher $dispatcher) {}

    public function handle(SubmissionApproved $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
