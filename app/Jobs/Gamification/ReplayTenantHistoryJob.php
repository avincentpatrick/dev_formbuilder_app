<?php

declare(strict_types=1);

namespace App\Jobs\Gamification;

use App\Enums\QueueName;
use App\Jobs\TenantAwareJob;
use App\Services\Gamification\BackfillTally;
use App\Services\Gamification\GamificationBackfill;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;

/**
 * One workspace's share of the gamification backfill (ADR-0020 §D5) — Increment K1c.
 *
 * The per-tenant child `BackfillGamificationCommand` fans out, on the `ReconcileTenantUsageJob` shape.
 * ADR-0020 §D5 requires exactly this: *"an operator command fanning out one `TenantAwareJob` per tenant"* —
 * because the replay reads and writes RLS-scoped tables and a cross-tenant transaction is not merely
 * discouraged in this architecture, it is inexpressible (`MaintenanceJob`'s docblock: the GUC is a scalar
 * and tenant matching is flat equality, with no set and no any-tenant mode).
 *
 * ── ⚠️ IT CHAINS ITSELF RATHER THAN RAISING ITS OWN TIMEOUT, AND THE DIFFERENCE IS WHETHER A BIG TENANT
 *    MAKES ANY PROGRESS AT ALL ──────────────────────────────────────────────────────────────────────────
 * `TenantAwareJob::handle()` wraps `handleForTenant()` in ONE transaction with `$timeout = 60`, and that
 * property is capped by an invariant — it must stay strictly below the queue's `retry_after` of 120, or the
 * queue can hand the same job to a second worker while the first still holds it. A single-shot replay of a
 * long-lived workspace that ran past 60s would be killed and roll back **everything it had done**, so the
 * tenant would never advance no matter how often it retried. Taking one chunk and re-dispatching means each
 * chunk commits, and a retry resumes rather than restarting.
 *
 * The re-dispatch sits inside the base class's transaction, which is correct rather than merely tolerated:
 * the `database` queue connection sets `after_commit => true` (a correctness invariant, deliberately not
 * env-overridable), so the follow-on job is only queued once this chunk's awards have actually committed.
 * ⚠️ Under `QUEUE_CONNECTION=sync` — which is every test — there is no such deferral and the chain runs
 * inline and recursively, one nested savepoint per chunk. That is why the chunk size is a parameter.
 *
 * ⚠️ **AND THE DEPENDENCE ON THAT SETTING IS NAMED RATHER THAN ASSUMED.** On a connection whose
 * `after_commit` is false — `redis`, `sqs`, `beanstalkd` all are — the follow-on job is pushed immediately,
 * so a chunk that then rolled back would leave the chain to resume PAST work that never committed. The
 * `database` connection's own config comment pins `after_commit => true` as a correctness invariant and
 * deliberately makes it non-env-overridable, and ADR-0007 names that connection as the substrate. The
 * residual risk is bounded rather than merely unlikely: **every write here is `ON CONFLICT DO NOTHING`
 * against an append-only ledger, so re-running the command repairs a skipped chunk**, and each chunk logs
 * its own `next_cursor` so the chain is legible after the fact.
 *
 * ⚠️ **THE MEMBERSHIP RULES RUN ON THE FIRST CHUNK ONLY**, keyed on the cursor being absent. They come from
 * `tenant_users` rather than from `audits` (ADR-0020 §D10 — `invite()` writes no audit row at all and
 * `accept()` writes neither a row nor an event), so they are bounded by the member count and need no
 * paging of their own. A retried first chunk replays them, which is free: every write in this path is
 * `ON CONFLICT DO NOTHING`.
 */
#[Queue(QueueName::ScheduledMaintenance)]
final class ReplayTenantHistoryJob extends TenantAwareJob
{
    public function __construct(
        public readonly string $tenantId,
        /** The last `audits.id` already replayed; null starts at the beginning AND does the memberships. */
        public readonly ?string $afterAuditId = null,
        /**
         * Rows per chunk.
         *
         * Carried on the payload for one reason, and it is the `SubmissionReferenceBackfill` reason: the
         * re-dispatch above is otherwise untestable. Reaching it through the default would mean a fixture
         * of five hundred audit rows, so the chaining — the part of this class most likely to be wrong, and
         * the part whose failure modes are "stops after one page" and "loops forever" — would ship
         * unexercised. It travels as an `int`, which `scripts/job-payload-lint.php` R3 permits.
         */
        public readonly int $limit = GamificationBackfill::CHUNK,
    ) {}

    protected function handleForTenant(): void
    {
        $backfill = app(GamificationBackfill::class);

        if (! $backfill->moduleEnabled($this->tenantId)) {
            // ⚠️ SAID OUT LOUD RATHER THAN LEFT AS A ZERO. `award()` returns false for "module off" using
            // the same value it returns for "already awarded", so without this the run would report a long
            // list of rows it had supposedly already scored and had in fact never scored at all.
            Log::info('Gamification backfill skipped: the module is switched off for this workspace.', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        $tally = new BackfillTally;

        if ($this->afterAuditId === null) {
            $tally = $tally->plus($backfill->replayMemberships($this->tenantId));
        }

        $chunk = $backfill->replayAudits($this->tenantId, $this->afterAuditId, $this->limit);
        $tally = $tally->plus($chunk->tally);

        Log::info('Gamification backfill chunk replayed.', [
            'tenant_id' => $this->tenantId,
            'resumed_after' => $this->afterAuditId,
            'next_cursor' => $chunk->nextCursor,
            // Every row lands in exactly one bucket, so a false here means the enumerator lost track of
            // something — logged rather than thrown, because a miscounted report must not destroy a
            // correct ledger.
            'balances' => $tally->balances(),
            ...$tally->toArray(),
        ]);

        if ($chunk->nextCursor !== null) {
            self::dispatch($this->tenantId, $chunk->nextCursor, $this->limit);
        }
    }

    /** @return array<string, scalar|null> */
    protected function failureContext(): array
    {
        return [
            'queue' => QueueName::ScheduledMaintenance->value,
            // The cursor is the one fact that makes a failure actionable: it says how far the walk got, so
            // an operator can resume from it rather than restarting a tenant that is most of the way done.
            'after_audit_id' => $this->afterAuditId,
        ];
    }
}
