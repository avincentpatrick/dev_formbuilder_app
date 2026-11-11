<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a data-explorer value computes over the rows of a group (ADR-0025 §D4).
 */
enum ExplorerAggregate: string
{
    case Count = 'count';
    case CountDistinct = 'count_distinct';
    case Sum = 'sum';
    case Average = 'avg';
    case Minimum = 'min';
    case Maximum = 'max';
    case Median = 'median';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Count',
            self::CountDistinct => 'Distinct count',
            self::Sum => 'Sum',
            self::Average => 'Average',
            self::Minimum => 'Minimum',
            self::Maximum => 'Maximum',
            self::Median => 'Median',
        };
    }

    /** `count` is the only value that needs no field: it counts rows. */
    public function needsField(): bool
    {
        return $this !== self::Count;
    }

    /**
     * Whether the values of several groups can be added into one.
     *
     * This decides what an "Other" bucket may say. Fifty averages cannot be summed into the average of the
     * rest, so for these the bucket carries the number of groups it stands for and no value — a stated gap,
     * never a figure computed from whichever rows happened to be to hand.
     */
    public function isAdditive(): bool
    {
        return $this === self::Count || $this === self::Sum;
    }
}
