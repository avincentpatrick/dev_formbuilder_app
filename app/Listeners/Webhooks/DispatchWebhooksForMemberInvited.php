<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\MemberInvited;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * Fans `member.invited` out to subscribed webhook endpoints (Increment I3). See
 * {@see DispatchWebhooksForSubmissionApproved} for why the fan-out ships with the enum case.
 *
 * This event carries no `form_id`, so {@see WebhookEventDispatcher::fanOut()}
 * matches only TENANT-WIDE endpoints — a form-scoped endpoint never receives it. That is correct (an
 * invitation belongs to no form) and worth stating, because the behaviour is a consequence of a null
 * lookup rather than of anything written down.
 */
final class DispatchWebhooksForMemberInvited
{
    public function __construct(private readonly WebhookEventDispatcher $dispatcher) {}

    public function handle(MemberInvited $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
