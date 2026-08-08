<?php

declare(strict_types=1);

namespace App\Listeners\Connectors;

use App\Events\SubmissionUpdated;
use App\Listeners\Webhooks\DispatchWebhooksForSubmissionUpdated;
use App\Services\Connectors\ConnectorEventDispatcher;
use App\Support\Connectors\ConnectorEventContextResolver;

/**
 * The {@see DispatchWebhooksForSubmissionUpdated} twin for the native-connector channel (H15a shape) —
 * without it, `SlackMessageFormatter`'s "*Submission answers edited*" arm and
 * {@see ConnectorEventContextResolver}'s deep-link arm, both added by I9c, are
 * unreachable code.
 *
 * A SEPARATE listener rather than a second call inside the webhook one, so the two channels fail
 * independently. Never `ShouldQueue` — see the twin.
 */
final class DispatchConnectorsForSubmissionUpdated
{
    public function __construct(private readonly ConnectorEventDispatcher $dispatcher) {}

    public function handle(SubmissionUpdated $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
