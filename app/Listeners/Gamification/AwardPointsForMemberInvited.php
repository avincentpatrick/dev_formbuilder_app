<?php

declare(strict_types=1);

namespace App\Listeners\Gamification;

use App\Enums\PointRule;
use App\Events\MemberInvited;
use App\Services\Gamification\PointsRecorder;

/**
 * Credits the member who offered a seat (Increment K1a) — the {@see AwardPointsForSubmissionCreated} shape.
 *
 * ⚠️ THE ONLY RULE WHOSE SUBJECT IS NOT A ROW ID. {@see MemberInvited} carries the invitee's EMAIL and no
 * user id — deliberately, since the invitee need not have a `users` row anyone here can see — so the
 * idempotency key is {@see PointsRecorder::emailSubject()}, a **tenant-salted** digest of the normalized
 * address. Re-inviting the same person after an invite lapses therefore cannot farm a second award, the
 * address itself never lands in the ledger, and the digest cannot be joined across two tenants' extracts to
 * prove they invited the same person — see that method for why the salt is the load-bearing half.
 *
 * `invitedByUserId` is nullable on the event (a system-issued invite has no actor); the recorder treats a
 * null member as "credit nobody" rather than as an error, so no guard is needed here.
 */
final class AwardPointsForMemberInvited
{
    public function __construct(private readonly PointsRecorder $points) {}

    public function handle(MemberInvited $event): void
    {
        $this->points->award(
            PointRule::MemberInvited,
            $event->invitedByUserId,
            'invite',
            PointsRecorder::emailSubject($event->tenantId, $event->email),
        );
    }
}
