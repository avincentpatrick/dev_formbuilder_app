<?php

declare(strict_types=1);

namespace App\Listeners\Entitlements;

use App\Enums\UsageMetric;
use App\Events\SubmissionCreated;
use App\Models\Submission;
use App\Services\Entitlements\UsageMeter;
use App\Services\Submissions\SubmissionPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Meters `submissions_count` (H5b / ADR-0008 §D4 never-block) when a submission is created.
 *
 * DELIBERATELY SYNCHRONOUS (not {@see ShouldQueue}): a queued listener could be
 * redelivered and double-count, and it would run on a worker under a NULL GUC where the strict-RLS increment
 * would find no tenant. {@see SubmissionCreated} is raised post-commit and created-only (never on an
 * idempotent replay — {@see SubmissionPipeline}), and tenant context is still set
 * post-commit, so the increment lands on the right tenant and can never roll the submission back.
 * {@see UsageMeter} swallows its own failures, so this can never surface into the pipeline.
 *
 * ── A `screened_out` SUBMISSION IS STILL METERED, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT (I9a) ──
 * I9a made `screened_out` stop consuming a form's author-set `max_responses` cap. It deliberately did NOT
 * change this. The two counters answer different questions: `max_responses` is a quantity the FORM AUTHOR set
 * for a data-collection round, and charging it for a respondent who was never asked anything is the bug I9a
 * fixed; `UsageMetric::SubmissionsCount` is the tenant's PLAN quota, which meters ingest — and a screened-out
 * row is a real row that was validated, stored, indexed and replicated to every subscribed connector.
 *
 * The asymmetry is worth stating because it is visible: a tenant can watch their plan quota move while a
 * capped form's "responses remaining" does not. Nothing bounds screened-out volume except I8b's per-IP rate
 * limit and proof-of-work challenge, which is where abuse belongs — `max_responses` was never a security
 * control, and treating it as one is how a cap meant for a survey ends up load-bearing for spam.
 * {@see Submission::scopeConsumesCapacity()} carries the other half of this argument.
 */
final class MeterSubmissionUsage
{
    public function __construct(private readonly UsageMeter $meter) {}

    public function handle(SubmissionCreated $event): void
    {
        $this->meter->increment(UsageMetric::SubmissionsCount);
    }
}
