<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\BadgeKey;
use App\Enums\PointRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One member's badge shelf — what they hold, and how far they are from the rest
 * (gamification-design.md §7, §10; ADR-0020 §D9) — Increment K1e.
 *
 * ── ⚠️ NOTHING IN THE ENGINE COULD ANSWER THIS BEFORE, WHICH IS WHY THE CLASS EXISTS ────────────────────
 * K1b's {@see BadgeAwarder} only ever WRITES, and the only reader K1d added counts:
 * `LeaderboardService::BADGES_SQL` returns `COUNT(*)` per member. So *"which badges do I hold, when did I
 * earn them, and how close am I to the ones I do not"* — the entire achievements surface — had no reader at
 * all. Everything else K1e renders was built by K1a–K1d and is consumed, not re-derived; this is the one
 * piece that is new.
 *
 * ── ⚠️ TWO GROUPED READS, NEVER ONE JOIN — THE §D11(d) FAN-OUT, IN ITS OTHER FORM ──────────────────────
 * {@see LeaderboardService} records why joining `point_awards` to `badge_awards` inflates a `SUM`. The same
 * trap is here in a shape that looks more innocent: joining the two so one statement can return "badge,
 * date, and how many awards of its rule" multiplies each badge row by that rule's award rows, so the
 * `COUNT` a member sees against {@see BadgeKey::Collector} would be their response count times the number
 * of collection badges they hold — **three times over**, for a member holding all three. Two reads keyed by
 * different columns cannot fan out at all, so the failure is designed out rather than tested for.
 *
 * ⚠️ **AND THE COUNT IS PER *RULE*, NOT PER BADGE, WHICH IS WHY THERE ARE SEVEN GROUPS AND TEN BADGES.**
 * Three badges count {@see PointRule::SubmissionCollected} and read the same number at three thresholds.
 * Grouping by badge would mean deciding what a badge with no awards yet contributes, and the answer is not
 * a row — it is the `?? 0` {@see BadgeShelf::assemble()} already applies against the catalog.
 *
 * ⚠️ **THERE IS NO OFF-TENANT GUARD IN THIS CLASS, AND THAT IS THE {@see StreakCalculator} POSTURE RATHER
 * THAN AN OMISSION — BUT IT PUTS A REAL OBLIGATION ON THE CALLER.** The tenant id is an argument, so an
 * off-tenant call is unrepresentable here: there is no ambient read that could quietly resolve to nothing.
 * The reachable failure moves one level up, to whoever produces that id. Every read below is RLS-filtered,
 * and an RLS-filtered read with no tenant GUC returns **no rows rather than raising** — so a caller that
 * passed an id while the GUC was unset would receive an empty shelf. That matters more here than almost
 * anywhere else in the engine, because "nothing earned" is a **legitimate and expected** answer for a new
 * member: unlike a leaderboard with no members, an empty shelf is not implausible enough for anyone to
 * notice. `AchievementsController` therefore makes the null-tenant case explicit before it calls, exactly as
 * `GamificationController::streakFor()` does for the streak. (Both named in backticks rather than with a doc
 * reference, for the reason `for()` records below: a fully-qualified one becomes a real `use`.)
 */
final class BadgeShelfService
{
    /**
     * Every badge this member holds here, and when.
     *
     * Served by `badge_awards_tenant_user_badge_unique`, whose leading `(tenant_id, user_id)` matches both
     * predicates on equality — so this is one contiguous stretch of that index and needs no second one.
     *
     * ⚠️ NO `ORDER BY`: {@see BadgeShelf} sorts on arrival and its comparator falls through to catalog
     * position, so a database sort would be discarded in every case while reading as though the ordering
     * depended on it. The {@see LeaderboardService::roster()} note, and the dead-`ORDER BY` finding K1d's
     * adversarial pass made against its own new code.
     */
    public const string EARNED_SQL = <<<'SQL'
        SELECT badge, awarded_at
        FROM badge_awards
        WHERE tenant_id = ? AND user_id = ?
        SQL;

    /**
     * How many times this member has been awarded each rule.
     *
     * Served by `point_awards_tenant_user_rule_subject_unique` — `(tenant_id, user_id, rule)` is that
     * index's leading prefix, both predicates are equality and the grouping column is the next one along,
     * so Postgres reads it in group order without a sort or a heap fetch. ⚠️ **NOT**
     * `point_awards_tenant_user_awarded_idx`, which stops at `(tenant_id, user_id)` and would have to visit
     * every row to bucket it. This is exactly {@see BadgeAwarder}'s own read widened from one rule to all
     * of them, and that method's note records the same index choice for the same reason.
     */
    public const string PROGRESS_SQL = <<<'SQL'
        SELECT rule, COUNT(*) AS total
        FROM point_awards
        WHERE tenant_id = ? AND user_id = ?
        GROUP BY rule
        SQL;

    /**
     * The shelf for `$userId` in `$tenantId`.
     *
     * The tenant id is an ARGUMENT rather than a read of `TenantContext`, matching {@see StreakCalculator::for()}
     * and deliberately unlike {@see LeaderboardService}: both statements here are raw SQL scoped to whatever
     * id they are handed, so there is no Eloquent half that could resolve the ambient tenant instead and
     * quietly disagree. See the class docblock for what that hands to the caller.
     *
     * (`TenantContext` is named in backticks rather than with a doc reference on purpose: Pint's
     * `fully_qualified_strict_types` fixer turns one into a real `use`, and this class does not touch that
     * type. The K1d finding, and the J5a lesson that a FORMATTER CAN INTRODUCE A DEPENDENCY.)
     */
    public function for(string $tenantId, string $userId): BadgeShelf
    {
        return BadgeShelf::assemble(
            $this->earnedOn($tenantId, $userId),
            $this->progressByRule($tenantId, $userId),
        );
    }

    /**
     * `badge value => when it was earned`.
     *
     * ⚠️ **A ROW WHOSE `badge` IS NOT A CATALOG CASE IS DROPPED RATHER THAN CRASHING THE PAGE.**
     * `BadgeKey::tryFrom()`, not `from()`. The column is constrained by `badge_awards_badge_check`, which is
     * generated from the enum, so today this cannot happen — but the one edit that would make it happen is
     * REMOVING a case, which is a one-line change that leaves every historical row behind it. Losing a
     * retired badge from the shelf is the right failure; a 500 on the achievements page is not.
     *
     * @return array<string, CarbonImmutable>
     */
    private function earnedOn(string $tenantId, string $userId): array
    {
        $map = [];

        foreach (DB::select(self::EARNED_SQL, [$tenantId, $userId]) as $row) {
            /** @var object{badge: string, awarded_at: string} $row */
            if (BadgeKey::tryFrom((string) $row->badge) === null) {
                continue;
            }

            $map[(string) $row->badge] = CarbonImmutable::parse((string) $row->awarded_at);
        }

        return $map;
    }

    /**
     * `rule value => how many awards`.
     *
     * `(int)` on the total because PostgreSQL returns `COUNT` as a string through PDO, and an unconverted
     * count would compare and sort as text — where '9' outranks '10'. {@see LeaderboardService::totals()}
     * carries the same conversion for the same reason, and this is the second place it bites: an unconverted
     * total would put a member on 9 responses ahead of one on 10 in {@see BadgeShelf}'s nearest-first list.
     *
     * ⚠️ Rules are NOT filtered to those a badge counts. {@see PointRule::SubmissionEdited} deliberately
     * earns no badge, so its group is read and then never looked up — which costs one row and keeps this
     * method a plain reading of the ledger rather than a second place the catalog is encoded.
     *
     * @return array<string, int>
     */
    private function progressByRule(string $tenantId, string $userId): array
    {
        $map = [];

        foreach (DB::select(self::PROGRESS_SQL, [$tenantId, $userId]) as $row) {
            /** @var object{rule: string, total: int|string} $row */
            $map[(string) $row->rule] = (int) $row->total;
        }

        return $map;
    }
}
