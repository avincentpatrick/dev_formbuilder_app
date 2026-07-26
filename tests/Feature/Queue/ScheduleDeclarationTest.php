<?php

declare(strict_types=1);

use App\Enums\QueueName;
use App\Jobs\Maintenance\PruneFailedJobsJob;
use App\Jobs\Maintenance\ReapExpiredDraftsJob;
use App\Jobs\Maintenance\RollUpUsageCountersJob;
use App\Jobs\Maintenance\SweepScheduledFormsJob;
use App\Jobs\Maintenance\SweepWebhookRetriesJob;
use App\Jobs\MaintenanceJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0007's scheduler tier. Pest boots the CONSOLE kernel, so routes/console.php is loaded in the
 * test suite and the declarations are really registered here.
 *
 * ASSERTS REGISTRATION, NEVER SUPPRESSION. The schedule mutex and WithoutOverlapping locks ride the
 * cache store, which phpunit.xml pins to `array` — per-process, so a test claiming "the second run was
 * suppressed" would be measuring the wrong thing.
 */
uses(RefreshDatabase::class);

it('registers the failed-jobs sweep on the expected cadence', function (): void {
    $events = app(Schedule::class)->events();

    $match = collect($events)->first(
        fn ($event): bool => str_contains($event->getSummaryForDisplay(), PruneFailedJobsJob::class),
    );

    expect($match)->not->toBeNull()
        ->and($match->expression)->toBe('10 3 * * *');
});

it('registers the usage-counter rollup on the expected cadence', function (): void {
    $events = app(Schedule::class)->events();

    $match = collect($events)->first(
        fn ($event): bool => str_contains($event->getSummaryForDisplay(), RollUpUsageCountersJob::class),
    );

    expect($match)->not->toBeNull()
        ->and($match->expression)->toBe('40 2 * * *'); // dailyAt('02:40')
});

it('registers the draft-expiry reaper on the expected cadence', function (): void {
    $events = app(Schedule::class)->events();

    $match = collect($events)->first(
        fn ($event): bool => str_contains($event->getSummaryForDisplay(), ReapExpiredDraftsJob::class),
    );

    expect($match)->not->toBeNull()
        ->and($match->expression)->toBe('40 3 * * *'); // dailyAt('03:40')
});

it('registers the scheduled-forms state-flip sweep on the expected cadence', function (): void {
    $events = app(Schedule::class)->events();

    $match = collect($events)->first(
        fn ($event): bool => str_contains($event->getSummaryForDisplay(), SweepScheduledFormsJob::class),
    );

    expect($match)->not->toBeNull()
        ->and($match->expression)->toBe('*/5 * * * *'); // everyFiveMinutes()
});

it('registers the webhook retry-ladder sweep on the expected cadence', function (): void {
    $events = app(Schedule::class)->events();

    $match = collect($events)->first(
        fn ($event): bool => str_contains($event->getSummaryForDisplay(), SweepWebhookRetriesJob::class),
    );

    expect($match)->not->toBeNull()
        ->and($match->expression)->toBe('*/5 * * * *'); // everyFiveMinutes()
});

it('keeps every scheduled job queueable rather than inline', function (): void {
    // Schedule::job() silently falls back to dispatchNow() for a non-ShouldQueue object. A job that
    // lost its interface would then run INSIDE the scheduler process — synchronously, without the
    // §D4 listener's worker edges and without the fairness limiter, while blocking the next tick.
    foreach ([PruneFailedJobsJob::class, RollUpUsageCountersJob::class, ReapExpiredDraftsJob::class, SweepScheduledFormsJob::class, SweepWebhookRetriesJob::class] as $job) {
        expect(is_subclass_of($job, ShouldQueue::class))->toBeTrue()
            ->and(is_subclass_of($job, MaintenanceJob::class))->toBeTrue();
    }
});

it('never introduces a console kernel', function (): void {
    // Illuminate\Foundation\Console\Kernel::shouldDiscoverCommands() is `get_class($this) === __CLASS__`,
    // so ANY console-kernel subclass silently stops routes/console.php being loaded — killing every
    // schedule declaration and the `inspire` command, with no error. Cheap to assert, invisible to
    // debug if it ever happens.
    expect(file_exists(app_path('Console/Kernel.php')))->toBeFalse();
});

it('prunes only failed jobs older than the retention window', function (): void {
    $retention = (int) config('queue.failed.retention_days', 14);

    DB::table('failed_jobs')->insert([
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => QueueName::Submissions->value,
            'payload' => '{}',
            'exception' => 'stale',
            'failed_at' => now()->subDays($retention + 1),
        ],
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => QueueName::Submissions->value,
            'payload' => '{}',
            'exception' => 'recent',
            'failed_at' => now()->subDay(),
        ],
    ]);

    (new PruneFailedJobsJob)->handle();

    // The recent one must survive: this is a retention sweep, not a truncate. `failed_jobs` is the
    // only record a failure ever happened, so over-pruning destroys the evidence §D12 depends on.
    expect(DB::table('failed_jobs')->pluck('exception')->all())->toBe(['recent']);
});
