<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One member's activity streak, derived from the award ledger (gamification-design.md §8, ADR-0020 §D5)
 * — Increment K1c.
 *
 * §D5 chose DERIVED over stored for two reasons that both still hold: there is nothing to reconcile, and a
 * maintained streak would need a nightly job, which ADR-0020 Context §6 records **would never run** — the
 * production box provisions no scheduler task. So this class is the whole feature.
 *
 * ── ⚠️ THE DAY BOUNDARY IS STATED HERE, NOT INHERITED, AND THAT IS THE DECISION K1c OWED ────────────────
 * {@see self::DAY_BOUNDARY} is a literal and deliberately **not** `config('app.timezone')`. They happen to
 * agree today, which is exactly what makes reading the config the tempting mistake: it would make the
 * meaning of "a day" in every streak in the product depend on a setting nobody would think of as a
 * gamification setting, and moving it would silently re-cut history for every member at once, with no
 * migration, no announcement and no way to notice. `forms.timezone` is not a candidate either — its own
 * migration says it is authoring/display metadata that is never consulted server-side.
 *
 * The zone is a PARAMETER with that constant as its default, so the day tenants gain a timezone of their
 * own the caller supplies it and this class does not change. Nothing in the schema carries one today: the
 * only `timezone` column anywhere is the per-form one just mentioned.
 *
 * ── WHY `$tenantId` IS EXPLICIT ────────────────────────────────────────────────────────────────────────
 * The {@see BadgeAwarder} posture, for the reason that class states about its own read: RLS is what
 * actually confines this query, and an RLS-filtered read with no tenant GUC returns **zero rows silently**
 * — which here would render as "your streak is 0" rather than as an error. Requiring the id makes
 * off-tenant unrepresentable at the call site instead of merely unlikely.
 */
final class StreakCalculator
{
    /** The IANA zone whose midnight separates one day from the next. See the class docblock. */
    public const string DAY_BOUNDARY = 'UTC';

    /**
     * The distinct days this member earned anything, newest first.
     *
     * `DISTINCT … ::date` rather than a `GROUP BY`, and the ordering is on the projected day rather than on
     * `awarded_at`, because two awards on one day must collapse to one entry before anything counts a run.
     *
     * Served by `point_awards_tenant_user_awarded_idx` — `(tenant_id, user_id)` is its leading prefix and
     * both predicates are equality, so Postgres reads one contiguous stretch of the index and the date
     * expression is computed over it. ⚠️ The expression is on the SELECT list and NOT in a predicate on
     * purpose: wrapping the indexed column in a WHERE clause would make the index unusable.
     */
    public const string DAYS_SQL = <<<'SQL'
        SELECT DISTINCT (awarded_at AT TIME ZONE ?)::date AS day
        FROM point_awards
        WHERE tenant_id = ? AND user_id = ?
        ORDER BY day DESC
        SQL;

    /**
     * This member's streak in this workspace.
     *
     * Returns {@see MemberStreak::none()} for a member with no awards — which is every member of every
     * workspace until the K1c backfill has run, and is why the backfill is a product decision of record
     * rather than a nicety (`App\Models\PointAward` is the engine's only stored history; named in prose
     * because this class never touches the model, and a `{@see}` here would make Pint import it).
     */
    public function for(string $tenantId, string $userId, ?string $timezone = null): MemberStreak
    {
        $zone = $timezone ?? self::DAY_BOUNDARY;

        $rows = DB::select(self::DAYS_SQL, [$zone, $tenantId, $userId]);

        $days = [];

        foreach ($rows as $row) {
            /** @var object{day: string} $row */
            $days[] = CarbonImmutable::parse((string) $row->day, $zone)->startOfDay();
        }

        return MemberStreak::fromDescendingDays($days, CarbonImmutable::now($zone)->startOfDay());
    }
}
