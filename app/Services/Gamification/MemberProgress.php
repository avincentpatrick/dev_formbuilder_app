<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * Everything one member may see about their own progress, with no permission at all (ADR-0020 §D7) —
 * Increment K1d.
 *
 * A composite of two independent readers — {@see LeaderboardService::standingFor()} and
 * {@see StreakCalculator::for()} — which are kept apart because they answer separable questions and are
 * derived from the ledger in different ways. This type exists so the ungated surface has ONE shape rather
 * than a controller assembling a loose array, and so K1e can consume the same thing server-side that the
 * API serializes.
 *
 * ⚠️ **NOTHING HERE NAMES ANOTHER PERSON, AND THAT IS THE INVARIANT.** §D7 mints no permission and instead
 * splits the feature between this type and {@see Leaderboard}: everything a member may see about themselves
 * lives here, and the moment a colleague's name would be added it stops being the ungated half.
 */
final readonly class MemberProgress
{
    public function __construct(
        public MemberStanding $standing,
        public MemberStreak $streak,
    ) {}
}
