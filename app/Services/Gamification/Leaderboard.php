<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * The workspace ladder, ranked (ADR-0020 §D7, gamification-design.md §10) — Increment K1d.
 *
 * The {@see MemberStreak} arrangement, for the same reason K1c chose it: {@see LeaderboardService} owns the
 * SQL and this owns the *rule*, so ranking — the part with ties, skips and an empty case in it — is a pure
 * function that can be exercised exhaustively without a database.
 *
 * ── ⚠️ THE ROSTER IS DECIDED HERE, ONCE, AND IT IS NOT THE LEDGER ───────────────────────────────────────
 * `$roster` is the workspace's ACTIVE members; the points map is the whole tenant's ledger. Everyone in the
 * roster appears — a member who has earned nothing is last, not absent — and **anyone in the ledger who is
 * not in the roster is dropped**. That second half is the load-bearing one:
 * `point_awards` is append-only and a departed member's rows survive by design, so the ledger outlives the
 * membership. A ladder naming people who have left the workspace is not a ladder anybody wants, and the
 * consequence — stated rather than discovered — is that **the entries here do not sum to
 * `TeamProgress::$points`**, which counts the whole ledger. See §D11.
 *
 * ── ⚠️ COMPETITION RANKING: TIES SHARE, AND THE NEXT RANK SKIPS ─────────────────────────────────────────
 * Two members tied for 2nd are followed by **4th**, not 3rd. That is `RANK`, not `DENSE_RANK`, and the
 * choice is forced by the sentence doc #28 §10 asks for: *"4th of 12"* has to mean *three people are ahead
 * of me*, or the number is not describing a position at all. ⚠️ Note what this costs to test: two members
 * cannot tell the two schemes apart, and neither can two tied at the TOP. It takes a tie followed by at
 * least one more member, which is what `LeaderboardTest` builds.
 *
 * Ordering inside a tie is by name then id — never by points alone, which leaves the order to whatever the
 * database happened to return and makes a stable-looking list reshuffle between two identical requests.
 */
final readonly class Leaderboard
{
    private function __construct(
        /** @var list<LeaderboardEntry> ranked, best first */
        public array $entries,
        /** The denominator in "4th of 12" — the roster's size, not the number of scorers. */
        public int $memberCount,
    ) {}

    /**
     * Rank a roster against the tenant's ledger totals.
     *
     * The two maps are read with `?? 0` rather than being required to cover the roster, because that is the
     * ordinary case rather than an error: a member who joined this morning is in the roster and in neither
     * map.
     *
     * @param  list<array{id: string, name: string}>  $roster  the workspace's active members
     * @param  array<string, int>  $pointsByUser  user id => points earned, from the whole ledger
     * @param  array<string, int>  $badgesByUser  user id => badges held
     */
    public static function fromRoster(array $roster, array $pointsByUser, array $badgesByUser): self
    {
        usort($roster, static function (array $a, array $b) use ($pointsByUser): int {
            return ($pointsByUser[$b['id']] ?? 0) <=> ($pointsByUser[$a['id']] ?? 0)
                ?: (strcmp($a['name'], $b['name'])
                    ?: strcmp($a['id'], $b['id']));
        });

        $entries = [];
        $previousPoints = null;
        $previousRank = 0;

        // No array_values(): usort() re-indexes in place, so $roster is already a list here.
        foreach ($roster as $index => $member) {
            $points = $pointsByUser[$member['id']] ?? 0;

            // Competition ranking: a tie inherits the rank above it, and everyone else takes their own
            // position — which is what makes the rank after a two-way tie skip a number.
            $rank = ($previousPoints === $points) ? $previousRank : $index + 1;

            $entries[] = new LeaderboardEntry(
                rank: $rank,
                userId: $member['id'],
                name: $member['name'],
                points: $points,
                badges: $badgesByUser[$member['id']] ?? 0,
            );

            $previousPoints = $points;
            $previousRank = $rank;
        }

        return new self(entries: $entries, memberCount: count($entries));
    }

    /** An empty ladder — off-tenant, or a workspace with no active members. */
    public static function none(): self
    {
        return new self(entries: [], memberCount: 0);
    }

    /**
     * One member's own position, WITHOUT the names beside it.
     *
     * This is what makes §D7's split structural rather than a matter of controller discipline: the ungated
     * `me` surface calls this and receives a type that cannot carry a colleague's name, so the gated list
     * and the ungated standing are computed from **one** ranking and still cannot leak into each other.
     *
     * Returns {@see MemberStanding::none()} for a user who is not on this ladder — off-tenant, or no longer
     * an active member, in which case their awards remain in the ledger and out of this list.
     */
    public function standingFor(string $userId): MemberStanding
    {
        foreach ($this->entries as $entry) {
            if ($entry->userId === $userId) {
                return MemberStanding::make(
                    rank: $entry->rank,
                    of: $this->memberCount,
                    points: $entry->points,
                    badges: $entry->badges,
                );
            }
        }

        return MemberStanding::none();
    }
}
