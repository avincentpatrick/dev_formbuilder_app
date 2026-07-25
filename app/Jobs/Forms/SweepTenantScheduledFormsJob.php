<?php

declare(strict_types=1);

namespace App\Jobs\Forms;

use App\Enums\QueueName;
use App\Jobs\Entitlements\ReconcileTenantUsageJob;
use App\Jobs\Maintenance\SweepScheduledFormsJob;
use App\Jobs\TenantAwareJob;
use App\Services\Forms\FormScheduleSweeper;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;

/**
 * The per-tenant child of {@see SweepScheduledFormsJob} (Increment H12a) — one dispatched per active tenant, so
 * flipping scheduled forms is the §D3 cross-tenant fan-out shape (never a single-tenant `applyLocal` job). Runs
 * inside the base's transaction under the tenant's adopted context, so {@see FormScheduleSweeper}'s updates +
 * the `form.opened`/`form.closed` it emits are RLS-scoped to that tenant.
 *
 * Emitting inside the transaction is deliberate: the base {@see TenantAwareJob} owns and does not expose a
 * post-commit hook, and — verified — a `ShouldDispatchAfterCommit`/`DB::afterCommit` event is never observed
 * under the test suite's uncommitted outer transaction. The trade is at-least-once delivery (a rare post-emit
 * COMMIT failure re-emits next tick with a fresh `event_id`); H13 dedupes on delivery. Mirrors
 * {@see ReconcileTenantUsageJob}, which fires its side effect the same way.
 */
#[Queue(QueueName::ScheduledMaintenance)]
final class SweepTenantScheduledFormsJob extends TenantAwareJob
{
    public function __construct(public readonly string $tenantId) {}

    protected function handleForTenant(): void
    {
        app(FormScheduleSweeper::class)->sweep(Carbon::now());
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['queue' => QueueName::ScheduledMaintenance->value];
    }
}
