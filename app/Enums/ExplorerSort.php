<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The order of a data-explorer chart's groups (ADR-0025 §D4). A chart grouped by a date ignores it: a
 * timeline is always in date order.
 */
enum ExplorerSort: string
{
    case ValueDescending = 'value_desc';
    case ValueAscending = 'value_asc';
    case Label = 'label';

    public function label(): string
    {
        return match ($this) {
            self::ValueDescending => 'Largest first',
            self::ValueAscending => 'Smallest first',
            self::Label => 'By name',
        };
    }
}
