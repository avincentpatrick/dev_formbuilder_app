<?php

declare(strict_types=1);

namespace App\Listeners\Gamification;

use App\Enums\PointRule;
use App\Events\SubmissionUpdated;
use App\Services\Gamification\PointsRecorder;

/**
 * Credits the member who corrected a submission's answers (Increment K1a) — the
 * {@see AwardPointsForSubmissionCreated} shape.
 *
 * Worth the least of any rule ({@see PointRule::points()}) because it is correction rather than new
 * evidence, and keyed on the SUBMISSION so an editor scores a given row once however many passes it takes.
 * Editing is the one unbounded act in this list — nothing stops a member saving the same submission fifty
 * times — so it is the rule where the idempotency key is not merely tidy but load-bearing.
 */
final class AwardPointsForSubmissionUpdated
{
    public function __construct(private readonly PointsRecorder $points) {}

    public function handle(SubmissionUpdated $event): void
    {
        $this->points->award(
            PointRule::SubmissionEdited,
            $event->editedByUserId,
            'submission',
            $event->submissionId,
        );
    }
}
