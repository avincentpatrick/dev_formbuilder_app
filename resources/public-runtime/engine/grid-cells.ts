/**
 * M246 (`grid-question-table`, `R-ffd10da2`) — how a grid cell reference reads a grid's answer; the mirror of
 * `app/Services/Expressions/GridCells.php`. A Grid's answer is `{row: {col: cell}}` and a Likert grid's
 * `{row: score}`. A path that is not there, at any step, is ABSENT — never a throw and never the whole row:
 *  - a scalar or null is returned as it is;
 *  - a list of strings (a multiple-choice column) is returned as the list;
 *  - anything else (an object: a path that stopped at a Grid's row) is ABSENT.
 *
 * Every step reads OWN properties only: a row named `constructor` or `__proto__` must read ABSENT here exactly as
 * PHP's `array_key_exists` reads it, never `Object.prototype`'s member.
 */

import { ABSENT, type EngineValue, type MaybeAbsent } from './coercion';

const hasOwn = (value: object, key: string): boolean => Object.prototype.hasOwnProperty.call(value, key);

const isIndexable = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null;

function leaf(value: unknown): MaybeAbsent {
    if (value === null || typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        return value;
    }

    if (Array.isArray(value) && value.every((item) => typeof item === 'string')) {
        return value as EngineValue;
    }

    return ABSENT;
}

/** `${key.row.col}` / `${key.row}` against the grid's answer as it sits in the context. */
export function resolveCell(grid: unknown, row: string, column: string | null): MaybeAbsent {
    if (!isIndexable(grid) || !hasOwn(grid, row)) {
        return ABSENT;
    }

    let value: unknown = grid[row];

    if (column !== null) {
        if (!isIndexable(value) || !hasOwn(value, column)) {
            return ABSENT;
        }
        value = (value as Record<string, unknown>)[column];
    }

    return leaf(value);
}

/** `${.col}` — a cell of the current row. */
export function resolveRowCell(row: Record<string, unknown> | null, column: string): MaybeAbsent {
    if (row === null || !hasOwn(row, column)) {
        return ABSENT;
    }

    return leaf(row[column]);
}
