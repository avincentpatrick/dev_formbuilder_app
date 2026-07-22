<?php

declare(strict_types=1);

use App\Enums\QueueName;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Jobs\ProbeMaintenanceJob;
use Tests\Support\Jobs\ProbeTenantJob;

/**
 * ADR-0007 §D9 — the per-tenant job-rate ceiling that closes Risk R6.
 *
 * The defining property is that it DEFERS excess work rather than rejecting it, which is the correct
 * semantics for a queue. The second test in this file is the one that would have caught the defect
 * the design review found: RateLimited enforces its ceiling by calling $job->release(), and
 * DatabaseQueue::release() re-pushes the row PRESERVING the already-incremented `attempts`. So a
 * `$tries` value would convert "this tenant is being throttled" into "this tenant's jobs permanently
 * failed" — exactly inverting §D9. TenantAwareJob therefore declares no $tries and bounds retries
 * with retryUntil() instead.
 *
 * Every ceiling is set via config() here: the shipped values are unvalidated planning assumptions and
 * far too high to trip, and CACHE_STORE=array (phpunit.xml) keeps each test's buckets to itself.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    ProbeTenantJob::reset();
    ProbeMaintenanceJob::reset();

    config()->set('queue.default', 'database');
    config()->set('queue-fairness.enabled', true);

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
    $this->user = User::factory()->create();

    enterTenant($this->tenant->id, $this->user->id);
});

it('defers rather than fails once the ceiling is hit', function (): void {
    config()->set('queue-fairness.ceilings.'.QueueName::Submissions->value, 1);

    ProbeTenantJob::dispatch($this->tenant->id, 'first');
    ProbeTenantJob::dispatch($this->tenant->id, 'second');

    workOneJob(); // consumes the single permitted execution
    workOneJob(); // over the ceiling → must be RELEASED, not run and not failed

    expect(ProbeTenantJob::$observations)->toHaveCount(1)
        ->and(ProbeTenantJob::last()['label'])->toBe('first');

    $deferred = DB::table('jobs')->first();

    // Still queued, deliberately delayed, and NOT in failed_jobs. The last of those is the assertion
    // that distinguishes "deferred" from "rejected".
    expect($deferred)->not->toBeNull()
        ->and($deferred->available_at)->toBeGreaterThan(now()->getTimestamp())
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('survives more deferrals than any $tries value would have permitted', function (): void {
    // THE REGRESSION TEST FOR THE tries-burn DEFECT, verified by mutation.
    //
    // Each deferral consumes an attempt (DatabaseQueue::release preserves the incremented count), so
    // under ADR-0007 §D7 EXACTLY AS WRITTEN — `$tries = 3` with no retryUntil() — this job is in
    // failed_jobs by the fourth pass. Confirmed: applying that mutant fails this test and only this
    // test.
    //
    // Note which mutant it does NOT kill: adding `$tries = 3` while KEEPING retryUntil() changes
    // nothing, because a non-null retryUntil makes maxTries dead code in the worker
    // (Worker::markJobAsFailedIfWillExceedMaxAttempts returns on the time check first). That is
    // precisely why TenantAwareJob declares no $tries at all — a $tries there would be inert
    // documentation that lies about what bounds the job.
    config()->set('queue-fairness.ceilings.'.QueueName::Submissions->value, 1);

    ProbeTenantJob::dispatch($this->tenant->id, 'hog');
    workOneJob(); // burns the ceiling for this minute

    ProbeTenantJob::dispatch($this->tenant->id, 'starved');

    for ($i = 0; $i < 5; $i++) {
        DB::table('jobs')->update(['available_at' => now()->subMinute()->getTimestamp()]);
        workOneJob();
    }

    $row = DB::table('jobs')->first();

    expect($row)->not->toBeNull()
        ->and($row->attempts)->toBeGreaterThan(3)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(ProbeTenantJob::$observations)->toHaveCount(1); // only 'hog' ever ran
});

it('keys the ceiling per tenant, not globally', function (): void {
    config()->set('queue-fairness.ceilings.'.QueueName::Submissions->value, 1);

    ProbeTenantJob::dispatch($this->tenant->id, 'acme');
    ProbeTenantJob::dispatch($this->other->id, 'globex');

    workOneJob();
    workOneJob();

    // One tenant exhausting its ceiling must not starve another — that is the whole point of R6.
    // A global bucket would run only the first.
    expect(ProbeTenantJob::$observations)->toHaveCount(2)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('does not rate-limit a cross-tenant MaintenanceJob', function (): void {
    config()->set('queue-fairness.ceilings.'.QueueName::ScheduledMaintenance->value, 0);

    ProbeMaintenanceJob::dispatch();
    workOneJob(QueueName::ScheduledMaintenance->value);

    // A MaintenanceJob has no tenant to be fair between (§D3); it is paced by the scheduler and
    // single-flighted by WithoutOverlapping. Even a zero ceiling must not hold it.
    expect(ProbeMaintenanceJob::$observations)->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('applies no ceiling when fairness is disabled', function (): void {
    config()->set('queue-fairness.enabled', false);
    config()->set('queue-fairness.ceilings.'.QueueName::Submissions->value, 0);

    ProbeTenantJob::dispatch($this->tenant->id);
    workOneJob();

    // The documented operational escape hatch: a config flag, no code deploy.
    expect(ProbeTenantJob::$observations)->toHaveCount(1);
});
