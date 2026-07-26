<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\FormPublished;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Auto-discovered synchronous listener for `form.published` (fired post-commit by PublishService). See
 * {@see DispatchWebhooksForSubmissionCreated} for the synchronous-not-queued rationale.
 */
final class DispatchWebhooksForFormPublished
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(FormPublished $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
