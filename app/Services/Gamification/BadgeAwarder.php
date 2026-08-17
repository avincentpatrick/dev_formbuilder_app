<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\BadgeKey;
use App\Enums\NotificationType;
use App\Enums\PointRule;
use App\Models\BadgeAward;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only writer of {@see BadgeAward} (gamification-design.md §7, ADR-0020 §D9) — Increment K1b.
 *
 * Invoked by {@see PointsRecorder::award()} and **only when that call genuinely created a row** — which is
 * the whole reason `award()` returns a bool rather than void, and what keeps a member's thousandth response
 * from re-offering every badge in the catalog.
 *
 * ── ⚠️ THE SAVEPOINT IS THE LOAD-BEARING PART, NOT THE `catch`, AND THAT DISTINCTION IS THE WHOLE FILE ──
 * K1a could argue there was nothing to catch: `ON CONFLICT DO NOTHING` never raises. **That argument does
 * not reach here, and reusing it would be the increment's worst defect.** Three things in this path can
 * raise, and in PostgreSQL a raise aborts the *whole* transaction — every later statement dies on `25P02`
 * until the block ends, so a bare `catch` swallows the error and hands the caller a transaction that is
 * already dead:
 *
 *   1. the `count(*)` read — a statement timeout, or an already-poisoned transaction;
 *   2. `42501`, an RLS `WITH CHECK` refusal, which is the loudness the explicit-`$tenantId` design relies on;
 *   3. ⚠️ `23514` — **a {@see BadgeKey} case added without widening `badge_awards_badge_check`.** That is a
 *      one-line enum edit, and without a savepoint it would take out every `TemplateService::instantiate()`
 *      for the affected rule. `BadgeCatalogTest` pins the constraint against the enum precisely so this is
 *      caught in CI, but the guard has to hold for the case where it is not.
 *
 * `DB::transaction()` issues a SAVEPOINT when it is already nested, so the failure rolls back to it and the
 * caller's transaction survives — the `SavedReportViewService` and `SubmissionReferenceBackfill` precedent,
 * the second of which records that without it its own effect test failed on `25P02`. **Measured there,
 * inherited here.**
 * (Both named in backticks rather than `{@see}` ON PURPOSE: they are PRECEDENT CITATIONS, not types this
 * class uses, and Pint's `fully_qualified_strict_types` fixer turns a `{@see}` into a real `use` — which it
 * did on the first run here, leaving this file importing two services it has nothing to do with. Same
 * reasoning `scripts/migration-lint.php` already records for itself, and the J5a lesson that a FORMATTER
 * CAN INTRODUCE A DEPENDENCY, so read the diff Pint makes and not only its exit status.)
 *
 * ── TWO BOUNDARIES, AND THE SECOND ONE IS DELIBERATELY OUTSIDE THE FIRST ───────────────────────────────
 * The announcement sits outside the savepoint. Inside it, a failed notification would roll the badge row
 * back — and the next award of that rule would re-create it and fail again, turning a one-time lost message
 * into a permanently repeating one. **The earned fact outranks telling somebody about it**, so the row
 * commits and the announcement is allowed to fail alone.
 *
 * ── ⚠️ THIS METHOD IS TOTAL: IT CANNOT THROW, AND {@see PointsRecorder} DEPENDS ON THAT ─────────────────
 * `award()` has already decided it created a row by the time it calls here, and its answer is **counted** —
 * K1c's backfill uses it to report what it actually wrote. If a badge failure escaped, the recorder's own
 * swallow-and-log would turn a genuinely-successful point award into `false`, and the backfill would
 * under-report against a ledger that is in fact correct. A scoreboard that miscounts itself is worse than
 * one that is merely missing a badge.
 *
 * ⚠️ **THE CATALOG LOOKUP IS INSIDE THE GUARD TOO, AND IT WAS OUTSIDE IT FIRST.** `BadgeKey::forRule()` is a
 * pure enum map that cannot realistically throw, which is exactly the reasoning that leaves a statement
 * unguarded and makes the docblock above a claim rather than a fact. It costs one line to make "cannot
 * throw" literally true instead of true-in-practice, and the mutation pass is what surfaced the gap: the
 * mutation *"fold a badge failure into `award()`'s answer"* survives **by construction** precisely because
 * this method is total, so that totality is the only thing defending the seam and it should not rest on an
 * argument about which statements are safe.
 *
 * ── ⚠️ RAW SQL AND AN EXPLICIT `$tenantId`, THE {@see PointsRecorder} POSTURE INHERITED WHOLE ───────────
 * `tenant_id` is passed rather than read from {@see TenantContext} here, so the write cannot depend on
 * *when* it was called — the argument `PointsRecorder::emailSubject()` makes, and the one that matters most
 * for K1c, which runs per-tenant inside a job. ⚠️ **And note what the tenant predicate on the READ is and
 * is not**: RLS is what actually confines that `count(*)`, so removing the predicate would change nothing
 * observable and no test inside this application can prove it is needed. It is kept as documentation of the
 * scope the caller asserted, and this sentence exists so nobody later "proves" it redundant and tidies it
 * away — the read it guards is the engine's first RLS-filtered read, and an under-scoped tenant GUC makes a
 * read return **zero rows silently**, the exact inverse of the INSERT's loud `42501`.
 *
 * ── THE GATE IS THE CALLER'S, DELIBERATELY, AND THAT IS A REAL COUPLING ────────────────────────────────
 * There is no `EntitlementService` check here. `PointsRecorder::award()` returns false before reaching this
 * class when the module is off, so a second check would be a second answer to one question — and the two
 * could disagree. Stated rather than left implicit **because it means this class must not grow a caller that
 * is not the recorder**: K1c's backfill goes through `award()` for exactly this reason.
 */
final class BadgeAwarder
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * Award every badge `$userId` has newly qualified for on `$rule`.
     *
     * `$awardedAt` is the **triggering award's** timestamp, not the clock: K1c replays acts that happened
     * months ago, and a badge stamped `now()` there would date every historical achievement to install day —
     * destroying the one fact this ledger exists to hold.
     *
     * `$announce` exists so K1c can replay history without sending a member several hundred notifications
     * for things they did last year. No production caller passes false yet; the decision is K1c's to make,
     * and the argument is here so that making it does not require reopening this seam — the same reason K1a
     * gave `award()` its `$awardedAt` before anything passed one. Named `$announce` rather than `$notify`
     * because at a call site `notify: false` reads as "do not use the notification system", which is not the
     * fact being stated.
     *
     * @return list<BadgeKey> the badges this call GENUINELY created; empty on any failure
     */
    public function evaluate(
        string $tenantId,
        string $userId,
        PointRule $rule,
        Carbon $awardedAt,
        bool $announce = true,
    ): array {
        try {
            $candidates = BadgeKey::forRule($rule);

            if ($candidates === []) {
                // A rule no badge counts — `submission.edited`, deliberately (see BadgeKey's docblock).
                // Returning before the transaction matters: that is the highest-frequency rule in the engine.
                return [];
            }

            // ── BOUNDARY 1: the savepoint. See the class docblock for why a bare catch is not enough.
            $earned = DB::transaction(
                fn (): array => $this->record($tenantId, $userId, $candidates, $awardedAt)
            );
        } catch (Throwable $e) {
            // WARNING rather than DEBUG because the RLS refusal described above arrives here. The SQLSTATE
            // is logged as well as the class — K1a could log only the class because only one failure was
            // reachable; three are reachable here and they want three different fixes.
            Log::warning('Badge evaluation failed.', [
                'rule' => $rule->value,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'sqlstate' => $e instanceof QueryException ? (string) $e->getCode() : null,
                'exception_class' => $e::class,
            ]);

            return [];
        }

        if ($earned === [] || ! $announce) {
            return $earned;
        }

        try {
            // ── BOUNDARY 2: outside the savepoint on purpose — see the class docblock.
            foreach ($earned as $badge) {
                // `announces()` is false for exactly one badge, and it is not a convenience: `welcome` is
                // earned in the same request that creates the membership, so announcing it would tell a
                // person who just joined that they have joined. See BadgeKey::announces() for why that
                // predicate is a branch rather than the decoration PointRule refused.
                if ($badge->announces()) {
                    $this->announce($userId, $badge);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Badge announcement failed.', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'exception_class' => $e::class,
            ]);
        }

        return $earned;
    }

    /**
     * Insert every candidate the member now qualifies for, and report the ones that did not already exist.
     *
     * @param  list<BadgeKey>  $candidates  ascending by threshold, per {@see BadgeKey::forRule()}
     * @return list<BadgeKey>
     */
    private function record(string $tenantId, string $userId, array $candidates, Carbon $awardedAt): array
    {
        $earned = $this->awardsOf($tenantId, $userId, $candidates[0]->rule());
        $created = [];

        foreach ($candidates as $badge) {
            // ⚠️ `<=`, NOT `==`, AND IT IS A REPAIR MECHANISM RATHER THAN A STYLE CHOICE. Under READ
            // COMMITTED two concurrent awards each see the same count, so with adjacent thresholds a tier
            // can be stepped over by both — nobody observes the number that would have granted it. With
            // `<=` the next award of that rule grants it (dated that later act, which is off by one act
            // rather than by months); with `==` it would be lost forever. The residual loss is the case
            // where the racing award is the last of that rule the member ever earns, which is bounded and
            // accepted. It is also what makes an out-of-order backfill replay converge.
            if ($badge->threshold() > $earned) {
                continue;
            }

            $affected = DB::affectingStatement(
                'INSERT INTO badge_awards (tenant_id, user_id, badge, awarded_at, created_at, updated_at) '
                .'VALUES (?, ?, ?, ?, now(), now()) '
                .'ON CONFLICT (tenant_id, user_id, badge) DO NOTHING',
                [$tenantId, $userId, $badge->value, $awardedAt->toIso8601String()],
            );

            // ⚠️ THE WRITE'S OWN RESULT, NEVER THE COUNT. Evaluation runs on every genuinely-new award of a
            // matching rule, so the thousandth response re-offers `first_response` for the thousandth time;
            // 999 of those affect zero rows. Announcing on "the threshold was met" instead would mean a
            // thousand notifications for one badge, and every "the badge row exists" assertion would still
            // be green. `ON CONFLICT DO NOTHING` is also what makes the concurrent loser a no-op rather than
            // a 23505 — which, per the class docblock, would abort the caller's transaction.
            if ($affected === 1) {
                $created[] = $badge;
            }
        }

        return $created;
    }

    /** How many times this member has been awarded `$rule` in this workspace. */
    private function awardsOf(string $tenantId, string $userId, PointRule $rule): int
    {
        // Served by `point_awards_tenant_user_rule_subject_unique` — `(tenant_id, user_id, rule)` is that
        // index's leading prefix and every predicate here is equality, so this is an index-only scan and
        // needs no heap access at all. ⚠️ NOT `point_awards_tenant_user_awarded_idx`, which matches only
        // `(tenant_id, user_id)` and would then have to fetch every row to test `rule`. Said explicitly
        // because the idempotency guard being the read index for this query is not obvious, and someone
        // will eventually "notice" that unique index is wide and try to narrow it.
        $row = DB::selectOne(
            'SELECT count(*) AS n FROM point_awards WHERE tenant_id = ? AND user_id = ? AND rule = ?',
            [$tenantId, $userId, $rule->value],
        );

        return (int) ($row->n ?? 0);
    }

    /**
     * Tell the earner, and nobody else.
     *
     * Addressed explicitly rather than by role, which is why {@see NotificationType::BadgeEarned}'s
     * `recipientRoles()` is empty: an achievement is the one notification in the catalog whose audience is a
     * single named person by definition, and a role-derived set would announce a member's badge to the Owner
     * and every Admin.
     *
     * One dispatch per badge rather than one for all of them: `notifications.data` is a per-row
     * discriminated union that `NotificationCopy` branches on, so a "you earned 2 badges" row would need a
     * plural arm nothing else in the catalog has. Two at once is only reachable through the concurrency
     * repair above or through K1c's replay, so in production this loop runs once.
     *
     * The payload carries the KEY alone — `NotificationCopy` derives the words from {@see BadgeKey}, because
     * a label copied into a `data` blob freezes into old rows and makes a re-label invisible.
     */
    private function announce(string $userId, BadgeKey $badge): void
    {
        $this->notifications->dispatch(NotificationType::BadgeEarned, [$userId], ['badge' => $badge->value]);
    }
}
