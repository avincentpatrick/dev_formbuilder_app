<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Jobs\MaintenanceJob;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps `failed_jobs` older than the configured retention (ADR-0007 §D12), and the substrate's first
 * concrete {@see MaintenanceJob} — without it §D3 would be a shape with no instance.
 *
 * It is genuine housekeeping AND a data-minimisation control: `failed_jobs` is an RLS-FREE table whose
 * payload column carries tenant uuids, so anything left there is readable outside any tenant boundary.
 * That is also why §D12 records that any tenant-facing view of this table is a CROSS-TENANT read,
 * sanctioned only via pgsql_superadmin, never a tenant-scoped query.
 *
 * Cross-tenant by nature — it holds no tenant context and touches only the queue's own RLS-exempt
 * tables, which is exactly what a MaintenanceJob is permitted to do.
 *
 * The constructor takes no arguments because routes/console.php schedules it by CLASS-STRING, which
 * Schedule::job() resolves through the container.
 */
final class PruneFailedJobsJob extends MaintenanceJob
{
    protected function sweep(): void
    {
        // Typed as the BASE interface on purpose: `prune()` lives on PrunableFailedJobProvider and
        // `count()` on CountableFailedJobProvider, neither of which the base guarantees. Without this
        // annotation Larastan resolves the container binding to its concrete configured type (which
        // implements all three) and flags the guards below as always-true.
        /** @var FailedJobProviderInterface $failer */
        $failer = app('queue.failer');

        // Prefer the framework's own pruner: it already chunk-deletes 1,000 rows at a time and returns
        // a count, whereas Artisan::call('queue:prune-failed') reports only to console output. Guarded
        // because the configured failer driver need not be prunable (e.g. the 'null'/'file' drivers).
        if (! $failer instanceof PrunableFailedJobProvider) {
            Log::warning('Failed-job pruning skipped: the configured queue failer is not prunable.', [
                'job' => self::class,
                'failer' => $failer::class,
            ]);

            return;
        }

        $days = (int) config('queue.failed.retention_days', 14);
        $pruned = $failer->prune(now()->subDays($days));

        Log::info('Pruned failed jobs.', [
            'job' => self::class,
            'retention_days' => $days,
            'pruned' => $pruned,
            'remaining' => $failer instanceof CountableFailedJobProvider ? $failer->count() : null,
        ]);
    }
}
