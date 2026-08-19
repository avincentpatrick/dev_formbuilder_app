<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\SubmissionCreated;
use App\Listeners\Entitlements\MeterSubmissionUsage;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Auto-discovered SYNCHRONOUS listener (the {@see MeterSubmissionUsage} shape):
 * it only creates delivery rows + enqueues jobs — fast, no outbound I/O — so it is not `ShouldQueue`:
 * there is nothing worth deferring. The `submission.created` event fires post-commit, so the row-creation
 * here is durably after the submission committed. Fan-out logic lives in {@see WebhookEventDispatcher},
 * shared by all EIGHT event listeners — it was four until I3 added three and I9c a fourth.
 *
 * ⚠️ THE PARENTHETICAL THAT SAT AFTER "`ShouldQueue`" UNTIL M3 IS RETIRED. It said a queued listener
 * would find no tenant context, which left a docblock as the only thing between this codebase and a
 * silent, total webhook outage. The dispatcher now establishes the event's own context, so queueing these
 * listeners is safe; actually doing it is a behaviour change owed its own increment, and it is filed as one.
 */
final class DispatchWebhooksForSubmissionCreated
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(SubmissionCreated $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
