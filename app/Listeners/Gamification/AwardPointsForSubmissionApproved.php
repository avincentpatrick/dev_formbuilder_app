<?php

declare(strict_types=1);

namespace App\Listeners\Gamification;

use App\Enums\PointRule;
use App\Events\SubmissionApproved;
use App\Services\Gamification\PointsRecorder;

/**
 * Credits the reviewer who cleared a submission (Increment K1a) — the
 * {@see AwardPointsForSubmissionCreated} shape.
 *
 * ⚠️ THE SUBJECT IS THE SUBMISSION, SO ONE REVIEWER SCORES A GIVEN SUBMISSION ONCE — EVER — ACROSS BOTH
 * REVIEW OUTCOMES. {@see AwardPointsForSubmissionReturned} writes the SAME rule against the SAME subject,
 * so a reviewer who returns a submission, receives the correction and then approves it earns
 * `submission.reviewed` once rather than twice. That is deliberate anti-farming: the alternative makes an
 * unbounded approve/return loop the single most efficient way to top the ladder, which would reward
 * churning the queue over clearing it. A SECOND reviewer on the same submission does earn their own award —
 * `user_id` is part of the idempotency key — because they did their own work.
 */
final class AwardPointsForSubmissionApproved
{
    public function __construct(private readonly PointsRecorder $points) {}

    public function handle(SubmissionApproved $event): void
    {
        $this->points->award(
            PointRule::SubmissionReviewed,
            $event->validatedByUserId,
            'submission',
            $event->submissionId,
        );
    }
}
