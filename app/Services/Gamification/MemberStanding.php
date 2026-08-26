<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * Where one member sits on their workspace's ladder — the *"4th of 12"* half of ADR-0020 §D7
 * (gamification-design.md §10) — Increment K1d.
 *
 * §D7's split is the whole reason this type exists separately from {@see Leaderboard}: **every** member may
 * see their own position with no permission at all, while the NAMED list beside it is gated on
 * `dashboard.org.view`. Keeping the two in separate types means the gated surface is the one that carries
 * other people's names, and a controller cannot leak the list by reaching for the wrong field.
 *
 * ⛔ **`of` IS THE ONE FIELD HERE THAT IS NOT COVERED BY THAT SENTENCE, AND M26 CORRECTED IT — SEE §D13.**
 * §D7's criterion is **names**: it gates *"the NAMED ranked list"*. K1e's `AchievementsController`
 * docblock deliberately **replaced** that criterion, rejecting *"names are gated, plain counts are not"* and
 * re-gating `TeamProgress` — whose `activeMembers` is this same number — on **workspace-wide figures about
 * other people's work**, calling the alternative *"a widening of an existing permission, performed by a new
 * page"*. `of` was never re-walked against the replacement, so one file argued both sides at once. It is now
 * withheld from a reader without `dashboard.org.view`, which is the same key `/dashboard`'s Members tile,
 * `/members` and the member search arm already answer this question with.
 *
 * ⚠️ **`rank` DELIBERATELY SURVIVES, AND A LATER READER MUST NOT "COMPLETE" THIS SWEEP BY REMOVING IT.** A
 * rank of 4 discloses that at least four members exist — a **floor**, not the headcount, and one anybody with
 * colleagues can infer. §D7's actual grant is that a member sees their own position; withholding `rank` would
 * spend that promise to blur a bound the reader already holds.
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
        /**
         * How many members the ladder holds — active membership, not scorers. See the class docblock.
         *
         * **Null means WITHHELD, not "none".** The empty ladder reports `0` ({@see none()}), so a surface can
         * tell "this workspace has nobody on it" from "you may not be told" without re-deriving either.
         */
        public ?int $of,
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
     * The same standing with the workspace headcount withheld — for a reader without `dashboard.org.view`
     * (M26, ADR-0020 §D13).
     *
     * ⚠️ **A METHOD ON THE VALUE OBJECT RATHER THAN A TERNARY AT EACH CALL SITE, AND THAT IS THE WHOLE POINT
     * OF THE FIX.** The defect §D13 records was not that somebody chose wrongly — it was that THREE surfaces
     * each decided this independently and two of them never made the decision at all. A ternary in the
     * controller would restore exactly that shape and leave the fourth surface free to get it wrong again.
     * Here the withheld standing is a VALUE: a caller that has one cannot leak the headcount by reaching for
     * the wrong field, which is the same argument the class docblock makes for keeping this type separate
     * from {@see Leaderboard}.
     */
    public function withoutHeadcount(): self
    {
        return new self(rank: $this->rank, of: null, points: $this->points, badges: $this->badges);
    }

    /**
     * No standing at all — off-tenant, or a user who is not an active member of this workspace.
     *
     * `rank` is null rather than 0 deliberately. Zero would render as a position, and "you are 0th of 0" is
     * a sentence no surface should have to special-case; null is the one value that cannot be mistaken for
     * one.
     *
     * ⚠️ **`of` STAYS `0` HERE RATHER THAN `null`, WHICH IS NOT AN INCONSISTENCY WITH {@see withoutHeadcount()}.**
     * The two nulls would mean different things and only one of them is a secret: `0` says *this ladder is
     * empty*, which discloses nothing and is true; `null` says *you may not be told*. Collapsing them would
     * make a withheld headcount and an empty workspace indistinguishable to every surface downstream.
     *
     * The reason this case is reachable at all is {@see TeamProgressService}'s: every read behind it is
     * RLS-filtered, and an RLS-filtered read with no tenant GUC returns no rows rather than raising — so
     * without an explicit empty case, "you have no standing" and "you forgot tenant context" are the same
     * output.
     */
    public static function none(): self
    {
        return new self(rank: null, of: 0, points: 0, badges: 0);
    }
}
