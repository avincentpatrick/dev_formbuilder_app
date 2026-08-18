<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * The whole workspace scoreboard — the named ladder, plus what the workspace has collectively done
 * (ADR-0020 §D7, §D11) — Increment K1d.
 *
 * The gated counterpart to {@see MemberProgress}, and composed for the same reason: two independent readers
 * whose answers belong on one screen should be read once, together, so a client cannot observe them at two
 * different instants and report a workspace that adds up differently from itself.
 *
 * ⚠️ **THE TWO HALVES DELIBERATELY DISAGREE, IN THREE PLACES, AND A SURFACE PUTTING THEM SIDE BY SIDE MUST
 * SAY WHICH IS WHICH.** None of these is a defect; each is a decision, and each is the kind of thing a
 * later reader "fixes":
 *
 *   1. `team->responses` exceeds the sum of members' `submission.collected` awards by exactly the GUEST
 *      submissions (ADR-0020 §D8/§D10(c)). A guest response is a response the workspace collected, and it
 *      credits nobody — otherwise the ladder would be decided by whoever published the busiest public link.
 *      On a tenant collecting mainly through public links this gap is most of the total.
 *   2. `team->points` is at least the sum of `ladder->entries`' points, and the difference is awards earned
 *      by members who have since LEFT. `point_awards` is append-only, so their rows outlive their
 *      membership; the ladder names active members only (§D11).
 *   3. `team->contributors` is likewise at least the number of entries with points, for the same reason.
 */
final readonly class Scoreboard
{
    public function __construct(
        public Leaderboard $ladder,
        public TeamProgress $team,
    ) {}
}
