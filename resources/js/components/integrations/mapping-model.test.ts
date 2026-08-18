import { describe, expect, it } from 'vitest';
import {
    buildRows,
    columnLabel,
    columnLetter,
    fieldOptionsFor,
    mappingProblem,
    mappingSummary,
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
