<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\PointRule;

/**
 * One award a replay wants to make — Increment K1c.
 *
 * The four arguments `PointsRecorder::award()` takes that are not the timestamp, carried as a value so the
 * decision (which rule, whose, about what) is made in one pure place and the writing is done in another.
 *
 * ⚠️ **`userId` IS NON-NULLABLE, AND THAT IS WHERE "CREDITS NOBODY" IS ENFORCED.** A guest submission, a
 * row whose actor was NULLed when the member's account was deleted, and an unreadable invitee all resolve
 * to no candidate at all rather than to a candidate with a null member. `award()` would return false for
 * such a row anyway — but it returns false for three different facts, so a replay counting its own output
 * could not tell "credited nobody, correctly" from "the write failed". Refusing to build the candidate is
 * what keeps the backfill's report honest.
 */
final readonly class AwardCandidate
{
    public function __construct(
        public PointRule $rule,
        public string $userId,
        public string $subjectType,
        public string $subjectId,
    ) {}
}
