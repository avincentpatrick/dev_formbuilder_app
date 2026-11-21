<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * M246 (`grid-question-table`, `R-ffd10da2`) — how a grid cell reference reads a grid's answer, in one place for the
 * evaluator, the semantic validator and piped text. Mirrored by `resources/public-runtime/engine/grid-cells.ts`.
 *
 * A Grid's answer is `{row: {col: cell}}` and a Likert grid's `{row: score}`. A path that is not there, at any step,
 * is {@see Marker::Absent} — never an exception and never the whole row. What a reference may land on:
 *  - a scalar (text, a choice, a number, a date) or null — returned as it is;
 *  - a list of strings (a multiple-choice column) — returned as the list, as a multi_select field's value is;
 *  - anything else (an object: a path that stopped at a Grid's row) — Absent.
 *
 * PHP cannot tell `{"0": …, "1": …}` from `[…, …]` after json_decode — a grid whose rows are named 0 and 1 arrives as
 * a list — so a list is read here as the map of its indexes, which is what it is to PHP, and TS reads an array the same
 * way (a canonical index is an entry; "01" and an array's own `length` are not). The golden vectors in `cells.json`
 * hold the two engines to it.
 */
final class GridCells
{
    /**
     * @param  mixed  $grid  the grid's answer as it sits in the context
     */
    public static function resolve(mixed $grid, string $row, ?string $column): mixed
    {
        if (! is_array($grid) || ! array_key_exists($row, $grid)) {
            return Marker::Absent;
        }

        $value = $grid[$row];

        if ($column !== null) {
            if (! is_array($value) || ! array_key_exists($column, $value)) {
                return Marker::Absent;
            }
            $value = $value[$column];
        }

        return self::leaf($value);
    }

    /**
     * `${.col}` — a cell of the current row.
     *
     * @param  array<array-key, mixed>|null  $row
     */
    public static function rowCell(?array $row, string $column): mixed
    {
        if ($row === null || ! array_key_exists($column, $row)) {
            return Marker::Absent;
        }

        return self::leaf($row[$column]);
    }

    private static function leaf(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            return Marker::Absent;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return Marker::Absent;
            }
        }

        return $value;
    }
}
