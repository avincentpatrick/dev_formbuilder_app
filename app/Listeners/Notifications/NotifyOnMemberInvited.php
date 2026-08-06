<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\NotificationType;
use App\Events\MemberInvited;
use App\Notifications\TenantInvitationNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientResolver;

/**
 * Someone was invited — tell the tenant's other administrators (Increment I3).
 *
 * NOT the invitee. They already receive {@see TenantInvitationNotification} from the
 * invite call site, and an in-app row would be unreadable to them anyway: they are not a member yet, so
 * there is no tenant context in which the row could be selected. The audience here is the Owner and the
 * other Admins, for whom "a seat was offered" is a governance fact worth seeing — minus the person who did
 * the inviting.
 *
 * `member_invited` is not form-scoped, so no visibility narrowing applies; its role set is org-wide by
 * construction.
 */
final class NotifyOnMemberInvited
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function handle(MemberInvited $event): void
    {
        $this->dispatcher->dispatch(
            NotificationType::MemberInvited,
            $this->recipients->forType(NotificationType::MemberInvited, null, $event->invitedByUserId),
            [
                'email' => $event->email,
                'role' => $event->role,
                'invited_by' => $event->invitedByUserId,
            ],
        );
    }
}
