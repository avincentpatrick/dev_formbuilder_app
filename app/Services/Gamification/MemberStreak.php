<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use Carbon\CarbonImmutable;

/**
 * One member's activity streak, as {@see StreakCalculator} derived it — Increment K1c.
 *
 * A value rather than three loose returns, on the `ExtractManifest` precedent: `current` and `longest` are
 * read from the same walk over the same rows, and a caller that could obtain one without the other would
 * inevitably run the walk twice to render one card.
 *
 * ⚠️ **`current` IS NOT `longest` CLAMPED, AND THE TWO ANSWER DIFFERENT QUESTIONS.** `current` is a live
 * fact that decays — it is zero the day after a gap — while `longest` is a historical high-water mark that
 * can only ever rise. A surface that shows one and calls it the other tells a member they have lost an
 * achievement they still hold, or that they hold one they have lost.
 *
 * ── ⚠️ WHY ADJACENCY IS `addDay()->isSameDay()` AND NOT `diffInDays() === 1` ────────────────────────────
 * Two independent reasons, and the first one bites on this codebase today. Carbon 3's `diffInDays()` returns
 * a **float**, so `=== 1` is false against `1.0` and the streak would silently always be one day long. It is
 * also **signed**, so the argument order on a descending list is the opposite of what reads naturally, and
 * getting it backwards yields `-1.0` — likewise never equal. Second, calendar days are not fixed-length: the
 * boundary is UTC today, but this class is written so that pointing it at a zone with daylight saving does
 * not turn a 23-hour day into a broken run. Adding a day and asking which day you landed on is exact in
 * every zone.
 */
final readonly class MemberStreak
{
    private function __construct(
        /** Consecutive active days ending today or yesterday; 0 once a whole day has been missed. */
        public int $current,
        /** The longest run of consecutive active days anywhere in this member's ledger. */
        public int $longest,
        /** The most recent day this member earned anything, in the boundary timezone; null if never. */
        public ?CarbonImmutable $lastActiveOn,
    ) {}

    /**
     * @param  list<CarbonImmutable>  $activeDays  distinct active days, DESCENDING, in the boundary timezone
     */
    public static function fromDescendingDays(array $activeDays, CarbonImmutable $today): self
    {
        if ($activeDays === []) {
            return self::none();
        }

        return new self(
            current: self::currentRun($activeDays, $today),
            longest: self::longestRun($activeDays),
            lastActiveOn: $activeDays[0],
        );
    }

    /** A member with no awards at all — and the shape a caller gets before they have done anything. */
    public static function none(): self
    {
        return new self(current: 0, longest: 0, lastActiveOn: null);
    }

    /**
     * The run that is still alive.
     *
     * ⚠️ **A STREAK SURVIVES "NOTHING YET TODAY", AND THIS IS THE DECISION RATHER THAN AN OFF-BY-ONE.**
     * Requiring today's date would zero every member in the product between midnight and whenever they next
     * act — so a person on a fourteen-day run would be told at breakfast that they had none. The streak is
     * therefore alive if the most recent active day is today **or** yesterday, and breaks only once a full
     * day has passed with nothing in it. That is also the only rule under which the number a member sees in
     * the morning matches the one they saw last night.
     *
     * @param  list<CarbonImmutable>  $activeDays  descending
     */
    private static function currentRun(array $activeDays, CarbonImmutable $today): int
    {
        $mostRecent = $activeDays[0];

        $alive = $mostRecent->isSameDay($today) || self::isDayBefore($mostRecent, $today);

        return $alive ? self::runLengthFrom($activeDays, 0) : 0;
    }

    /**
     * @param  list<CarbonImmutable>  $activeDays  descending
     */
    private static function longestRun(array $activeDays): int
    {
        $longest = 0;

        for ($i = 0, $n = count($activeDays); $i < $n; $i++) {
            // Only start counting at the HEAD of a run — the entry before this one is not the day after it —
            // so the walk is O(n) overall rather than O(n²): every index is visited once as a run member and
            // at most once as a candidate head.
            if ($i > 0 && self::isDayBefore($activeDays[$i], $activeDays[$i - 1])) {
                continue;
            }

            $longest = max($longest, self::runLengthFrom($activeDays, $i));
        }

        return $longest;
    }

    /**
     * How many consecutive days run backwards from `$from`.
     *
     * The list is already DISTINCT, so a repeated day cannot inflate a run.
     *
     * @param  list<CarbonImmutable>  $activeDays  descending
     */
    private static function runLengthFrom(array $activeDays, int $from): int
    {
        $length = 1;

        for ($i = $from + 1, $n = count($activeDays); $i < $n; $i++) {
            if (! self::isDayBefore($activeDays[$i], $activeDays[$i - 1])) {
                break;
            }

            $length++;
        }

        return $length;
    }

    /** Is `$earlier` the calendar day immediately before `$later`? See the class docblock for why not a diff. */
    private static function isDayBefore(CarbonImmutable $earlier, CarbonImmutable $later): bool
    {
        return $earlier->addDay()->isSameDay($later);
    }
}
