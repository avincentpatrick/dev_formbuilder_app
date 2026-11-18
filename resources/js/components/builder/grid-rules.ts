/**
 * M246 (`grid-question-table`) — where a grid holds expressions of its own; the builder's mirror of
 * `app/Support/Forms/GridRules.php`. Every builder reader that walks a question's conditions (the logic rail, the
 * conversion dialog, the reference pickers) reads a grid's rules through {@link gridRuleSites}, so a grid rule is
 * never the one condition a walk forgot.
 *
 *  - `rows[].relevant` — show this row only when… (Grid and Likert);
 *  - `columns[].relevant` — show this column only when… (Grid; reads other questions, never `${.col}`);
 *  - `columns[].needed_when` — in each row, this cell is needed only when… (Grid; may read `${.col}` to its left);
 *  - `columns[].required_when` — in each row, this cell is required when… (Grid; likewise).
 */

export type GridRuleKind = 'row' | 'column' | 'needed' | 'required';

export interface GridRuleSite {
    expression: string;
    rule: GridRuleKind;
    /** The row's value for a row rule, else null. */
    row: string | null;
    /** The column's value for a column rule, else null. */
    column: string | null;
    /** Whether `${.col}` may be used — a column's per-row rules only. */
    rowScope: boolean;
}

export const GRID_COLUMN_RULE_KEYS = { column: 'relevant', needed: 'needed_when', required: 'required_when' } as const;

type Entry = Record<string, unknown>;

const entries = (list: unknown): Entry[] =>
    Array.isArray(list) ? list.filter((entry): entry is Entry => typeof entry === 'object' && entry !== null && !Array.isArray(entry)) : [];

const valueOf = (entry: Entry): string | null =>
    typeof entry.value === 'string' || typeof entry.value === 'number' ? String(entry.value) : null;

const expressionOf = (text: unknown): string | null => (typeof text === 'string' && text.trim() !== '' ? text : null);

const isGrid = (fieldType: string): boolean => fieldType === 'matrix' || fieldType === 'likert_matrix';

/** Every non-blank rule a grid's config holds, rows first, then columns — the mirror of `GridRules::sites()`. */
export function gridRuleSites(fieldType: string, config: unknown): GridRuleSite[] {
    if (!isGrid(fieldType) || typeof config !== 'object' || config === null) return [];
    const record = config as Entry;
    const sites: GridRuleSite[] = [];

    for (const row of entries(record.rows)) {
        const expression = expressionOf(row.relevant);
        if (expression !== null) sites.push({ expression, rule: 'row', row: valueOf(row), column: null, rowScope: false });
    }

    if (fieldType !== 'matrix') return sites;

    for (const column of entries(record.columns)) {
        for (const rule of ['column', 'needed', 'required'] as const) {
            const expression = expressionOf(column[GRID_COLUMN_RULE_KEYS[rule]]);
            if (expression !== null) sites.push({ expression, rule, row: null, column: valueOf(column), rowScope: rule !== 'column' });
        }
    }

    return sites;
}

/** Whether a grid's config holds any rule at all — a row or column condition, or a "Not needed" cell. */
export function gridHasRules(fieldType: string, config: unknown): boolean {
    if (gridRuleSites(fieldType, config).length > 0) return true;
    if (fieldType !== 'matrix' || typeof config !== 'object' || config === null) return false;

    return entries((config as Entry).rows).some((row) => Array.isArray(row.not_needed) && row.not_needed.length > 0);
}
