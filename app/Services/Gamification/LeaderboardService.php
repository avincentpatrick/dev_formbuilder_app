<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\TenantUserStatus;
use App\Models\User;
use App\Services\Search\Arms\MemberSearchArm;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The workspace ladder's reader (gamification-design.md §10, ADR-0020 §D7, §D11) — Increment K1d.
 *
 * The {@see TeamProgressService} posture, and for its reasons: the tenant id is read from
 * {@see TenantContext} **once, here**, and passed to every statement, so the Eloquent half (which scopes to
 * the AMBIENT tenant) and the raw half (which scopes to whatever id it is handed) cannot be pointed at two
 * different workspaces. Off-tenant is an explicit guard rather than three quiet empty results.
 *
 * ── ⚠️ POINTS AND BADGES ARE READ SEPARATELY, AND THAT IS A CORRECTNESS DECISION, NOT A STYLE ONE ───────
 * The tempting shape is one statement joining `point_awards` and `badge_awards` onto the roster. It is
 * wrong: with a member holding 3 badges and 2 awards, the join produces six rows and `SUM(points)` returns
 * **three times** the real total, silently and plausibly. Two grouped reads keyed by user id cannot fan
 * out at all, so the failure is designed out rather than tested for. `LeaderboardTest` still pins it — with
 * a member holding at least two of each, because one badge and one award produce the same number under
 * both shapes and would let the bug through.
 *
 * ── ⚠️ WHO IS ON THE LADDER IS THE ROSTER READ, NOT THE LEDGER ──────────────────────────────────────────
 * `point_awards` is append-only, so a departed member's rows survive them (the table's own migration says
 * so: *"a removed member keeps their `users` row, so their awards survive and are simply visible to
 * nobody"*). The roster below is therefore what narrows the ladder, and it narrows it to ACTIVE members.
 *
 * ⚠️ **THE `users` RLS POLICY WOULD HAVE DONE THIS BY ACCIDENT, WHICH IS EXACTLY WHY THE PREDICATE IS
 * WRITTEN OUT.** `users_visibility` is *self OR an ACTIVE co-tenant membership*, and RLS applies at every
 * reference to `users` — {@see MemberSearchArm} records this measured on the seeded corpus, where joining
 * `tenant_users` to `users` returns the six active members and drops the invited one whose membership row
 * is perfectly visible. Relying on that would put this feature's roster rule in a 2026-07 migration, where
 * nobody reading this file could find it, and would make a later widening of that policy silently change
 * who appears on every leaderboard in the product. The `whereExists` is the same belt-and-braces that class
 * argues for, and it is expected to SURVIVE mutation for the same reason: RLS holds the line underneath.
 */
final class LeaderboardService
{
    /**
     * Every member's total, over the WHOLE tenant ledger — including members who have since left, whom
     * {@see Leaderboard::fromRoster()} then drops. Narrowing here instead would need a join to
     * `tenant_users` and would put the roster rule in two places.
     *
     * Served by `point_awards_tenant_user_awarded_idx`: `tenant_id` leads it and the predicate is equality,
     * so Postgres groups one contiguous stretch of the index. ADR-0020's Consequences accept this as an
     * aggregate rather than a lookup, and reserve a rollup for when a real query proves slow.
     */
    public const string POINTS_SQL = <<<'SQL'
        SELECT user_id, SUM(points) AS total
        FROM point_awards
        WHERE tenant_id = ?
        GROUP BY user_id
        SQL;

    /** Served by `badge_awards_tenant_user_badge_unique`'s leading `(tenant_id, user_id)`. */
    public const string BADGES_SQL = <<<'SQL'
        SELECT user_id, COUNT(*) AS total
        FROM badge_awards
        WHERE tenant_id = ?
        GROUP BY user_id
        SQL;

    /** The whole named ladder. `dashboard.org.view` only — see {@see LeaderboardEntry}. */
    public function forCurrentTenant(): Leaderboard
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null) {
            return Leaderboard::none();
        }

        return Leaderboard::fromRoster(
            $this->roster($tenantId),
            $this->totals(self::POINTS_SQL, $tenantId),
            $this->totals(self::BADGES_SQL, $tenantId),
        );
    }

    /**
     * One member's own position — the ungated half of §D7.
     *
     * Computed by ranking the whole ladder and then asking it, rather than by a cheaper direct query, and
     * the reason is that a rank is not a property of one member: it is *how many people are ahead of them*,
     * so the full aggregate is unavoidable either way. Sharing the one ranking means the number a member
     * sees on their own card and the number beside their name on the org list are the same number by
     * construction, and cannot drift the day one of the two is edited.
     */
    public function standingFor(string $userId): MemberStanding
    {
        return $this->forCurrentTenant()->standingFor($userId);
    }

    /**
     * The workspace's active members, as `[['id' => …, 'name' => …], …]`.
     *
     * ⚠️ **NO `withTrashed()`**, matching {@see MemberSearchArm} and deliberately unlike
     * `TenantMembershipService::listMembers()`, which needs it so every membership row renders a name. A
     * ladder is not a roster of records: a deactivated account is one the product treats as gone, and
     * naming it here would be the same widening. The consequence is that such a member leaves the ladder
     * and stays in the ledger, which is the §D11 gap and not a second one.
     *
     * ⚠️ **NO `ORDER BY` EITHER, AND ITS ABSENCE IS DELIBERATE RATHER THAN AN OVERSIGHT.**
     * {@see Leaderboard::fromRoster()} sorts this list on arrival, and its comparator falls through to
     * name and then id — so it returns 0 only for two members sharing both, which cannot happen. A
     * database sort here would therefore be discarded in every case while reading as though tie
     * ordering depended on it, which is how dead code survives three readers. Ordering is the value
     * object's rule and is stated there.
     *
     * @return list<array{id: string, name: string}>
     */
    private function roster(string $tenantId): array
    {
        // array_values() because Collection::all() is array<int, …> rather than a `list`, which is what
        // this method promises and what Leaderboard::fromRoster() indexes by position.
        return array_values(User::query()
            ->whereExists(function (QueryBuilder $sub) use ($tenantId): void {
                $sub->selectRaw('1')
                    ->from('tenant_users')
                    ->whereColumn('tenant_users.user_id', 'users.id')
                    ->where('tenant_users.tenant_id', $tenantId)
                    ->where('tenant_users.status', TenantUserStatus::Active->value);
            })
            ->get(['users.id', 'users.name'])
            ->map(static fn (User $member): array => [
                'id' => (string) $member->id,
                'name' => (string) $member->name,
            ])
            ->all());
    }

    /**
     * A `user_id => count` map from one of the grouped statements above.
     *
     * Both statements alias their aggregate to `total`, so this reads ONE fixed property rather than taking
     * the column name as an argument. That is not only tidier: a dynamic `$row->{$column}` is unresolvable
     * to static analysis, and it turns a typo in a caller into a runtime null instead of a red gate.
     *
     * `(int)` on the value is DEFENSIVE rather than load-bearing, and K1e corrected this note after
     * measuring it: this line used to assert flatly that PostgreSQL returns `SUM`/`COUNT` as a string
     * through PDO. **On this stack it does not** — `get_debug_type()` on both returns `int`, because
     * pdo_pgsql fetches native types here — so deleting the cast changes nothing today and a mutant that
     * does so survives. It stays because it is free and it is right where a string WOULD arrive (`SUM()`
     * over a `numeric` column returns `numeric`, which PDO does stringify), and because an unconverted
     * total would compare and sort as text, where '9' outranks '10'.
     *
     * @return array<string, int>
     */
    private function totals(string $sql, string $tenantId): array
    {
        $map = [];

        foreach (DB::select($sql, [$tenantId]) as $row) {
            /** @var object{user_id: string, total: int|string} $row */
            $map[(string) $row->user_id] = (int) $row->total;
        }

        return $map;
    }
}
