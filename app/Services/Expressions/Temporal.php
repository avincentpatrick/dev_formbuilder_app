<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Enums\ComparisonOperator;

/**
 * Chronological ordering of ISO-8601 dates, times and date-times (Increment M134, `R-62b638e1`; `D71`, `D89`) —
 * reached ONLY from {@see ExpressionEvaluator::numericCompare()} once either operand has failed to be a number. A
 * numeric-like string never parses here and an ISO value is never numeric-like, so the two paths are disjoint and
 * every vector that pinned "a non-numeric operand makes an ordering false" still holds for every non-temporal
 * operand. The TypeScript twin is `resources/public-runtime/engine/temporal.ts`; `tests/golden/expressions/
 * temporal.json` and `tests/golden/validation/temporal.json` hold the two together.
 *
 * ⛔ NOT {@see Coercion}. Coercion is the byte-for-byte contract both engines pin, and `R-af395416` forbids
 * widening it: a date is not a number. This class answers one question — does one value come before another — and
 * nothing else reads it. EQUALITY IS UNTOUCHED: `'09:00' = '09:00:00'` stays false, as text, while both orderings
 * between them hold.
 *
 * WHAT PARSES — strings only, strictly, so an API client's free text reads as "not a date" rather than a guess:
 * a date `YYYY-MM-DD`, a time `HH:MM[:SS]` (00-23, 00-59), and a date-time `YYYY-MM-DDTHH:MM[:SS]` with an
 * optional `Z` or `±HH:MM` offset. Calendar-valid (leap years), years 0001-9999. Refused: a space for the `T`, a
 * lower-case `t`/`z`, `24:00`, `:60`, fractional seconds, unpadded parts. The browser's `date`, `time` and
 * `datetime-local` controls and the OCR reader all emit these shapes.
 *
 * WHAT COMPARES (anything else is false, as a non-number always was):
 *   - a date with a date, and a naive date-time with a naive date-time; a date with a naive date-time reads the
 *     date as its midnight (ODK's reading);
 *   - a time with a time;
 *   - a date-time carrying an offset with another carrying one, as instants;
 *   - NEVER a naive value with a zoned one (`D89`): an answer is wall-clock with no zone and `now()` is UTC, so any
 *     answer is incomparable with `now()` — publish refuses that ordering, and the engines agree it never holds.
 *
 * ⚠️ `\A…\z`, NEVER `^…$`: PCRE's `$` also matches before a final newline, so `"2026-07-11\n"` would parse here
 * and not in the browser. No `u` flag, matching the TypeScript twin's flagless patterns.
 */
final class Temporal
{
    private const DATE = '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/';

    private const TIME = '/\A([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?\z/';

    private const DATETIME = '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?(Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])?\z/';

    private const SECONDS_PER_DAY = 86400;

    /** Does `$left $op $right` hold chronologically? False whenever either side is not a temporal value, or the two cannot be compared. */
    public static function orders(mixed $left, mixed $right, ComparisonOperator $op): bool
    {
        $a = self::parse($left);
        $b = self::parse($right);

        if ($a === null || $b === null || ! self::comparable($a, $b)) {
            return false;
        }

        return match ($op) {
            ComparisonOperator::Gt => $a['key'] > $b['key'],
            ComparisonOperator::Lt => $a['key'] < $b['key'],
            ComparisonOperator::Gte => $a['key'] >= $b['key'],
            ComparisonOperator::Lte => $a['key'] <= $b['key'],
            default => false,
        };
    }

    /**
     * A strict parse — null for anything that is not exactly one of the three shapes. `key` is an integer count of
     * seconds (from a fixed origin for a date or date-time — only differences are ever read — and from midnight for a time), shifted to UTC when zoned.
     *
     * @return array{kind: 'date'|'time'|'datetime', zoned: bool, key: int}|null
     */
    public static function parse(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match(self::DATE, $value, $m) === 1) {
            $days = self::days((int) $m[1], (int) $m[2], (int) $m[3]);

            return $days === null ? null : ['kind' => 'date', 'zoned' => false, 'key' => $days * self::SECONDS_PER_DAY];
        }

        if (preg_match(self::TIME, $value, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            return ['kind' => 'time', 'zoned' => false, 'key' => self::secondsOfDay((string) $m[1], (string) $m[2], $m[3] ?? null)];
        }

        if (preg_match(self::DATETIME, $value, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            $days = self::days((int) $m[1], (int) $m[2], (int) $m[3]);

            if ($days === null) {
                return null;
            }

            $offset = $m[7] ?? null;
            $key = $days * self::SECONDS_PER_DAY + self::secondsOfDay((string) $m[4], (string) $m[5], $m[6] ?? null);

            return ['kind' => 'datetime', 'zoned' => $offset !== null, 'key' => $key - self::offsetSeconds($offset)];
        }

        return null;
    }

    /**
     * @param  array{kind: 'date'|'time'|'datetime', zoned: bool, key: int}  $a
     * @param  array{kind: 'date'|'time'|'datetime', zoned: bool, key: int}  $b
     */
    private static function comparable(array $a, array $b): bool
    {
        if ($a['kind'] === 'time' || $b['kind'] === 'time') {
            return $a['kind'] === $b['kind'];
        }

        return $a['zoned'] === $b['zoned'];
    }

    /** Days from a fixed origin (0000-03-01) for a calendar-valid date, else null — integer arithmetic only, never a DateTime. */
    private static function days(int $year, int $month, int $day): ?int
    {
        if ($year < 1 || $month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            return null;
        }

        $shift = $month <= 2 ? 1 : 0;
        $y = $year - $shift;
        $m = $month + 12 * $shift - 3;

        return 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) + intdiv(153 * $m + 2, 5) + $day - 1;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            return ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0)) ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    private static function secondsOfDay(string $hours, string $minutes, ?string $seconds): int
    {
        return (int) $hours * 3600 + (int) $minutes * 60 + (int) ($seconds ?? '0');
    }

    private static function offsetSeconds(?string $offset): int
    {
        if ($offset === null || $offset === 'Z') {
            return 0;
        }

        $magnitude = (int) substr($offset, 1, 2) * 3600 + (int) substr($offset, 4, 2) * 60;

        return $offset[0] === '-' ? -$magnitude : $magnitude;
    }
}
