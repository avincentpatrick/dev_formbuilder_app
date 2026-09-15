// Pure shaping for the Google Sheets column map (H16b). Kept out of the component for the reason
// `channel-options.ts` gives: the rules that decide what a tenant can bind are worth testing without
// mounting anything — and unlike the channel picker, getting these wrong files real answers under the wrong
// heading rather than merely showing the wrong label.

import type { MappableColumn, MappingColumn, Option, SheetMapping, TabularDestination } from './types';

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
 * ⚠️ ONE FIELD KEY CANNOT FILL TWO COLUMNS. Since M96 the tenant rule requests refuse it on save, under
 * `config.mapping.columns` (`SubscriptionConfigRules::duplicateBindingGuard()`); before that nothing on the save
 * path did. Enforcing it here as DISABLED options is still what keeps the tenant from ever meeting that refusal:
 * a form that stops the choice explains itself, where a refused save only reports it.
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
 * SERVER stamps it: the tenant rule requests derive it from these posted headers on every save
 * (`SubscriptionConfigRules::withStampedFingerprint()`, M96). Until M96 nothing did, so every tabular save from
 * the editor was refused on a key neither editor renders.
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

// ── M96: Submission ID, and re-opening a saved rule ───────────────────────────────────────────────────────

/**
 * The catalog key for the submission's own id — `SubmissionRowProjector::META_SUBMISSION_ID`.
 *
 * It is what the delivery adapters search a destination for before they write, so a delivery retried after a
 * network error can find the row it already added. A rule with no column bound to it gives them nothing to search
 * for, and that retry can add the same row twice.
 */
export const IDENTITY_KEY = '__submission_id';

/** The help under whichever column Submission ID fills. */
export const IDENTITY_ROW_HELP = 'Lets us spot a row we already added, so a retried delivery does not add it twice.';

/**
 * Airtable field types that may change or refuse a submission id.
 *
 * ⚠️ A DENYLIST, AND THE CONSEQUENCE IS NOT MEASURED. Airtable writes are sent with `typecast: true`, and what that
 * does to a uuid in one of these fields — coerce it, or refuse the write — has not been measured against the live
 * API. The warning therefore claims only "may change or refuse". An allowlist of text types would also warn on
 * types nobody has shown to be a problem.
 */
export const IDENTITY_TYPE_DENYLIST: readonly string[] = [
    'number',
    'currency',
    'percent',
    'date',
    'dateTime',
    'checkbox',
    'rating',
    'duration',
];

const IDENTITY_HEADING = 'submission id';

/** Which editor is asking: a Google sheet has columns and rows, an Airtable table has fields and records. */
export type IdentityKind = 'sheet' | 'table';

/**
 * A heading as the Submission ID match reads it: inner whitespace collapsed, trimmed, lower-cased.
 *
 * A UI match ONLY, and deliberately not `ColumnFingerprint::normalize()`. Nothing is digested from it, so it can
 * never disagree with the server's fingerprint: it decides which column to suggest, and the tenant sees that
 * suggestion before anything is saved.
 */
export function identityHeading(header: string): string {
    return header.replace(/\s+/g, ' ').trim().toLowerCase();
}

/**
 * Bind Submission ID to the first free column headed "Submission ID", when no column holds it yet.
 *
 * ⛔ NEVER ONTO ANY OTHER HEADING. An existing sheet's heading row belongs to the tenant, and Airtable fields
 * cannot be created from here, so binding the id onto a column headed anything else would write ids over whatever
 * the tenant keeps in it. When no heading matches, `identityHint()` says how to add one instead.
 *
 * Run on every map built from a PICK — a base, a table, a tab, Check — and on a restore whose columns changed.
 * Never on a restore whose columns are unchanged: that rule is shown exactly as it was saved, unbound id included.
 */
export function preBindIdentity(rows: MappingRow[]): MappingRow[] {
    if (rows.some((row) => row.fieldKey === IDENTITY_KEY)) {
        return rows;
    }

    const target = rows.find((row) => !row.fieldKey && identityHeading(row.header) === IDENTITY_HEADING);

    return target ? rows.map((row) => (row.index === target.index ? { ...row, fieldKey: IDENTITY_KEY } : row)) : rows;
}

/**
 * Why Submission ID is not doing its job on this map, or null when it is.
 *
 * Rendered as a plain paragraph, never a live region: each editor already owns one status line, and a second
 * `aria-live` region on the same form competes with it.
 *
 * @param headerTypes Airtable's field type for each column, index-aligned with the header row. Null for a sheet.
 * @param existingRule The rule is already saved. Adding a column changes the heading row, so that rule pauses with
 *   a column-drift reason on its next delivery until it is saved again, and the copy says so.
 */
export function identityHint(
    rows: MappingRow[],
    kind: IdentityKind,
    headerTypes: (string | null)[] | null = null,
    existingRule = false,
): string | null {
    if (rows.length === 0) {
        return null;
    }

    const noun = kind === 'sheet' ? 'row' : 'record';
    const retry = `Without it, a delivery retried after a network error can add the same ${noun} twice.`;
    const bound = rows.find((row) => row.fieldKey === IDENTITY_KEY);

    if (bound) {
        const type = headerTypes?.[bound.index] ?? null;

        return type !== null && IDENTITY_TYPE_DENYLIST.includes(type)
            ? 'Airtable may change or refuse the submission id in a field of this type, so a retried delivery may not find the record it already added. Use a single line text field.'
            : null;
    }

    // The column is there and simply not bound — telling the tenant to add one would send them looking for nothing.
    if (rows.some((row) => identityHeading(row.header) === IDENTITY_HEADING)) {
        return `Choose Submission ID for the “Submission ID” ${kind === 'sheet' ? 'column' : 'field'}. ${retry}`;
    }

    const add =
        kind === 'sheet'
            ? `Add a column headed “Submission ID” at the end of this sheet, then press Check again. ${retry}`
            : `Add a single line text field called “Submission ID” to this table in Airtable, then press Check again. ${retry}`;

    return existingRule ? `${add} The rule pauses until you save it with the new column.` : add;
}

/**
 * Rows for a heading row that has CHANGED, carrying each stored binding to the column with the same heading.
 *
 * ⚠️ WHY THIS IS NOT `buildRows`. That function matches by position, which is right while the columns are
 * unchanged. Once the fingerprint says they changed, position is known to be wrong — an inserted column would put
 * every answer after it one column out — so bindings follow their heading here instead, and the editor says the
 * columns changed and asks for a review before anything is saved.
 *
 * A column with no heading is never matched, because there is no text to match it by, and each stored binding is
 * used once, on the first free column with the same heading.
 */
export function rebindByHeading(headerRow: string[], columns: MappingColumn[]): MappingRow[] {
    const stored = columns
        .filter((column) => column.field_key && identityHeading(column.header) !== '')
        .map((column) => ({ heading: identityHeading(column.header), fieldKey: column.field_key as string }));
    const used = new Set<string>();

    return headerRow.map((header, index) => {
        const heading = identityHeading(header);
        const match = heading === '' ? undefined : stored.find((c) => c.heading === heading && !used.has(c.fieldKey));

        if (match) {
            used.add(match.fieldKey);
        }

        return { index, header, fieldKey: match?.fieldKey ?? null };
    });
}

/**
 * The rows for re-opening a saved rule against its destination's CURRENT heading row.
 *
 * The stored bindings are carried by position only when the server's fingerprint of the current row equals the one
 * stored on the rule — plain string equality, so no TypeScript copy of the digest exists. Otherwise the columns
 * changed since the rule was saved (`drifted`), and the rows are rebuilt by heading and pre-bound for review.
 *
 * ⛔ WITHOUT THIS CHECK, SAVING A DRIFTED RULE WOULD HIDE THE DRIFT FOR GOOD. The save stamps a fingerprint from the
 * current headings, so a mapping carried over by position would match the live sheet, pass drift detection, and
 * deliver every answer after the change one column out.
 */
export function restoreRows(
    destination: Pick<TabularDestination, 'header_row' | 'fingerprint'>,
    mapping: SheetMapping | null | undefined,
): { rows: MappingRow[]; drifted: boolean } {
    if (!mapping) {
        return { rows: preBindIdentity(buildRows(destination.header_row)), drifted: false };
    }

    if (mapping.fingerprint === destination.fingerprint) {
        return { rows: buildRows(destination.header_row, mapping), drifted: false };
    }

    return { rows: preBindIdentity(rebindByHeading(destination.header_row, mapping.columns)), drifted: true };
}
