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

const ARRAY_INDEX = /^(0|[1-9][0-9]*)$/;

/**
 * Whether `key` is one of `value`'s own entries, read the way PHP reads a decoded answer. PHP's `json_decode` cannot
 * tell a list from an object keyed "0".."n-1" (a grid whose rows are named 0 and 1 arrives as a list), so an array
 * here is the object of its indexes: a canonical index is an entry, and nothing else is — never "01", and never the
 * array's own `length`, which `hasOwnProperty` would otherwise admit (found by M246's mutant of this walk).
 */
function hasEntry(value: unknown, key: string): value is Record<string, unknown> {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    if (Array.isArray(value)) {
        return ARRAY_INDEX.test(key) && Number(key) < value.length;
    }

    return hasOwn(value, key);
}

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
    if (!hasEntry(grid, row)) {
        return ABSENT;
    }

    let value: unknown = grid[row];

    if (column !== null) {
        if (!hasEntry(value, column)) {
            return ABSENT;
        }
        value = (value as Record<string, unknown>)[column];
    }

    return leaf(value);
}

/** `${.col}` — a cell of the current row. */
export function resolveRowCell(row: Record<string, unknown> | null, column: string): MaybeAbsent {
    if (!hasEntry(row, column)) {
        return ABSENT;
    }

    return leaf(row[column]);
}

/**
 * The types a Grid column may take — the mirror of PHP `MatrixColumns::TYPES`, held equal by
 * `MatrixColumnTypesMirrorDriftTest`. An unknown or missing type is a single choice, as PHP `MatrixColumns::type()` reads it.
 */
const GRID_COLUMN_TYPES = ['single_select', 'multi_select', 'short_text', 'long_text', 'integer', 'decimal', 'date'];

type GridEntry = Record<string, unknown>;

const gridEntries = (list: unknown): GridEntry[] =>
    Array.isArray(list) ? list.filter((entry): entry is GridEntry => typeof entry === 'object' && entry !== null && !Array.isArray(entry)) : [];

const entryValue = (entry: GridEntry): string => (entry.value === undefined || entry.value === null ? '' : String(entry.value));

/**
 * M246 (`R-ffd10da2`): what one grid cell is when it is shown on its own — piped into text — the mirror of PHP
 * `MatrixColumns::cellSource()`: a Grid's cell as a question of its column's type over its column's choices, a Likert
 * row as a Likert scale over the grid's columns. Null for a row or column the grid does not have, or a path of the
 * wrong length for the grid's kind.
 */
export function cellSource(gridType: string, config: Record<string, unknown>, row: string, column: string | null): { type: string; config: Record<string, unknown> } | null {
    if (!gridEntries(config.rows).some((entry) => entryValue(entry) === row)) {
        return null;
    }

    const columns = gridEntries(config.columns);
    if (gridType === 'likert_matrix') {
        return column === null ? { type: 'likert_scale', config: { options: columns } } : null;
    }

    if (gridType !== 'matrix' || column === null) {
        return null;
    }

    const match = columns.find((entry) => entryValue(entry) === column);
    if (match === undefined) {
        return null;
    }

    const type = typeof match.type === 'string' && GRID_COLUMN_TYPES.includes(match.type) ? match.type : 'single_select';
    if (type !== 'single_select' && type !== 'multi_select') {
        return { type, config: { options: [] } };
    }

    const own = gridEntries(match.options);
    const sourcesOwn = (match.options_list ?? null) !== null || (match.options_source ?? null) !== null;

    return { type, config: { options: own.length > 0 || sourcesOwn ? own : gridEntries(config.cells) } };
}
