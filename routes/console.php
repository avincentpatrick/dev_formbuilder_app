<?php

use App\Jobs\Maintenance\PruneFailedJobsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work (ADR-0007)
|--------------------------------------------------------------------------
|
| Declared here with Schedule::job() — the Laravel 11+ idiom. There is deliberately NO withSchedule()
| closure in bootstrap/app.php and NO app/Console/Kernel.php.
|
| >> NEVER CREATE app/Console/Kernel.php. <<
| Illuminate\Foundation\Console\Kernel::shouldDiscoverCommands() is `get_class($this) === __CLASS__`,
| so introducing ANY console-kernel subclass silently stops this file being loaded at all — killing
| the `inspire` command and every schedule below, with no error anywhere.
|
| Every entry must DISPATCH a job, never run work inline (technical-architecture.md:184). Note that
| Schedule::job() silently falls back to dispatchNow() for a non-ShouldQueue object, so a job that
| loses its interface would start running inside the scheduler process — ScheduleDeclarationTest pins
| against that.
|
| No $queue argument is passed: Schedule::job() uses `$queue ?? $job->queue`, and every job already
| names its queue with #[Queue(QueueName::…)]. Passing one here would be a second source of truth that
| could drift from the class.
|
| Production note: nothing runs these on the Windows box yet — deploy.ps1 provisions no Task Scheduler
| task (docs/deployment-infrastructure.md §8 item 7). Locally the `scheduler` compose service does.
|
*/

// Failed jobs carry tenant uuids in an RLS-free table, so this is data minimisation as well as
// housekeeping. Off-peak: it is a bulk delete against the same Postgres instance serving the app.
Schedule::job(PruneFailedJobsJob::class)->dailyAt('03:10');
