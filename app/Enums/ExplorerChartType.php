<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a data-explorer chart is drawn (ADR-0025 §D7).
 *
 * The chart type is part of the DECLARATION, not a display preference kept beside it: each one admits a
 * different number of group-bys and values ({@see self::maxDimensions()}, {@see self::maxMeasures()}), so a
 * definition that reads as a valid donut cannot be stored and then fail to draw.
 */
enum ExplorerChartType: string
{
    case Bar = 'bar';
    case Column = 'column';
    case Stacked = 'stacked';
    case Line = 'line';
    case Area = 'area';
    case Donut = 'donut';
    case Table = 'table';
    case Number = 'number';

    public function label(): string
    {
        return match ($this) {
            self::Bar => 'Bars',
            self::Column => 'Columns',
            self::Stacked => 'Stacked columns',
            self::Line => 'Line',
            self::Area => 'Area',
            self::Donut => 'Donut',
            self::Table => 'Table',
            self::Number => 'Number',
        };
    }

    public function minDimensions(): int
    {
        return match ($this) {
            self::Table, self::Number => 0,
            default => 1,
        };
    }

    public function maxDimensions(): int
    {
        return match ($this) {
            self::Number => 0,
            self::Bar, self::Donut => 1,
            default => 2,
        };
    }

    /** With two group-bys the second one supplies the series, so only one value can be plotted. */
    public function maxMeasures(int $dimensions): int
    {
        return match ($this) {
            self::Bar, self::Donut => 1,
            self::Table, self::Number => 3,
            default => $dimensions === 2 ? 1 : 3,
        };
    }
}
