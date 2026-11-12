<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a data-explorer chart groups a date (ADR-0025 §D4).
 *
 * The first five are calendar periods and keep the timeline's order. The last two fold every period onto
 * one cycle — "which weekday", "which hour" — and so have a fixed, small set of groups.
 */
enum ExplorerBucket: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
    case Weekday = 'weekday';
    case Hour = 'hour';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
            self::Quarter => 'Quarter',
            self::Year => 'Year',
            self::Weekday => 'Day of the week',
            self::Hour => 'Hour of the day',
        };
    }

    /** A calendar period, as opposed to a position in a repeating cycle. */
    public function isCalendar(): bool
    {
        return $this !== self::Weekday && $this !== self::Hour;
    }
}
