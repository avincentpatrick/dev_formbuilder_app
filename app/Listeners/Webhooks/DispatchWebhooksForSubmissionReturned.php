<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\SubmissionReturned;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Fans `submission.returned` out to subscribed webhook endpoints (Increment I3). See
 * {@see DispatchWebhooksForSubmissionApproved} for why the fan-out ships with the enum case rather than
 * after it, and {@see DispatchWebhooksForSubmissionCreated} for the shape.
 */
final class DispatchWebhooksForSubmissionReturned
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(SubmissionReturned $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
