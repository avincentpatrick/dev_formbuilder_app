<?php

declare(strict_types=1);

namespace App\Listeners\Gamification;

use App\Enums\PointRule;
use App\Events\FormPublished;
use App\Services\Gamification\PointsRecorder;

/**
 * Credits the publisher (Increment K1a) — the {@see AwardPointsForSubmissionCreated} shape.
 *
 * ⚠️ The subject is the FORM VERSION, not the form, and that is the one deliberate asymmetry against
 * {@see AwardPointsForFormCreated}. Publishing is the act that turns a draft into something a respondent
 * can answer, and a form that is revised and republished has done it again — `form_versions` are immutable
 * once published (ADR-0013), so the version id is exactly "this publication" and can never be reused to
 * farm a second award for the same one.
 */
final class AwardPointsForFormPublished
{
    public function __construct(private readonly PointsRecorder $points) {}

    public function handle(FormPublished $event): void
    {
        $this->points->award(
            PointRule::FormPublished,
            $event->publishedByUserId,
            'form_version',
            $event->formVersionId,
        );
    }
}
