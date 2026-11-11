<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of column a data-explorer field is, which decides what a chart may do with it (ADR-0025 §D4).
 */
enum ExplorerFieldType: string
{
    /** A value from a short list — a status, a form, a chosen option. Grouped and filtered, never summed. */
    case Category = 'category';

    /** A yes/no. A category with exactly two values. */
    case Boolean = 'boolean';

    /** A question that takes several options at once. Grouping by it counts a row once per chosen option. */
    case MultiCategory = 'multi_category';

    /** A number. Summed, averaged and compared; not grouped. */
    case Number = 'number';

    /** A moment, stored in UTC and grouped in the reader's time zone. */
    case Time = 'time';

    /** A calendar date an answer holds, with no time and so no time zone. */
    case Date = 'date';

    public function isGroupable(): bool
    {
        return $this !== self::Number;
    }

    public function isTemporal(): bool
    {
        return $this === self::Time || $this === self::Date;
    }
}
