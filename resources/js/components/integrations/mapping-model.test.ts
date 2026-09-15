import { describe, expect, it } from 'vitest';
import {
    buildRows,
    columnLabel,
    columnLetter,
    fieldOptionsFor,
    IDENTITY_KEY,
    identityHeading,
    identityHint,
    mappingProblem,
    mappingSummary,
    preBindIdentity,
    rebindByHeading,
    restoreRows,
    suggestedTitle,
    toPayload,
    type MappingRow,
} from './mapping-model';
import type { MappableColumn } from './types';

/**
 * The Sheets column map (H16b).
 *
 * Worth unit-testing rather than leaving to the component for a reason the channel picker did not have:
 * getting these rules wrong does not show the wrong label, it files real answers under the wrong heading —
 * silently, because a spreadsheet cannot tell you a column moved.
 */

const catalog: MappableColumn[] = [
    { key: 'full_name', label: 'Full name', group: 'Form fields' },
    { key: 'colour', label: 'Colour', group: 'Form fields' },
    { key: '__reference', label: 'Reference', group: 'Submission details' },
];

function rows(...pairs: [string, string | null][]): MappingRow[] {
    return pairs.map(([header, fieldKey], index) => ({ index, header, fieldKey }));
}

describe('buildRows', () => {
    it('re-binds an existing mapping BY POSITION, never by header text', () => {
        // ⚠️ THE LOAD-BEARING CASE. Both consumers of this engine address columns positionally and
        // ColumnFingerprint treats order as part of the identity, precisely because a reordered sheet is a
        // different table. Re-binding by name would "helpfully" follow a moved column and put every answer
        // after it one cell out — the failure ocr-pipeline-design.md:84 names, through the front door.
        const existing = {
            fingerprint: 'abc',
            columns: [
                { header: 'full name', field_key: 'full_name' },
                { header: 'colour', field_key: 'colour' },
            ],
        };

        // The tenant swapped the two columns in their sheet.
        const built = buildRows(['Colour', 'Full name'], existing);

        expect(built[0]).toEqual({ index: 0, header: 'Colour', fieldKey: 'full_name' });
        expect(built[1]).toEqual({ index: 1, header: 'Full name', fieldKey: 'colour' });
    });

    it('keeps the RAW header, not the normalized one the fingerprint stores', () => {
        // The stored header is normalized by ColumnMapping::author(). Echoing that back would show the tenant
        // a column name their sheet does not contain, and the mismatch is invisible until they go looking.
        const built = buildRows(['  Full Name  '], {
            fingerprint: 'abc',
            columns: [{ header: 'full name', field_key: 'full_name' }],
        });

        expect(built[0].header).toBe('  Full Name  ');
        expect(built[0].fieldKey).toBe('full_name');
    });

    it('leaves a column unbound when the stored mapping is shorter than the sheet', () => {
        const built = buildRows(['A', 'B', 'C'], { fingerprint: 'x', columns: [{ header: 'a', field_key: 'k' }] });

        expect(built.map((r) => r.fieldKey)).toEqual(['k', null, null]);
    });
});

describe('columnLabel / columnLetter', () => {
    it('addresses a blank heading the way the tenant’s own formula bar does', () => {
        // A blank cell in row 1 is a real, addressable column — dropping it would shift everything after it —
        // so it is named rather than skipped, and "Column AA" is findable where "Column 27" is not.
        expect(columnLabel(rows(['', null])[0])).toBe('Column A (no heading)');
        expect(columnLetter(0)).toBe('A');
        expect(columnLetter(25)).toBe('Z');
        // Bijective base-26, not plain base-26: getting this wrong yields "Column @" or an off-by-one at Z.
        expect(columnLetter(26)).toBe('AA');
        expect(columnLetter(51)).toBe('AZ');
        expect(columnLetter(52)).toBe('BA');
    });

    it('prefers the real heading whenever there is one', () => {
        expect(columnLabel(rows(['Full name', null])[0])).toBe('Full name');
    });
});

describe('fieldOptionsFor', () => {
    it('disables a key already bound to another column, because the server throws on a duplicate', () => {
        // ColumnMapping::author() raises InvalidArgumentException on a duplicate binding — a 500, not a 422
        // naming a field. Disabling here is the difference between a form that explains itself and one that
        // fails with a stack trace.
        const all = rows(['A', 'full_name'], ['B', null]);
        const { groups } = fieldOptionsFor(all[1], catalog, all);
        const formFields = groups.find((g) => g.label === 'Form fields');

        expect(formFields?.options.find((o) => o.value === 'full_name')?.disabled).toBe(true);
        expect(formFields?.options.find((o) => o.value === 'colour')?.disabled).toBe(false);
    });

    it('never disables the key THIS column already holds, or it could not keep its own value', () => {
        const all = rows(['A', 'full_name'], ['B', null]);
        const { groups } = fieldOptionsFor(all[0], catalog, all);

        expect(groups.find((g) => g.label === 'Form fields')?.options.find((o) => o.value === 'full_name')?.disabled)
            .toBe(false);
    });

    it('preserves the catalog’s groups so metadata and answers stay visibly apart', () => {
        const { groups } = fieldOptionsFor(rows(['A', null])[0], catalog, rows(['A', null]));

        expect(groups.map((g) => g.label)).toEqual(['Form fields', 'Submission details']);
    });
});

describe('toPayload', () => {
    it('emits EVERY column, including unbound ones', () => {
        // MappedColumn models an unbound column explicitly and project() writes '' for it. Omitting it would
        // shorten the row and shift every value after it one cell left.
        expect(toPayload(rows(['A', 'full_name'], ['B', null], ['C', 'colour']))).toEqual([
            { header: 'A', field_key: 'full_name' },
            { header: 'B', field_key: null },
            { header: 'C', field_key: 'colour' },
        ]);
    });

    it('sends no fingerprint — the server derives it', () => {
        // A TypeScript reimplementation of ColumnFingerprint's normalisation would be a second thing to keep
        // in step with a digest the delivery path compares byte for byte, and nothing could notice the drift
        // because both sides would read the same client-computed value.
        const payload = toPayload(rows(['A', 'full_name']));

        expect(payload.every((column) => !('fingerprint' in column))).toBe(true);
    });
});

describe('mappingProblem', () => {
    it('does NOT require every column to be bound', () => {
        // A sheet legitimately carries columns Meridian does not fill — a reviewer's notes, a formula, a
        // column the tenant keeps for themselves. That is what MappedColumn's unbound state is for.
        expect(mappingProblem(rows(['A', 'full_name'], ['B', null]))).toBeNull();
    });

    it('refuses a mapping that would write nothing at all', () => {
        expect(mappingProblem(rows(['A', null], ['B', null]))).toContain('at least one column');
    });

    it('refuses an empty sheet rather than saving a rule with no columns', () => {
        expect(mappingProblem([])).toContain('heading row');
    });
});

describe('mappingSummary / suggestedTitle', () => {
    it('counts columns and bindings separately, and singularises one column', () => {
        expect(mappingSummary(rows(['A', 'full_name'], ['B', null]))).toBe('2 columns, 1 filled by Meridian');
        expect(mappingSummary(rows(['A', null]))).toBe('1 column, 0 filled by Meridian');
    });

    it('names a created spreadsheet after the FORM, since it lands in the tenant’s own Drive', () => {
        expect(suggestedTitle('Clinic Intake')).toBe('Clinic Intake — responses');
        expect(suggestedTitle(null)).toBe('Meridian form responses');
    });
});

// ── M96: Submission ID, and opening a rule that was already saved ─────────────────────────────────────────

const SHEET_MISSING =
    'Add a column headed “Submission ID” at the end of this sheet, then press Check again. Without it, a delivery retried after a network error can add the same row twice.';
const TABLE_MISSING =
    'Add a single line text field called “Submission ID” to this table in Airtable, then press Check again. Without it, a delivery retried after a network error can add the same record twice.';
const PAUSES = 'The rule pauses until you save it with the new column.';
const TYPE_WARNING =
    'Airtable may change or refuse the submission id in a field of this type, so a retried delivery may not find the record it already added. Use a single line text field.';

describe('identityHeading / preBindIdentity (M96)', () => {
    it('reads a heading through stray spaces and capitals, and nothing broader', () => {
        // A UI match only. It is deliberately not ColumnFingerprint's normaliser, which the server owns.
        expect(identityHeading('  submission   ID ')).toBe('submission id');
        expect(identityHeading('Submission-ID')).toBe('submission-id');
    });

    it('binds the column headed Submission ID and leaves every other column alone', () => {
        const built = preBindIdentity(rows(['Full name', null], ['  submission   ID ', null], ['Notes', null]));

        expect(built.map((r) => r.fieldKey)).toEqual([null, IDENTITY_KEY, null]);
    });

    it('binds only the first of two matching headings', () => {
        const built = preBindIdentity(rows(['Submission ID', null], ['submission id', null]));

        expect(built.map((r) => r.fieldKey)).toEqual([IDENTITY_KEY, null]);
    });

    it('passes over a matching heading the tenant already pointed at another field', () => {
        const built = preBindIdentity(rows(['Submission ID', 'full_name'], ['submission id', null]));

        expect(built.map((r) => r.fieldKey)).toEqual(['full_name', IDENTITY_KEY]);
    });

    it('changes nothing when Submission ID is already bound somewhere', () => {
        const built = preBindIdentity(rows(['Ref', IDENTITY_KEY], ['Submission ID', null]));

        expect(built.map((r) => r.fieldKey)).toEqual([IDENTITY_KEY, null]);
    });

    it('never binds a column whose heading is not Submission ID', () => {
        // Binding it onto another heading would write ids over the tenant's own data in that column.
        const built = preBindIdentity(rows(['Full name', null], ['ID', null], ['', null]));

        expect(built.map((r) => r.fieldKey)).toEqual([null, null, null]);
    });
});

describe('identityHint (M96)', () => {
    it('says how to add the column when a sheet has none', () => {
        expect(identityHint(rows(['Full name', 'full_name']), 'sheet')).toBe(SHEET_MISSING);
    });

    it('says how to add the field when an Airtable table has none', () => {
        expect(identityHint(rows(['Full name', 'full_name']), 'table', ['singleLineText'])).toBe(TABLE_MISSING);
    });

    it('adds, on a rule that is already saved, that adding the column pauses it until it is saved again', () => {
        expect(identityHint(rows(['Full name', 'full_name']), 'sheet', null, true)).toBe(`${SHEET_MISSING} ${PAUSES}`);
        expect(identityHint(rows(['Full name', 'full_name']), 'table', null, true)).toBe(`${TABLE_MISSING} ${PAUSES}`);
    });

    it('asks for the binding, not a new column, when the heading is there and unbound', () => {
        const hint = identityHint(rows(['Full name', 'full_name'], ['Submission ID', null]), 'sheet', null, true);

        expect(hint).toContain('Choose Submission ID for the “Submission ID” column.');
        expect(hint).not.toContain('Add a column');
        expect(hint).not.toContain(PAUSES);
    });

    it('says nothing once Submission ID is bound to a sheet column', () => {
        expect(identityHint(rows(['Full name', 'full_name'], ['Submission ID', IDENTITY_KEY]), 'sheet')).toBeNull();
    });

    it('warns only for the Airtable field types on the denylist', () => {
        const bound = rows(['Full name', 'full_name'], ['Submission ID', IDENTITY_KEY]);

        for (const type of ['number', 'currency', 'percent', 'date', 'dateTime', 'checkbox', 'rating', 'duration']) {
            expect(identityHint(bound, 'table', ['singleLineText', type]), type).toBe(TYPE_WARNING);
        }

        for (const type of ['singleLineText', 'multilineText', 'singleSelect']) {
            expect(identityHint(bound, 'table', ['singleLineText', type]), type).toBeNull();
        }

        // Types are read by the BOUND row's position, not by the first row's.
        expect(identityHint(bound, 'table', ['number', 'singleLineText'])).toBeNull();
    });
});

describe('rebindByHeading / restoreRows (M96)', () => {
    const stored = {
        fingerprint: 'fp-saved',
        columns: [
            { header: 'full name', field_key: 'full_name' },
            { header: 'colour', field_key: 'colour' },
            { header: 'submission id', field_key: null },
        ],
    };

    it('carries the stored bindings by position, untouched, when the columns have not changed', () => {
        const restored = restoreRows({ header_row: ['Full name', 'Colour', 'Submission ID'], fingerprint: 'fp-saved' }, stored);

        expect(restored.drifted).toBe(false);
        // The stored rule left Submission ID unbound. Opening it must not quietly change that.
        expect(restored.rows.map((r) => r.fieldKey)).toEqual(['full_name', 'colour', null]);
    });

    it('never carries a binding by position once the columns changed, and matches by heading instead', () => {
        // A column was inserted and two were swapped. By position, `full_name` would land on "Colour".
        const restored = restoreRows(
            { header_row: ['Colour', 'Reviewer', 'Full name', 'Submission ID'], fingerprint: 'fp-now' },
            stored,
        );

        expect(restored.drifted).toBe(true);
        expect(restored.rows.map((r) => r.fieldKey)).toEqual(['colour', null, 'full_name', IDENTITY_KEY]);
    });

    it('treats a rule with no stored mapping as a fresh map', () => {
        const restored = restoreRows({ header_row: ['Full name', 'Submission ID'], fingerprint: 'fp-now' }, null);

        expect(restored.drifted).toBe(false);
        expect(restored.rows.map((r) => r.fieldKey)).toEqual([null, IDENTITY_KEY]);
    });

    it('leaves a column with no heading unbound, because there is no text to match it by', () => {
        const built = rebindByHeading(['', 'Notes'], [
            { header: '', field_key: 'full_name' },
            { header: 'notes', field_key: 'colour' },
        ]);

        expect(built.map((r) => r.fieldKey)).toEqual([null, 'colour']);
    });

    it('uses each stored binding once, on the first matching heading, and never twice', () => {
        const built = rebindByHeading(['Name', 'Name'], [{ header: 'name', field_key: 'full_name' }]);

        expect(built.map((r) => r.fieldKey)).toEqual(['full_name', null]);
    });
});
