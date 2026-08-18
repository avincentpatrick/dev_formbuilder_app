<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\NotificationType;
use App\Events\MemberJoined;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientResolver;

/**
 * Someone walked in the front door — tell the people who run the workspace (Increment J3a).
 *
 * The sibling of {@see NotifyOnMemberInvited}, deliberately down to the shape: same recipients, same page,
 * same governance question. The pair is the point — `member_invited` fires when a seat is OFFERED, this when
 * one is TAKEN through open registration instead, and before J3a only the first had a voice. A workspace
 * that turns self-registration on had no way to learn that anyone had used it; the only trace was an audit
 * row reading `via: self_registration` that nobody was watching.
 *
 * ── NOT the joiner, and `$exceptUserId` is how that is expressed ───────────────────────────────────────
 * `NotifyOnMemberInvited` passes the inviter for the same reason: nobody is told about their own act. Here
 * it is close to redundant — a fresh `viewer` holds neither `owner` nor `admin`, so they would not be in the
 * recipient set anyway — and it is passed regardless, because the two listeners should read identically and
 * because the joining ROLE is a parameter of `joinOpenTenant()` that a future caller could change.
 *
 * `member_joined` is not form-scoped, so no visibility narrowing applies; its role set is org-wide by
 * construction.
 *
 * ⚠️ Runs INSIDE `joinOpenTenant()`'s transaction — see {@see MemberJoined}'s docblock for why that is
 * required rather than merely tolerated. Nothing here may assume a committed row.
 */
final class NotifyOnMemberJoined
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function handle(MemberJoined $event): void
    {
        $this->dispatcher->dispatch(
            NotificationType::MemberJoined,
            $this->recipients->forType(NotificationType::MemberJoined, null, $event->userId),
            [
                'user_id' => $event->userId,
                'name' => $event->name,
                'role' => $event->role,
            ],
        );
    }
}
