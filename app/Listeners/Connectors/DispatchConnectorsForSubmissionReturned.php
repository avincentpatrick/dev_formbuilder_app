<?php

declare(strict_types=1);

namespace App\Listeners\Connectors;

use App\Events\SubmissionReturned;
use App\Listeners\Webhooks\DispatchWebhooksForSubmissionReturned;
use App\Services\Connectors\ConnectorEventDispatcher;

/**
 * The {@see DispatchWebhooksForSubmissionReturned} twin for the native-connector channel (H15a shape).
 */
final class DispatchConnectorsForSubmissionReturned
{
    public function __construct(private readonly ConnectorEventDispatcher $dispatcher) {}

    public function handle(SubmissionReturned $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
