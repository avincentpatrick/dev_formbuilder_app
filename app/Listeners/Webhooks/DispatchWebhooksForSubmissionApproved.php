<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Enums\DomainEventType;
use App\Events\SubmissionApproved;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Fans `submission.approved` out to subscribed webhook endpoints (Increment I3) — the
 * {@see DispatchWebhooksForSubmissionCreated} shape, unchanged.
 *
 * It ships in the same increment as the enum case because {@see DomainEventType} is the SUBSCRIPTION
 * vocabulary: the moment the case existed, the checkbox appeared on `/webhooks` and eight FormRequests
 * began accepting it. An event a tenant can subscribe to but which never delivers is precisely the defect
 * that kept `review.requested` out of the catalog — it must not be introduced by the back door.
 *
 * Auto-discovered and never `ShouldQueue`: it only creates delivery rows and enqueues jobs, and a queued
 * listener would run under a null tenant GUC.
 */
final class DispatchWebhooksForSubmissionApproved
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(SubmissionApproved $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
