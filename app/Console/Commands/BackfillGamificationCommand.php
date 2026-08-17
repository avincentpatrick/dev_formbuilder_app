<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Gamification\ReplayTenantHistoryJob;
use App\Models\Tenant;
use App\Services\Gamification\BackfillTally;
use App\Services\Gamification\GamificationBackfill;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The operator surface for the gamification backfill (ADR-0020 §D5) — Increment K1c.
 *
 * A product decision of record (2026-08-17): existing workspaces are BACKFILLED from their own history
 * rather than starting at zero, because everyone starting empty leaves the demo tenants blank and the
 * feature untestable the day it ships.
 *
 * WHY A COMMAND AND NOT A ROUTE, on the `ExtractTenantCommand` / `ActivateCustomDomainCommand` precedent.
 * This is a cross-tenant one-shot: it touches every workspace on the box, which is not a thing any tenant's
 * session should be able to start. Exposing it would need a new permission key — an authorization widening
 * — for an action nobody in a tenant ever needs to take twice. Requiring shell access is the authorization
 * model here, not friction.
 *
 * ⚠️ **AND WHY NOT A MIGRATION, WHICH IS WHERE EVERY OTHER BACKFILL IN THIS CODEBASE LIVES.** A migration
 * runs as `meridian_app` with **no tenant GUC**, where the strict RLS predicate resolves to `tenant_id =
 * NULL` and matches nothing: it would read zero rows, write zero rows, raise nothing, and report success.
 * The five classes under `app/Support/Migrations/` get away with it by using the BYPASSRLS connection, and
 * that route is deliberately closed here — the whole safety argument for `PointsRecorder`'s raw INSERT is
 * that the strict `WITH CHECK` policy proves it wrote where it thought it did.
 *
 * ⚠️ **THE SCHEDULER IS NOT AN OPTION EITHER** — ADR-0020 Context §6: the production box provisions no Task
 * Scheduler task, so anything hung off `routes/console.php`'s schedule would silently never run. This is
 * one-shot and hand-started on purpose. Do not add a `Schedule::job()` line for it.
 *
 * Safe to re-run: every write is `ON CONFLICT DO NOTHING` against an append-only ledger, so a second run
 * reports everything as `existing` and changes nothing.
 */
final class BackfillGamificationCommand extends Command
{
    protected $signature = 'gamification:backfill
                            {--tenant= : One tenant id, slug or domain (default: every ACTIVE workspace)}
                            {--dry-run : Replay inside a transaction and roll it back — real numbers, no writes}
                            {--sync : Replay inline instead of queueing, so this process reports the result}';

    protected $description = 'Replay existing history into the gamification ledger, once, per workspace';

    public function handle(GamificationBackfill $backfill): int
    {
        $targets = $this->targets();

        if ($targets === null) {
            return self::FAILURE;
        }

        if ($targets === []) {
            $this->warn('No active workspace matched. Nothing to replay.');

            return self::SUCCESS;
        }

        // ⚠️ A dry run has to execute inline: the numbers come from the real enumeration, and a queued job
        // would neither roll back nor report here. `--dry-run` therefore implies `--sync` rather than
        // conflicting with it.
        $inline = (bool) $this->option('sync') || (bool) $this->option('dry-run');

        return $inline ? $this->runInline($backfill, $targets) : $this->fanOut($targets);
    }

    /**
     * @return list<Tenant>|null null when a named tenant could not be resolved
     */
    private function targets(): ?array
    {
        $named = $this->option('tenant');

        if (! is_string($named) || $named === '') {
            // `tenants` is RLS-exempt — it is the discriminator table — so this needs no context, and
            // `scopeActive()` is routed through rather than re-spelled so this and `TenantAwareJob`'s own
            // per-job lifecycle guard cannot drift apart.
            //
            // The narrowing is real rather than a cast to satisfy the analyser: `Tenant` extends stancl's
            // model, so the builder is typed against the CONTRACT and `get()->all()` widens to
            // `array<Model>` — the same reason `ExtractTenantCommand` checks `instanceof` on its own
            // resolve.
            return array_values(array_filter(
                Tenant::query()->active()->orderBy('slug')->get()->all(),
                static fn (object $tenant): bool => $tenant instanceof Tenant,
            ));
        }

        $tenant = TenantLocator::find($named);

        if ($tenant === null) {
            $this->error('No tenant matches that id, slug or domain.');

            return null;
        }

        if (! $tenant->isActive()) {
            $this->error("{$tenant->slug} is not active. A suspended workspace is skipped by the queue too.");

            return null;
        }

        return [$tenant];
    }

    /**
     * @param  list<Tenant>  $targets
     */
    private function fanOut(array $targets): int
    {
        foreach ($targets as $tenant) {
            ReplayTenantHistoryJob::dispatch((string) $tenant->getKey());
        }

        $this->info(count($targets).' workspace(s) queued. Each job replays a chunk and re-dispatches itself.');
        $this->line('Watch the `scheduled-maintenance` queue; every chunk logs its own tally.');

        return self::SUCCESS;
    }

    /**
     * @param  list<Tenant>  $targets
     */
    private function runInline(GamificationBackfill $backfill, array $targets): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $total = new BackfillTally;

        if ($dryRun) {
            $this->warn('DRY RUN — every workspace is replayed for real and then rolled back. Nothing persists.');
        }

        foreach ($targets as $tenant) {
            $tally = $this->replayOne($backfill, (string) $tenant->getKey(), $dryRun);
            $label = $tenant->slug ?? (string) $tenant->getKey();

            if ($tally === null) {
                // Named, not omitted: a workspace absent from the table would read as one this run never
                // reached, which is the one thing an operator must not have to guess about.
                $rows[] = [$label, 'module off', '—', '—', '—', '—'];

                continue;
            }

            $total = $total->plus($tally);

            $rows[] = [
                $label,
                (string) $tally->scanned,
                (string) $tally->created,
                (string) $tally->existing,
                (string) $tally->unmapped,
                (string) $tally->uncredited,
            ];
        }

        $this->newLine();
        $this->table(['Workspace', 'Scanned', 'Awarded', 'Already held', 'No act', 'Nobody to credit'], $rows);

        // ⚠️ SURFACED RATHER THAN BURIED, the `ExtractTenantCommand` posture. `uncredited` is the number an
        // operator has to interpret: guest responses (correct and expected — ADR-0020 §D8 credits nobody
        // for them), members whose accounts were deleted, and invitations still outstanding, whose invitee
        // row this process cannot read. `unmapped` should be small; a large one means `audits` has grown a
        // writer this map has never been told about.
        if ($total->uncredited > 0) {
            $this->warn("{$total->uncredited} act(s) had nobody to credit — guest responses, deleted accounts, "
                .'or invitations not yet accepted. All three are expected; a surprising total is not.');
        }

        if (! $total->balances()) {
            $this->error('The tally does not balance — some rows were counted in no bucket at all. Report this.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? "{$total->created} award(s) WOULD be created. Nothing was written."
            : "{$total->created} award(s) created.");

        return self::SUCCESS;
    }

    /**
     * One workspace, under its own context.
     *
     * ⚠️ **A DRY RUN IS THE REAL REPLAY, ROLLED BACK — NOT A SECOND COUNTING PATH.** The obvious shape is a
     * query that reports what WOULD be written, and it was rejected: it is a second authority on the same
     * question, agreeing with the writer only by inspection, and this codebase has an ADR-numbering
     * incident to show for what that costs. A transaction that is thrown away is exact by construction, and
     * it exercises the same statements the real run will.
     *
     * The context is applied INSIDE the transaction because {@see TenantContext::applyLocal()} is
     * `SET LOCAL` — it is a no-op outside one, and it expires with the rollback, which is precisely the
     * cleanup this needs.
     *
     * Returns null when the workspace has the module switched off — distinct from a tally of zeroes, which
     * would mean it ran and found nothing.
     */
    private function replayOne(GamificationBackfill $backfill, string $tenantId, bool $dryRun): ?BackfillTally
    {
        $tally = null;

        DB::beginTransaction();

        try {
            TenantContext::applyLocal($tenantId, null);

            if ($backfill->moduleEnabled($tenantId)) {
                $tally = $backfill->replayTenant($tenantId);
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            // The GUC died with the transaction either way; this clears the PHP-side mirror so the next
            // workspace in the loop cannot inherit it. `applyLocal(null)`, never `forget()`.
            TenantContext::applyLocal(null);
        }

        return $tally;
    }
}
