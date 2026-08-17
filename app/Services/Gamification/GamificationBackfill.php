<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\PointRule;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replays a workspace's real history into the award ledger (ADR-0020 §D5/§D10) — Increment K1c.
 *
 * The product decision of record (2026-08-17) is that existing workspaces are BACKFILLED rather than
 * starting at zero: everyone starting empty leaves the demo tenants blank and the feature untestable on
 * arrival. This class is the enumerator; `ReplayTenantHistoryJob` is where it runs and
 * `BackfillGamificationCommand` is how an operator starts it.
 *
 * ── ⚠️ TWO SOURCES, NOT ONE, AND THE SECOND ONE IS A CORRECTION TO §D5's OWN SENTENCE ──────────────────
 * §D5 says "backfilled once from `audits`". Verified against the code, that is not possible for two of the
 * seven rules: `TenantMembershipService::invite()` writes **no audit row at all**, and `accept()` writes
 * neither an audit row nor an event — so the `('tenant_users','created')` row covers only the three
 * self-serve doors. `tenant_users` itself carries `invited_by`, `invited_at` and `joined_at` and is
 * complete for every door, so it is the authority for both member rules and `audits` is the authority for
 * the five act rules. ADR-0020 §D10 records the correction.
 *
 * ── ⚠️ WHY THIS CANNOT BE A MIGRATION, WHICH IS WHERE EVERY OTHER BACKFILL IN THIS CODEBASE LIVES ──────
 * The five classes under `app/Support/Migrations/` all run inside a migration on the `pgsql_privileged`
 * connection, which BYPASSES RLS. That route is closed here and closing it is the point: this writes
 * through `PointsRecorder::award()`, whose raw INSERT relies on the strict `WITH CHECK` policy to prove it
 * is writing where it thinks it is. A migration executes as `meridian_app` with **no tenant GUC**, where
 * `tenant_id = NULL` matches nothing — `INSERT…SELECT` there writes zero rows, reads zero rows, and
 * reports success. Hence one `TenantAwareJob` per tenant, under a real GUC.
 *
 * ── ⚠️ CHRONOLOGICAL WITHIN EACH RULE IS ALL THAT IS REQUIRED, AND THAT IS WHAT MAKES TWO SOURCES SAFE ─
 * `badge_awards.awarded_at` is copied from the point award that crossed the threshold, and every badge in
 * the catalog counts exactly ONE `PointRule` — so the crossing row is the chronologically-Nth award of
 * that rule, whatever order the rules themselves were replayed in. Membership therefore runs first and the
 * audit walk second, with no merge and no sort across the two. **Do not "fix" this into a merge sort**:
 * within a source the ordering is real and load-bearing (`audits.id` is a uuidv7, so `ORDER BY id` is both
 * chronological and index-covered), and across sources it buys nothing.
 *
 * ⚠️ **EVERY AWARD IS WRITTEN WITH `announceBadges: false`.** A replay earns badges for real, which is
 * wanted — but a long-standing member would otherwise be told about most of the catalog at once, for
 * things they did last year. `BadgeAwardTest` pins both the suppression and the default.
 */
final class GamificationBackfill
{
    /**
     * Audit rows per job. Short enough that one chunk finishes well inside `TenantAwareJob`'s 60s timeout
     * — the job re-dispatches itself rather than raising that, so a big tenant makes progress in committed
     * steps instead of losing a whole transaction to a timeout.
     */
    public const int CHUNK = 500;

    /** The zero uuid sorts before every uuidv7, so it opens the keyset walk. The J2e backfill precedent. */
    public const string CURSOR_START = '00000000-0000-0000-0000-000000000000';

    /**
     * The act rules, in `audits.id` order — which is chronological, because the column is a uuidv7.
     *
     * ⚠️ **THE `json_agg` OF `jsonb_object_keys` IS NOT A CONVENIENCE.** It is what keeps audit VALUES out
     * of PHP entirely: {@see AuditReplayMap} needs only the SHAPE of `new_values` to tell a review from an
     * edit, and a compliance ledger's redacted contents have no business travelling through a scoring
     * service. `jsonb_typeof` guards it, because `jsonb_object_keys` raises on a non-object.
     *
     * ⚠️ **THE LEFT JOIN IS ADR-0020 §D8 MADE STRUCTURAL.** Collection credits the member on the
     * SUBMISSION, and reading the audit's own actor instead would agree on every production row today and
     * silently diverge on a fixture row or a half-fired `nullOnDelete`. The join is confined to submission
     * rows by its own ON clause, so it costs nothing on the other three tuples.
     *
     * The `a.tenant_id = ?` predicate is documentation of the scope the caller asserted — RLS is what
     * actually confines this, and both tables are STRICT.
     */
    public const string AUDITS_SQL = <<<'SQL'
        SELECT a.id,
               a.auditable_type,
               a.event,
               a.auditable_id,
               a.user_id,
               a.created_at,
               s.respondent_user_id,
               CASE WHEN jsonb_typeof(a.new_values) = 'object'
                    THEN (SELECT json_agg(k) FROM jsonb_object_keys(a.new_values) AS k)
                    ELSE NULL END AS new_value_keys
        FROM audits a
        LEFT JOIN submissions s ON a.auditable_type = 'submission' AND s.id = a.auditable_id
        WHERE a.tenant_id = ?
          AND (a.auditable_type, a.event) IN (%s)
          AND a.id > ?::uuid
        ORDER BY a.id
        LIMIT ?
        SQL;

    /**
     * Every membership that ever completed, oldest first.
     *
     * No status predicate: a member who joined and was later removed DID join, and the live listener
     * awarded them at the time. `point_awards` keeps their row and shows it to nobody — the consequence
     * data-dictionary §31 already documents for a removed member.
     */
    public const string JOINED_SQL = <<<'SQL'
        SELECT user_id, joined_at
        FROM tenant_users
        WHERE tenant_id = ? AND joined_at IS NOT NULL
        ORDER BY joined_at
        SQL;

    /**
     * Every invitation ever sent, oldest first, with the invitee's address for the digest.
     *
     * ⚠️ **A LEFT JOIN, AND THE NULLS ARE THE INTERESTING ROWS.** `users` carries its own RLS policy —
     * visible if the row is you, or an **active** co-member — and a tenant job runs with `current_user_id`
     * unset, so a still-pending invitee's `users` row is invisible from in here. An inner join would drop
     * those rows and the count would silently be short; the left join keeps them and they are reported as
     * uncredited. The award cannot be made without the address, because the idempotency key is a digest of
     * it and a second key would let one invitation be scored twice.
     */
    public const string INVITED_SQL = <<<'SQL'
        SELECT tu.invited_by, tu.invited_at, u.email
        FROM tenant_users tu
        LEFT JOIN users u ON u.id = tu.user_id
        WHERE tu.tenant_id = ? AND tu.invited_at IS NOT NULL AND tu.invited_by IS NOT NULL
        ORDER BY tu.invited_at
        SQL;

    public function __construct(
        private readonly PointsRecorder $points,
        private readonly AuditReplayMap $map,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Is the engine switched on for this workspace?
     *
     * Asked ONCE, up front, by both entry points — and the reason is that `PointsRecorder::award()` returns
     * `false` for "the module is off" using the very same value it returns for "already awarded". Without
     * this check a disabled tenant would report a long, plausible list of rows it had supposedly already
     * scored, when in fact it had scored none of them and never would.
     *
     * ⚠️ The id drops the per-tenant memo (the `ReconcileTenantUsageJob` precedent — a worker's container is
     * long-lived and the plan is memoized per tenant); the ANSWER is read under the ambient context, which
     * every caller has already established as this tenant.
     */
    public function moduleEnabled(string $tenantId): bool
    {
        $this->entitlements->forget($tenantId);

        return $this->entitlements->feature(PointsRecorder::FEATURE);
    }

    /**
     * One whole workspace, in one call — the inline path an operator watches finish.
     *
     * The queued path deliberately does NOT use this: it takes one chunk per job and re-dispatches, so a
     * large tenant makes progress in committed steps rather than risking a whole transaction to
     * `TenantAwareJob`'s timeout. Both walk the identical enumeration; only the loop differs.
     */
    public function replayTenant(string $tenantId, int $limit = self::CHUNK): BackfillTally
    {
        $tally = $this->replayMemberships($tenantId);
        $cursor = null;

        do {
            $chunk = $this->replayAudits($tenantId, $cursor, $limit);
            $tally = $tally->plus($chunk->tally);
            $cursor = $chunk->nextCursor;
        } while ($cursor !== null);

        return $tally;
    }

    /**
     * The two membership rules, from the membership table. Runs once per tenant, on the first chunk.
     */
    public function replayMemberships(string $tenantId): BackfillTally
    {
        return $this->replayJoins($tenantId)->plus($this->replayInvites($tenantId));
    }

    /**
     * One chunk of the act rules, and where to resume.
     *
     * A short final page ends the walk: fewer rows than the limit means the ledger is exhausted, so there
     * is no extra round trip to discover it. `$afterAuditId` of null starts at {@see self::CURSOR_START}.
     */
    public function replayAudits(string $tenantId, ?string $afterAuditId, int $limit = self::CHUNK): BackfillChunk
    {
        $pairs = AuditReplayMap::SCORED_PAIRS;

        // A row-constructor IN list, one placeholder pair per scored tuple — so the tuples are BOUND rather
        // than interpolated and the SQL text is still a single assertable constant. `(a, b) IN ((?,?),…)`
        // is exact where `a = ANY(…) AND b = ANY(…)` would admit the cross product; see SCORED_PAIRS.
        $sql = sprintf(self::AUDITS_SQL, implode(', ', array_fill(0, count($pairs), '(?, ?)')));

        $bindings = [$tenantId];

        foreach ($pairs as [$type, $event]) {
            $bindings[] = $type;
            $bindings[] = $event;
        }

        $bindings[] = $afterAuditId ?? self::CURSOR_START;
        $bindings[] = $limit;

        $rows = DB::select($sql, $bindings);

        $tally = new BackfillTally;
        $lastId = null;

        foreach ($rows as $row) {
            /** @var object{id: string, auditable_type: string, event: string, auditable_id: string, user_id: ?string, created_at: string, respondent_user_id: ?string, new_value_keys: ?string} $row */
            $lastId = (string) $row->id;

            $tally = $tally->plus($this->awardFor(
                new ReplayableAudit(
                    auditId: (string) $row->id,
                    auditableType: (string) $row->auditable_type,
                    event: (string) $row->event,
                    auditableId: (string) $row->auditable_id,
                    actorUserId: $row->user_id === null ? null : (string) $row->user_id,
                    respondentUserId: $row->respondent_user_id === null ? null : (string) $row->respondent_user_id,
                    newValueKeys: self::decodeKeys($row->new_value_keys),
                ),
                Carbon::parse((string) $row->created_at),
            ));
        }

        return new BackfillChunk($tally, count($rows) < $limit ? null : $lastId);
    }

    private function awardFor(ReplayableAudit $row, Carbon $at): BackfillTally
    {
        // Two questions, asked separately on purpose: "does this row evidence an act" and "is there anybody
        // to credit for it" are different facts about a workspace, and collapsing them would report a
        // tenant that collects entirely through public links as one full of unrecognised audit rows.
        if ($this->map->rule($row) === null) {
            return new BackfillTally(scanned: 1, unmapped: 1);
        }

        $candidate = $this->map->candidate($row);

        if ($candidate === null) {
            return new BackfillTally(scanned: 1, uncredited: 1);
        }

        return $this->award($candidate->rule, $candidate->userId, $candidate->subjectType, $candidate->subjectId, $at);
    }

    private function replayJoins(string $tenantId): BackfillTally
    {
        $tally = new BackfillTally;

        foreach (DB::select(self::JOINED_SQL, [$tenantId]) as $row) {
            /** @var object{user_id: string, joined_at: string} $row */
            $userId = (string) $row->user_id;

            // The subject is the USER id, matching `AwardPointsForMemberJoined`. ⚠️ Note what it is NOT:
            // the `('tenant_users','created')` audit row's `auditable_id` is the MEMBERSHIP uuid, so a
            // replay that had gone through `audits` would have keyed on a different subject — and the
            // unique index would NOT have caught it, because the subject is part of the key. A doubled
            // score with no error is the failure this line avoids.
            $tally = $tally->plus($this->award(
                PointRule::MemberJoined,
                $userId,
                'member',
                $userId,
                Carbon::parse((string) $row->joined_at),
            ));
        }

        return $tally;
    }

    private function replayInvites(string $tenantId): BackfillTally
    {
        $tally = new BackfillTally;

        foreach (DB::select(self::INVITED_SQL, [$tenantId]) as $row) {
            /** @var object{invited_by: string, invited_at: string, email: ?string} $row */
            $email = $row->email === null ? null : (string) $row->email;

            if ($email === null || $email === '') {
                $tally = $tally->plus(new BackfillTally(scanned: 1, uncredited: 1));

                continue;
            }

            // ⚠️ `PointsRecorder::emailSubject()` and never a second copy of the hash. It is a static on the
            // recorder for exactly this caller — two implementations of one hash rule is two chances to
            // disagree, and the symptom would be a duplicate award rather than an error.
            $tally = $tally->plus($this->award(
                PointRule::MemberInvited,
                (string) $row->invited_by,
                'invite',
                PointsRecorder::emailSubject($tenantId, $email),
                Carbon::parse((string) $row->invited_at),
            ));
        }

        return $tally;
    }

    /** One award, with the act's own date and no announcement. See the class docblock for both. */
    private function award(PointRule $rule, string $userId, string $subjectType, string $subjectId, Carbon $at): BackfillTally
    {
        $created = $this->points->award($rule, $userId, $subjectType, $subjectId, $at, announceBadges: false);

        return new BackfillTally(scanned: 1, created: $created ? 1 : 0, existing: $created ? 0 : 1);
    }

    /**
     * @return list<string>
     */
    private static function decodeKeys(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $key): string => (string) $key, $decoded));
    }
}
