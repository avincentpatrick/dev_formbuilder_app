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
 * ── WHY $maxExceptions AND NOT retryWindowHours() = 0 (Increment I10b) ──────────────────────────────
 * This fixture used to override `retryWindowHours()` to 0 so the job landed in `failed_jobs` on the
 * first attempt instead of waiting on a backoff ladder. That made a merge-blocking gate depend on
 * wall-clock, and `docs/feature-backlog.md` carried the flake: the same commit passed the Pest job
 * twice and failed once, with `MaxAttemptsExceededException` where the fixture's own message belonged.
 *
 * The mechanism, traced in laravel/framework 13.18.1. A 0-hour window stamps `retryUntil` into the
 * payload as the DISPATCH second. `Worker::process()` calls
 * `markJobAsFailedIfAlreadyExceedsMaxAttempts()` (Worker.php:548) BEFORE `fire()`, and that method
 * returns early only while `Carbon::now()->getTimestamp() <= $retryUntil` (Worker.php:643). One second
 * of slip on a loaded runner and it falls through to `failJob()` with the FRAMEWORK's exception — the
 * job never runs at all. The green path then needed `retryUntil <= now()` to hold as well
 * (Worker.php:669), so the test was resting on two `<=` comparisons being simultaneously true, i.e.
 * on exact equality across three separate wall-clock reads.
 *
 * `$maxExceptions = 1` removes time from the question entirely. The pre-flight check early-returns for
 * a full six hours (the real `queue-fairness.retry_window_hours`), `handleForTenant()` GENUINELY
 * throws — which is what §D4/§D12 need it to do — and
 * `markJobAsFailedIfWillExceedMaxExceptions()` (Worker.php:684) fails the job with the fixture's own
 * exception on the first throw, because `1 <= increment('job-exceptions:{uuid}')`. Two dependencies,
 * both verified rather than assumed: `WorkCommand::handle()` binds the cache
 * (`->setCache($this->cache)`, WorkCommand.php:148), and `phpunit.xml` sets `CACHE_STORE=array`, so
 * the increment is visible in-process. Each dispatch mints a fresh payload uuid, so the counter cannot
 * bleed between tests.
 *
 * It is also the PRODUCTION-representative path. No production job overrides `retryWindowHours()` —
 * this fixture was the only override in the tree — so real jobs fail through `$maxExceptions`, not
 * through an expired retry window.
 *
 * The six hours are pinned in `phpunit.xml` (`QUEUE_FAIRNESS_RETRY_WINDOW_HOURS`, forced), because
 * `config/queue-fairness.php` reads it from env with no floor: without the pin, setting that variable
 * to 0 anywhere — a CI image, a shared `.env`, a `docker exec -e` while draining a queue — would
 * restore the flake across the whole suite without touching a line of PHP.
 *
 * ⚠️ This override MASKS one mutation: changing `TenantAwareJob::$maxExceptions`'s default from 3 is
 * invisible here. `DatabaseWorkerPipelineTest`'s `expect($payload['maxExceptions'])->toBe(3)` on
 * `ProbeTenantJob` is the surviving guard for that default — do not delete it as redundant.
 */
#[Queue(QueueName::Submissions)]
final class ExplodingTenantJob extends TenantAwareJob
{
    /** One throw is enough — see the class docblock. Deliberately NOT a retryUntil trick. */
    public int $maxExceptions = 1;

    public function __construct(
        public readonly string $tenantId,
    ) {}

    protected function handleForTenant(): void
    {
        throw new RuntimeException('Deliberate failure from ExplodingTenantJob.');
    }
}
