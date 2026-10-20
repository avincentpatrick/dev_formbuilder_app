<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * Date arithmetic for the expression library (M180, `R-da02fe03`, `D155`): `date_diff()`, `date_add()` and `age()`.
 * The TypeScript twin is `resources/public-runtime/engine/date-math.ts`; `tests/golden/expressions/date-functions.json`
 * holds the two together. Built on {@see Temporal}'s strict parse, integer arithmetic only — never a DateTime, so no
 * server time zone, locale or library can move a result.
 *
 * WHAT COUNTS (`D155`): whole completed units, truncated toward zero, the way a spreadsheet's DATEDIF and an age
 * count — 8 h 30 min is 8 hours, and a person born on 2004-02-29 turns 22 on 2026-03-01. A month is complete when the
 * day of the month, then the time of day, has been reached again; a year is twelve of them.
 *
 * WHAT PAIRS, as {@see Temporal} orders them: two dates or date-times (a date reads as its midnight), two times, two
 * date-times carrying an offset (as instants) — never a value with an offset against one without (`D89`), never a
 * time against a date. Anything else, or an answer that is not a date, is blank. A time takes only hours and minutes;
 * a date takes no hours or minutes when one is added to it.
 *
 * WHAT `date_add()` WRITES: the shape it was given — a date as `YYYY-MM-DD`, a date-time with seconds only if it had
 * them and its own offset kept, a time wrapped around midnight. A month added to the 31st lands on the month's last
 * day. A result outside the years 0001-9999 is blank.
 */
final class DateMath
{
    /** @var list<string> the units both functions accept, as the quoted word */
    public const UNITS = ['years', 'months', 'weeks', 'days', 'hours', 'minutes'];

    private const SECONDS = ['weeks' => 604800, 'days' => 86400, 'hours' => 3600, 'minutes' => 60];

    private const SECONDS_PER_DAY = 86400;

    private const MAX_COUNT = 1e10;

    /** `date_diff(start, end, unit)` — whole units from start to end (negative when end is earlier), or Absent. */
    public static function diff(mixed $start, mixed $end, string $unit): int|Marker
    {
        $a = Temporal::parse($start);
        $b = Temporal::parse($end);

        if ($a === null || $b === null || ($a['kind'] === 'time') !== ($b['kind'] === 'time') || $a['zoned'] !== $b['zoned']) {
            return Marker::Absent;
        }

        if ($unit === 'years' || $unit === 'months') {
            if ($a['kind'] === 'time') {
                return Marker::Absent;
            }

            $months = self::completeMonths($a['key'], $b['key']);

            return $unit === 'months' ? $months : intdiv($months, 12);
        }

        return intdiv($b['key'] - $a['key'], self::SECONDS[$unit]);
    }

    /** `date_add(date, n, unit)` — the value moved by a whole n units, in the shape it was given, or Absent. */
    public static function add(mixed $value, mixed $n, string $unit): string|Marker
    {
        $parsed = Temporal::parse($value);
        $number = Coercion::toNumber($n);

        // A count past ten billion leaves the years 0001-9999 in any unit — and would overflow an integer first.
        if ($parsed === null || ! is_string($value) || is_nan($number) || ! is_finite($number) || abs($number) >= self::MAX_COUNT) {
            return Marker::Absent;
        }

        $count = (int) $number; // truncated toward zero

        if ($parsed['kind'] === 'time') {
            if ($unit !== 'hours' && $unit !== 'minutes') {
                return Marker::Absent;
            }

            $seconds = self::floorMod($parsed['key'] + $count * self::SECONDS[$unit], self::SECONDS_PER_DAY);

            return self::clock($seconds, strlen($value) === 8);
        }

        if ($parsed['kind'] === 'date') {
            if ($unit === 'hours' || $unit === 'minutes') {
                return Marker::Absent;
            }

            $days = self::moved(intdiv($parsed['key'], self::SECONDS_PER_DAY), $count, $unit);

            return $days === null ? Marker::Absent : self::calendar($days);
        }

        // A date-time: moved on its own wall clock, so its offset is kept as written.
        $withSeconds = strlen($value) > 16 && $value[16] === ':';
        $suffix = substr($value, $withSeconds ? 19 : 16);
        $wall = $parsed['key'] + Temporal::offsetSeconds($suffix === '' ? null : $suffix);
        $day = self::floorDiv($wall, self::SECONDS_PER_DAY);
        $time = $wall - $day * self::SECONDS_PER_DAY;

        if (isset(self::SECONDS[$unit]) && $unit !== 'weeks' && $unit !== 'days') {
            $wall += $count * self::SECONDS[$unit];
            $day = self::floorDiv($wall, self::SECONDS_PER_DAY);
            $time = $wall - $day * self::SECONDS_PER_DAY;
            $moved = Temporal::civil($day) === null ? null : $day;
        } else {
            $moved = self::moved($day, $count, $unit);
        }

        if ($moved === null) {
            return Marker::Absent;
        }

        return self::calendar($moved).'T'.self::clock($time, $withSeconds).$suffix;
    }

    /** `age(dob)` — whole years from the date of birth to today's date on the clock, or Absent. */
    public static function age(mixed $dateOfBirth, ?string $now): int|Marker
    {
        return $now === null ? Marker::Absent : self::diff($dateOfBirth, substr($now, 0, 10), 'years');
    }

    /** Whole calendar months from one key to another — a month counts once its day, then its time, comes round again. */
    private static function completeMonths(int $from, int $to): int
    {
        $fromDay = self::floorDiv($from, self::SECONDS_PER_DAY);
        $toDay = self::floorDiv($to, self::SECONDS_PER_DAY);
        [$fy, $fm, $fd] = Temporal::civil($fromDay) ?? [0, 0, 0];
        [$ty, $tm, $td] = Temporal::civil($toDay) ?? [0, 0, 0];
        $fromRest = [$fd, $from - $fromDay * self::SECONDS_PER_DAY];
        $toRest = [$td, $to - $toDay * self::SECONDS_PER_DAY];

        $months = ($ty - $fy) * 12 + ($tm - $fm);

        if ($months > 0 && $toRest < $fromRest) {
            $months--;
        } elseif ($months < 0 && $toRest > $fromRest) {
            $months++;
        }

        return $months;
    }

    /** A day number moved by whole days, weeks, months or years (a month end clamps), or null outside 0001-9999. */
    private static function moved(int $day, int $count, string $unit): ?int
    {
        if ($unit === 'days' || $unit === 'weeks') {
            $moved = $day + $count * ($unit === 'weeks' ? 7 : 1);

            return Temporal::civil($moved) === null ? null : $moved;
        }

        [$year, $month, $date] = Temporal::civil($day) ?? [0, 0, 0];
        $index = $year * 12 + ($month - 1) + $count * ($unit === 'years' ? 12 : 1);
        $newYear = self::floorDiv($index, 12);
        $newMonth = $index - $newYear * 12 + 1;

        if ($newYear < 1 || $newYear > 9999) {
            return null;
        }

        return Temporal::days($newYear, $newMonth, min($date, Temporal::daysInMonth($newYear, $newMonth)));
    }

    private static function calendar(int $day): string
    {
        [$year, $month, $date] = Temporal::civil($day) ?? [0, 0, 0];

        return sprintf('%04d-%02d-%02d', $year, $month, $date);
    }

    private static function clock(int $seconds, bool $withSeconds): string
    {
        $text = sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));

        return $withSeconds ? $text.sprintf(':%02d', $seconds % 60) : $text;
    }

    private static function floorDiv(int $a, int $b): int
    {
        return intdiv($a, $b) - (($a % $b !== 0 && ($a < 0) !== ($b < 0)) ? 1 : 0);
    }

    private static function floorMod(int $a, int $b): int
    {
        return $a - self::floorDiv($a, $b) * $b;
    }
}
