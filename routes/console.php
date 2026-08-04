<?php

use App\Jobs\Maintenance\PruneFailedJobsJob;
use App\Jobs\Maintenance\ReapExpiredDraftsJob;
use App\Jobs\Maintenance\RefreshConnectorTokensJob;
use App\Jobs\Maintenance\RollUpUsageCountersJob;
use App\Jobs\Maintenance\SweepScheduledFormsJob;
use App\Jobs\Maintenance\SweepWebhookRetriesJob;
use App\Jobs\Maintenance\VerifyCustomDomainsJob;
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

// Usage-counter rollup (H5b / ADR-0008 §D9). Cross-tenant, so it is a MaintenanceJob that fans out one
// per-tenant child (ReconcileTenantUsageJob) — never a single-tenant job. Off-peak, a little before the
// failed-job prune so the two nightly sweeps do not contend. Overlap-safety comes from
// MaintenanceJob::middleware()'s WithoutOverlapping lock, not a scheduler modifier.
Schedule::job(RollUpUsageCountersJob::class)->dailyAt('02:40');

// Draft-expiry reaper (H9b). Cross-tenant MaintenanceJob → one ReapTenantDraftsJob per active tenant, each
// hard-deleting the drafts whose 30-day draft_expires_at has passed. Off-peak and staggered clear of the
// 02:40 rollup and 03:10 prune so the nightly sweeps do not contend.
Schedule::job(ReapExpiredDraftsJob::class)->dailyAt('03:40');

// Scheduled-forms state flip (H12a). Cross-tenant MaintenanceJob → one SweepTenantScheduledFormsJob per active
// tenant, each advancing that tenant's published forms across their opens_at/closes_at boundaries and emitting
// form.opened/form.closed. everyFiveMinutes() (not dailyAt) so a time boundary announces within ~5 minutes;
// enforcement is live at submit time, so this cadence never affects whether a form actually accepts responses.
Schedule::job(SweepScheduledFormsJob::class)->everyFiveMinutes();

// Webhook retry-ladder sweep (H13a). Cross-tenant MaintenanceJob → one SweepTenantWebhookRetriesJob per active
// tenant, each re-dispatching the tenant's `failed` deliveries whose next_retry_at has arrived. everyFiveMinutes()
// keeps a due retry's added latency small without sweeping more often than the ladder's finest (1-minute) step
// meaningfully rewards; the delivery jobs themselves run on the `webhooks` queue.
Schedule::job(SweepWebhookRetriesJob::class)->everyFiveMinutes();

// Native-connector OAuth token refresh (H15a / ADR-0009 §D6). Cross-tenant MaintenanceJob → one
// RefreshTenantConnectorTokensJob per active tenant, each renewing the grants whose token_expires_at falls
// inside connectors.refresh_lead_seconds. hourly() against a 2-hour lead, so a grant is renewed with a full
// sweep cycle to spare and one missed sweep cannot expire a token. Refresh is deliberately never lazy: doing
// it inside a delivery attempt would put a second outbound call in the delivery transaction and let a
// provider outage stampede every queued delivery at once.
Schedule::job(RefreshConnectorTokensJob::class)->hourly();

// Custom-domain DNS verification (H22a / ADR-0012). Cross-tenant, and the FIRST MaintenanceJob here that
// does its work INLINE instead of fanning out — its whole subject matter is `domains`, one of the
// RLS-exempt tables §D3's rule 2 permits, so a per-tenant child would only re-read a table no tenant GUC
// affects (see the base class's rule 3, amended in H22a to say so).
//
// everyFifteenMinutes(), not everyFiveMinutes(): a tenant that has just published its TXT record can
// trigger an immediate check from POST /api/v1/domains/{domain}/verify rather than waiting, so the sweep
// only has to cover the unattended cases — the propagation tail, expired claims, and the periodic
// re-read that notices a domain whose DNS has lapsed. Fifteen also keeps its query volume clear of the
// two five-minute sweeps above.
Schedule::job(VerifyCustomDomainsJob::class)->everyFifteenMinutes();
