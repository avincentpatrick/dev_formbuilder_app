<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Enums\QueueName;
use App\Jobs\TenantAwareJob;
use Illuminate\Queue\Attributes\Queue;
use RuntimeException;

/**
 * A TenantAwareJob that always throws — the fixture for the failure-path halves of ADR-0007 §D4 and
 * §D12.
 *
 * It is what distinguishes JobAttempted from JobProcessed: Worker::process() raises JobProcessed only
 * on the success path, so a listener bound to that edge leaks this job's tenant static. Running this
 * and then asserting the statics are clean is the test that kills the implementation ADR-0007 §D4
 * literally prescribes.
 *
 * retryWindowHours() is 0 so the job exhausts retryUntil() immediately and lands in failed_jobs on
 * the first attempt, rather than being released and leaving the test waiting on a backoff ladder.
 */
#[Queue(QueueName::Submissions)]
final class ExplodingTenantJob extends TenantAwareJob
{
    public function __construct(
        public readonly string $tenantId,
    ) {}

    protected function retryWindowHours(): int
    {
        return 0;
    }

    protected function handleForTenant(): void
    {
        throw new RuntimeException('Deliberate failure from ExplodingTenantJob.');
    }
}
