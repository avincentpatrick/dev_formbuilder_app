<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * Where one member sits on their workspace's ladder — the *"4th of 12"* half of ADR-0020 §D7
 * (gamification-design.md §10) — Increment K1d.
 *
 * §D7's split is the whole reason this type exists separately from {@see Leaderboard}: **every** member may
 * see this about themselves, with no permission at all, while the NAMED list beside it is gated on
 * `dashboard.org.view`. Keeping the two in separate types means the gated surface is the one that carries
 * other people's names, and a controller cannot leak the list by reaching for the wrong field.
 *
 * ⚠️ **`of` IS THE TEAM, NOT THE SCOREBOARD, AND THE SPEC DID NOT SAY WHICH.** doc #28 §10 asks for
 * *"4th of 12"* without defining the twelve. It is the count of ACTIVE members — the same number
 * `TeamProgress::$activeMembers` and `DashboardMetricsService::activeMembersCount()` already report — and
 * **not** the count of members who have scored anything. A member who has earned nothing is last of twelve
 * rather than absent, because "you are not on the board" is a worse answer than "you are twelfth", and
 * because a denominator that grows as colleagues start earning would make a member's own position appear to
 * move on a day they did nothing. {@see Leaderboard} is the one place that decides the roster.
 *
 * ⚠️ **`rank` IS COMPETITION RANKING, SO IT SKIPS.** Two members tied for 2nd are followed by **4th**, not
 * 3rd: the rank is *one plus the number of people strictly ahead of you*, which is the only reading under
 * which "4th of 12" and "three people are ahead of me" agree. See {@see Leaderboard::fromRoster()}.
 */
final readonly class MemberStanding
{
    private function __construct(
        /** Position on the ladder, 1-based and competition-ranked; null when this member is not on it. */
        public ?int $rank,
        /** How many members the ladder holds — active membership, not scorers. See the class docblock. */
        public int $of,
        /** Every point this member has earned in this workspace, at the weight it was earned at. */
        public int $points,
        /** How many badges this member holds here. */
        public int $badges,
    ) {}

    public static function make(int $rank, int $of, int $points, int $badges): self
    {
        return new self(rank: $rank, of: $of, points: $points, badges: $badges);
    }

    /**
     * No standing at all — off-tenant, or a user who is not an active member of this workspace.
     *
     * `rank` is null rather than 0 deliberately. Zero would render as a position, and "you are 0th of 0" is
     * a sentence no surface should have to special-case; null is the one value that cannot be mistaken for
     * one. The reason this case is reachable at all is {@see TeamProgressService}'s: every read behind it is
     * RLS-filtered, and an RLS-filtered read with no tenant GUC returns no rows rather than raising — so
     * without an explicit empty case, "you have no standing" and "you forgot tenant context" are the same
     * output.
     */
    public static function none(): self
    {
        return new self(rank: null, of: 0, points: 0, badges: 0);
    }
}
