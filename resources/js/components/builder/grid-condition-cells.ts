/**
 * M246 (`grid-question-table`, `R-ffd10da2`) — the grid cells a condition, a calculation or "Insert a question" may name,
 * as entries beside the questions in the condition editor's catalogue. A cell is a pseudo-question whose key is its whole
 * path — `grid.row.col`, or `grid.row` for a Likert row — so the editor's existing pickers, describer and printer carry
 * it whole (`condition-model.ts`'s KEY_TEXT reads the path).
 *
 * WHICH CELLS (`D230`): a rule elsewhere may read only a cell that is ALWAYS ASKED — its row has no show-when, its column
 * has no show-when and no needed-when, its row does not mark it "Not needed", and the grid's rows are its own (not copied
 * in from a list or another form at publish). The publish gate refuses the rest by name; the editor never offers them.
 * A row or column whose value cannot be named (`[A-Za-z0-9_][A-Za-z0-9_-]*`) is not offered either.
 *
 * Inside a Grid column's own rules, {@link rowCellEntries} adds "This row's …" for each column to its left — `.col`.
 */

import type { ConditionFieldOption, PaletteType } from './types';

/** One grid path segment, exactly as the lexer reads it. */
export const GRID_SEGMENT = /^[A-Za-z0-9_][A-Za-z0-9_-]*$/;

type Entry = Record<string, unknown>;
type GridField = { key: string; label: string; field_type: string; config: Record<string, unknown> | null | undefined };

const CHOICE_TYPES = new Set(['single_select', 'multi_select']);
const NUMBER_TYPES = new Set(['integer', 'decimal']);

const entries = (list: unknown): Entry[] =>
    Array.isArray(list) ? list.filter((entry): entry is Entry => typeof entry === 'object' && entry !== null && !Array.isArray(entry)) : [];
const valueOf = (entry: Entry): string => (entry.value === undefined || entry.value === null ? '' : String(entry.value));
const labelOf = (entry: Entry): string => (typeof entry.label === 'string' && entry.label.trim() !== '' ? entry.label.trim() : valueOf(entry));
const blank = (text: unknown): boolean => typeof text !== 'string' || text.trim() === '';
const choices = (list: unknown): { value: string; label: string }[] => entries(list).map((option) => ({ value: valueOf(option), label: labelOf(option) }));

/** The column's type as a grid reads it — none, or one a grid cannot hold, is a single choice. */
function columnType(column: Entry): string {
    return typeof column.type === 'string' && column.type !== '' ? column.type : 'single_select';
}

function kindOf(palette: PaletteType[], type: string): string | undefined {
    return palette.find((t) => t.value === type)?.operand_kind;
}

function cellOption(palette: PaletteType[], config: Entry, column: Entry, key: string, label: string): ConditionFieldOption {
    const type = columnType(column);
    const own = choices(column.options);

    return {
        key,
        label,
        numeric: NUMBER_TYPES.has(type),
        operand_kind: kindOf(palette, type),
        options: CHOICE_TYPES.has(type) ? (own.length > 0 ? own : choices(config.cells)) : [],
    };
}

/** Whether the grid's rows are copied in from a list or another form at publish — then no row of it can be named. */
function rowsSourced(config: Entry): boolean {
    return (config.rows_list ?? null) !== null || (config.rows_source ?? null) !== null;
}

/**
 * Every always-asked cell of every grid but `ownKey`, as condition-catalogue entries labelled "Grid — Row — Column", in
 * the form's order.
 */
export function gridCellEntries(fields: GridField[], palette: PaletteType[], ownKey: string | null, anyCell = false): ConditionFieldOption[] {
    const out: ConditionFieldOption[] = [];

    for (const field of fields) {
        if (field.key === '' || field.key === ownKey || (field.field_type !== 'matrix' && field.field_type !== 'likert_matrix')) continue;
        const config = (field.config ?? {}) as Entry;
        if (rowsSourced(config)) continue;
        const gridLabel = field.label.trim() === '' ? field.key : field.label.trim();
        const columns = entries(config.columns);

        for (const row of entries(config.rows)) {
            const rowValue = valueOf(row);
            // `anyCell`: piped text may show any cell — it never feeds back into what is asked (`D230`).
            if (!GRID_SEGMENT.test(rowValue) || (!anyCell && !blank(row.relevant))) continue;

            if (field.field_type === 'likert_matrix') {
                out.push({
                    key: `${field.key}.${rowValue}`,
                    label: `${gridLabel} — ${labelOf(row)}`,
                    numeric: true,
                    operand_kind: kindOf(palette, 'likert_scale'),
                    options: columns.map((column) => ({ value: valueOf(column), label: labelOf(column) })),
                });
                continue;
            }

            const notNeeded = new Set(Array.isArray(row.not_needed) ? row.not_needed.map(String) : []);
            for (const column of columns) {
                const columnValue = valueOf(column);
                if (!GRID_SEGMENT.test(columnValue)) continue;
                if (!anyCell && (!blank(column.relevant) || !blank(column.needed_when) || notNeeded.has(columnValue))) continue;
                out.push(cellOption(palette, config, column, `${field.key}.${rowValue}.${columnValue}`, `${gridLabel} — ${labelOf(row)} — ${labelOf(column)}`));
            }
        }
    }

    return out;
}

/**
 * "This row's …" — `.col` — for each column of a Grid to the LEFT of `columnIndex`, the only cells a column's
 * needed-when and required-when may read of its own row (a column to the right is not decided yet).
 */
export function rowCellEntries(config: Record<string, unknown> | null | undefined, columnIndex: number, palette: PaletteType[]): ConditionFieldOption[] {
    const grid = (config ?? {}) as Entry;

    return entries(grid.columns)
        .slice(0, Math.max(0, columnIndex))
        .filter((column) => GRID_SEGMENT.test(valueOf(column)))
        .map((column) => cellOption(palette, grid, column, `.${valueOf(column)}`, `This row’s ${labelOf(column)}`));
}

/**
 * Every place a grid rule elsewhere names `${grid.row…}` — a renamed or removed row or column of this grid would leave
 * it pointing at nothing, and publish would refuse it. Returned as the plain names of what names it, for the "still
 * named by …" warning.
 */
export function cellNamesUsed(gridKey: string, texts: { owner: string; text: string | null | undefined }[]): Map<string, string[]> {
    const used = new Map<string, string[]>();
    const pattern = new RegExp(`\\$\\{${gridKey.replace(/[$()*+.?[\\\]^{|}]/g, '\\$&')}\\.([A-Za-z0-9_][A-Za-z0-9_-]*)(?:\\.([A-Za-z0-9_][A-Za-z0-9_-]*))?\\}`, 'g');

    for (const { owner, text } of texts) {
        if (typeof text !== 'string' || !text.includes('${')) continue;
        for (const match of text.matchAll(pattern)) {
            for (const segment of [match[1], match[2]]) {
                if (segment === undefined) continue;
                const owners = used.get(segment) ?? [];
                if (!owners.includes(owner)) owners.push(owner);
                used.set(segment, owners);
            }
        }
    }

    return used;
}
