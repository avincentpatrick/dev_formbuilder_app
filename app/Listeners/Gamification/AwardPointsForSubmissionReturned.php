<?php

declare(strict_types=1);

namespace App\Listeners\Gamification;

use App\Enums\PointRule;
use App\Events\SubmissionReturned;
use App\Services\Gamification\PointsRecorder;

/**
 * Credits the reviewer who returned a submission for correction (Increment K1a).
 *
 * Byte-for-byte the {@see AwardPointsForSubmissionApproved} rule — same {@see PointRule}, same subject —
 * and its docblock carries the argument for why that collapses to one award per (reviewer, submission)
 * rather than two. Returning work is reviewing it; a queue where only approvals scored would pay people to
 * wave things through.
 *
 * A separate class rather than a second `handle*` method on that one, because one-listener-per-event is the
 * shape all twenty-five existing listeners take and auto-discovery reads the type-hint either way.
 */
final class AwardPointsForSubmissionReturned
{
    public function __construct(private readonly PointsRecorder $points) {}

    public function handle(SubmissionReturned $event): void
    {
        $this->points->award(
            PointRule::SubmissionReviewed,
            $event->validatedByUserId,
            'submission',
            $event->submissionId,
        );
    }
}
