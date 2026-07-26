<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\SubmissionCreated;
use App\Listeners\Entitlements\MeterSubmissionUsage;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Auto-discovered SYNCHRONOUS listener (the {@see MeterSubmissionUsage} shape):
 * it only creates delivery rows + enqueues jobs — fast, no outbound I/O — so it must not be `ShouldQueue`
 * (a queued listener would run under a null tenant GUC). The `submission.created` event fires post-commit,
 * so the row-creation here is durably after the submission committed. Fan-out logic lives in
 * {@see WebhookEventDispatcher} (shared by all four event listeners).
 */
final class DispatchWebhooksForSubmissionCreated
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(SubmissionCreated $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
