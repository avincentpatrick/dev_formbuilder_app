<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * One named row of the workspace ladder (ADR-0020 §D7, gamification-design.md §10) — Increment K1d.
 *
 * ⚠️ **THIS TYPE CARRIES A PERSON'S NAME, WHICH IS WHAT MAKES ITS CONTAINER THE GATED ONE.** §D7 mints no
 * thirtieth permission and reuses the org/own split instead: a member's own position travels as
 * {@see MemberStanding}, which names nobody, while a list of these is `dashboard.org.view` only. Anything
 * that adds a field here is widening what that permission discloses about a colleague, and should be read
 * as such rather than as a convenience for a template.
 */
final readonly class LeaderboardEntry
{
    public function __construct(
        /** 1-based competition rank — ties share a rank and the next one skips. {@see Leaderboard}. */
        public int $rank,
        public string $userId,
        public string $name,
        /** Every point this member earned here, at the weight it was earned at (ADR-0020 §D4). */
        public int $points,
        public int $badges,
    ) {}
}
