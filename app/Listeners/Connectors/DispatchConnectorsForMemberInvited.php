<?php

declare(strict_types=1);

namespace App\Listeners\Connectors;

use App\Events\MemberInvited;
use App\Listeners\Webhooks\DispatchWebhooksForMemberInvited;
use App\Services\Connectors\ConnectorEventDispatcher;
use App\Support\Connectors\ConnectorEventContextResolver;

/**
 * The {@see DispatchWebhooksForMemberInvited} twin for the native-connector channel (H15a shape).
 *
 * A Slack message for this event carries no deep link: `member.invited` has no form and no submission, and
 * the members page is not somewhere the invitee appears until they accept. See
 * {@see ConnectorEventContextResolver}.
 */
final class DispatchConnectorsForMemberInvited
{
    public function __construct(private readonly ConnectorEventDispatcher $dispatcher) {}

    public function handle(MemberInvited $event): void
    {
        $this->dispatcher->fanOut($event);
    }
}
