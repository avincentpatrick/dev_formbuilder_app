<?php

declare(strict_types=1);

namespace App\Listeners\Gamification;

use App\Enums\PointRule;
use App\Events\FormCreated;
use App\Services\Gamification\PointsRecorder;

/**
 * Credits the creator of a new form (Increment K1a) — the {@see AwardPointsForSubmissionCreated} shape,
 * with no read-back needed because {@see FormCreated} carries the creator.
 *
 * The subject is the FORM, so a form scores its creation exactly once however many times it is later
 * edited, renamed or republished.
 */
final class AwardPointsForFormCreated
{
    public function __construct(private readonly PointsRecorder $points) {}

    public function handle(FormCreated $event): void
    {
        $this->points->award(
            PointRule::FormCreated,
            $event->createdByUserId,
            'form',
            $event->formId,
        );
    }
}
