<?php

declare(strict_types=1);

namespace App\Listeners\Gamification;

use App\Enums\PointRule;
use App\Events\SubmissionCreated;
use App\Listeners\Entitlements\MeterSubmissionUsage;
use App\Models\Submission;
use App\Services\Gamification\PointsRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Credits the member who collected a response (Increment K1a) — the engine's highest-volume rule and the
 * one the *enumerator collection streak* is built out of.
 *
 * DELIBERATELY SYNCHRONOUS (never {@see ShouldQueue}), the {@see MeterSubmissionUsage} shape and for its
 * reasons: a queued listener would run on a worker under a NULL tenant GUC, where the RLS-scoped read below
 * finds nothing and the insert is refused. {@see SubmissionCreated} is raised post-commit and created-only
 * (never on an idempotent replay — `SubmissionPipeline`), and session-scoped tenant context outlives the
 * commit, so the award lands on the right tenant and can never roll the submission back.
 *
 * ── ⚠️ A `guest` SUBMISSION CREDITS NOBODY, AND THAT IS THE RULE RATHER THAN A GAP ──────────────────────
 * The event carries `source` but not the collector, so the row is read back for `respondent_user_id` — set
 * for `manual`, `offline_sync` and the OCR channels, NULL for `guest`. On the guest channel there is no
 * member to credit, and the tempting alternative — crediting the form's owner — would turn the per-member
 * ladder into a popularity contest decided by whoever published the busiest public link, which is not what
 * "who collected the most data" means. Workspace totals count every submission; see {@see PointRule}.
 *
 * ── A `screened_out` SUBMISSION STILL SCORES, ON {@see MeterSubmissionUsage}'S OWN ARGUMENT ─────────────
 * That listener meters screened-out rows because they are real rows that were validated, stored and
 * indexed. The same holds one step further out: a member who encoded a response did the collection work
 * whatever the screening rules later concluded about it, and docking them for the form author's screening
 * logic would score the form's design rather than their effort.
 */
final class AwardPointsForSubmissionCreated
{
    public function __construct(private readonly PointsRecorder $points) {}

    public function handle(SubmissionCreated $event): void
    {
        $submission = Submission::query()
            ->whereKey($event->submissionId)
            ->first(['id', 'respondent_user_id']);

        if (! $submission instanceof Submission) {
            return;
        }

        $this->points->award(
            PointRule::SubmissionCollected,
            $submission->respondent_user_id === null ? null : (string) $submission->respondent_user_id,
            'submission',
            (string) $submission->getKey(),
        );
    }
}
