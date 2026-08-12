// Pure shaping for the Google Sheets column map (H16b). Kept out of the component for the reason
// `channel-options.ts` gives: the rules that decide what a tenant can bind are worth testing without
// mounting anything — and unlike the channel picker, getting these wrong files real answers under the wrong
// heading rather than merely showing the wrong label.

import type { MappableColumn, MappingColumn, Option, SheetMapping } from './types';

/** The option value meaning "write nothing into this column". Never a real field key — those never start `–`. */
export const UNBOUND = '';

/** What the select shows for a column nobody has bound yet. */
export const UNBOUND_LABEL = 'Leave empty';

/**
 * One row of the editor: a spreadsheet column, and the field key (if any) that fills it.
 *
 * `header` is the RAW text from the sheet, never the normalized form the fingerprint is built from. A UI that
 * echoed the normalized version would tell the tenant their sheet says something it does not, and the
 * mismatch is invisible until they go looking for a column that appears not to exist.
 */
export type MappingRow = {
    /** 0-based position in the sheet's header row. The mapping is POSITIONAL — see `toPayload`. */
    index: number;
    header: string;
    fieldKey: string | null;
};

/**
 * Build the editor's rows from a sheet's header row, pre-binding anything an existing mapping already had.
 *
 * ⚠️ MATCHED BY POSITION, NOT BY HEADER TEXT. Both consumers of this engine address columns positionally, and
 * `ColumnFingerprint` treats order as part of the identity precisely because a reordered sheet is a DIFFERENT
 * table. Re-binding by name would quietly "helpfully" follow a moved column and put every answer after it one
 * cell out — the failure `ocr-pipeline-design.md` §84 names, arriving through the front door.
 */
export function buildRows(headerRow: string[], existing?: SheetMapping | null): MappingRow[] {
    return headerRow.map((header, index) => ({
        index,
        header,
        fieldKey: existing?.columns[index]?.field_key ?? null,
    }));
}

/**
 * How a column with no name should read.
 *
 * A blank cell in row 1 is a real, addressable column — dropping it would shift every column after it — so it
 * is rendered rather than skipped, and named by its spreadsheet position so the tenant can find it. `A`, `B`,
 * `AA`: the same address their own formula bar shows, which "Column 27" would not be.
 */
export function columnLabel(row: MappingRow): string {
    return row.header.trim() !== '' ? row.header : `Column ${columnLetter(row.index)} (no heading)`;
}

/** 0 → A, 25 → Z, 26 → AA. Spreadsheet column addressing is bijective base-26, not plain base-26. */
export function columnLetter(index: number): string {
    let n = index + 1;
    let out = '';

    while (n > 0) {
        const remainder = (n - 1) % 26;
        out = String.fromCharCode(65 + remainder) + out;
        n = Math.floor((n - 1) / 26);
    }

    return out;
}

/**
 * The options for one column's field select, grouped, with the already-taken keys disabled.
 *
 * ⚠️ ONE FIELD KEY CANNOT FILL TWO COLUMNS, AND THE SERVER AGREES — `ColumnMapping::author()` throws on a
 * duplicate binding. Enforcing it here as DISABLED options rather than letting the save fail is the whole
 * difference between a rule that explains itself and a 500: the parser's exception is an
 * `InvalidArgumentException`, which is not a 422 naming a field.
 *
 * The key bound to THIS row stays enabled, or a column could never keep the value it already has.
 */
export function fieldOptionsFor(
    row: MappingRow,
    catalog: MappableColumn[],
    rows: MappingRow[],
): { groups: { label: string; options: (Option & { disabled?: boolean })[] }[] } {
    const takenElsewhere = new Set(
        rows.filter((r) => r.index !== row.index && r.fieldKey).map((r) => r.fieldKey as string),
    );

    const groups = new Map<string, (Option & { disabled?: boolean })[]>();

    for (const column of catalog) {
        const list = groups.get(column.group) ?? [];
        list.push({
            value: column.key,
            label: column.label,
            disabled: takenElsewhere.has(column.key),
        });
        groups.set(column.group, list);
    }

    return { groups: [...groups].map(([label, options]) => ({ label, options })) };
}

/**
 * The `config.mapping` payload the server validates, or null when there is nothing to send.
 *
 * ⚠️ NO FINGERPRINT IS COMPUTED HERE, DELIBERATELY. `ColumnFingerprint` normalizes each header before
 * digesting it, and a second implementation of that normalization in TypeScript is a second thing to keep in
 * step with a digest the delivery path compares byte for byte — the `BrandRampGenerator` parity problem, with
 * no test that could notice the drift because both sides would be reading the same client-computed value. The
 * SERVER stamps it: `inspect`/`create` return the header row, and the rule save re-derives the fingerprint
 * from it through `ColumnMapping::author()`.
 *
 * Every column is emitted, including unbound ones. `MappedColumn` models an unbound column explicitly and
 * `project()` writes `''` for it; omitting it would shorten the row and shift everything after it.
 */
export function toPayload(rows: MappingRow[]): MappingColumn[] {
    return rows.map((row) => ({
        header: row.header,
        field_key: row.fieldKey === UNBOUND ? null : row.fieldKey,
    }));
}

/**
 * Why the mapping cannot be saved yet, or null when it can.
 *
 * Deliberately NOT "every column must be bound": a spreadsheet legitimately carries columns Meridian does not
 * fill — a reviewer's notes, a formula, a column the tenant keeps for themselves — and `MappedColumn`'s
 * unbound state exists to model exactly that. The only real floor is that the rule writes SOMETHING, because
 * a mapping with no bindings appends a row of blanks per submission forever.
 */
export function mappingProblem(rows: MappingRow[]): string | null {
    if (rows.length === 0) {
        return 'We couldn’t read any columns from that sheet. Check the tab has a heading row.';
    }

    if (!rows.some((row) => row.fieldKey)) {
        return 'Point at least one column at a form field, or every row we add will be blank.';
    }

    return null;
}

/** A one-line summary of the binding count, for the editor's status line. */
export function mappingSummary(rows: MappingRow[]): string {
    const bound = rows.filter((row) => row.fieldKey).length;
    const columns = rows.length === 1 ? '1 column' : `${rows.length} columns`;

    return `${columns}, ${bound} filled by Meridian`;
}

/**
 * The default title for a spreadsheet we are about to create on the tenant's behalf.
 *
 * Named for the form rather than for us: it lands in THEIR Drive beside their own documents, where
 * "Meridian export 3" is noise. Falls back only when the rule is not scoped to one form.
 */
export function suggestedTitle(formTitle: string | null): string {
    return formTitle ? `${formTitle} — responses` : 'Meridian form responses';
}
