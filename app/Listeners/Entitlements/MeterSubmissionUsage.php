<?php

declare(strict_types=1);

namespace App\Listeners\Entitlements;

use App\Enums\UsageMetric;
use App\Events\SubmissionCreated;
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
 */
final class MeterSubmissionUsage
{
    public function __construct(private readonly UsageMeter $meter) {}

    public function handle(SubmissionCreated $event): void
    {
        $this->meter->increment(UsageMetric::SubmissionsCount);
    }
}
