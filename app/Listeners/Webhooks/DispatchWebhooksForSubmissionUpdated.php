<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Enums\DomainEventType;
use App\Events\SubmissionUpdated;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Fans `submission.updated` out to subscribed webhook endpoints (Increment I9c) — the
 * {@see DispatchWebhooksForSubmissionCreated} shape, unchanged.
 *
 * ⚠️ IT WAS MISSING FROM I9c'S FIRST DRAFT, AND THE ENUM'S OWN DOCBLOCK NAMES THAT AS THE WORST OUTCOME.
 * The increment added the {@see DomainEventType} case, the CHECK migration, the label, the Slack copy and
 * the deep link — everything except the two dispatch listeners — so a tenant could tick "Submission answers
 * edited" on `/webhooks`, point it at a SIEM, and receive nothing, forever, with no error anywhere. That is
 * the exact defect the catalog's docblock says kept `review.requested` out of it: "a case with NO emission
 * site is worse than a missing one — it is subscribable and can never fire." An adversarial review found it;
 * no test did, which is why `WebhookFanOutTest` now drives this case alongside I3's.
 *
 * Auto-discovered from the `handle()` type-hint and not `ShouldQueue`: it only creates delivery rows and
 * enqueues jobs, so there is nothing worth deferring. The second reason recorded here until M3 — that a
 * queued listener would find no tenant context — is retired; {@see DispatchWebhooksForSubmissionCreated}
 * carries the correction.
 */
final class DispatchWebhooksForSubmissionUpdated
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(SubmissionUpdated $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
