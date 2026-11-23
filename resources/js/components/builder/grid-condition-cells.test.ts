import { describe, expect, it } from 'vitest';
import { cellNamesUsed, gridCellEntries, rowCellEntries } from './grid-condition-cells';
import { describe as describeCondition } from './condition-describer';
import { findTrigger, referenceCandidates } from './reference-candidates';
import type { PaletteType } from './types';

/*
 * M246 (`grid-question-table`, `R-ffd10da2`) — which grid cells a condition, a calculation or piped text may name. A rule
 * elsewhere reads only an ALWAYS-ASKED cell (`D230`): its row has no show-when, its column no show-when or needed-when,
 * its row does not mark it "Not needed", and the grid's rows are its own. Piped text may show any cell.
 */

const palette = [
    { value: 'single_select', operand_kind: 'choice', pipeable: true },
    { value: 'long_text', operand_kind: 'text', pipeable: true },
    { value: 'integer', operand_kind: 'number', pipeable: true },
    { value: 'likert_scale', operand_kind: 'number', pipeable: true },
    { value: 'short_text', operand_kind: 'text', pipeable: true },
    { value: 'matrix', operand_kind: 'object', pipeable: false },
    { value: 'likert_matrix', operand_kind: 'object', pipeable: false },
] as unknown as PaletteType[];

const mx = {
    key: 'mx',
    label: 'Indicators',
    field_type: 'matrix',
    config: {
        rows: [
            { value: 'a01', label: 'A01' },
            { value: 'b03', label: 'B03', not_needed: ['cut'] },
            { value: 'c09', label: 'C09', relevant: "${gate} = 'yes'" },
            { value: 'Row 4', label: 'Row four' },
        ],
        columns: [
            { value: 'cut', label: 'Cut-off', type: 'single_select', options: [{ value: 'change', label: 'Change it' }] },
            { value: 'keep', label: 'Keep it?' },
            { value: 'comment', label: 'Comment', type: 'long_text', needed_when: "${.cut} = 'change'" },
        ],
        cells: [{ value: 'yes', label: 'Yes' }],
    },
};
const lk = { key: 'sat', label: 'Satisfaction', field_type: 'likert_matrix', config: { rows: [{ value: 'clean', label: 'Clean' }], columns: [{ value: '1', label: 'Poor' }, { value: '4', label: 'Good' }] } };
const sourced = { key: 'wards', label: 'Wards', field_type: 'matrix', config: { rows: [{ value: 'w1', label: 'W1' }], rows_list: 'wards', columns: [{ value: 'n', label: 'N' }], cells: [] } };

describe('gridCellEntries', () => {
    it('offers only always-asked cells, by their whole path, with their column\'s kind and choices', () => {
        const cells = gridCellEntries([mx, lk, sourced], palette, null);

        expect(cells.map((c) => c.key)).toEqual(['mx.a01.cut', 'mx.a01.keep', 'mx.b03.keep', 'sat.clean']);
        expect(cells[0]).toMatchObject({ label: 'Indicators — A01 — Cut-off', operand_kind: 'choice', options: [{ value: 'change', label: 'Change it' }] });
        // A choice column with no list of its own uses the grid's shared cells.
        expect(cells[1].options).toEqual([{ value: 'yes', label: 'Yes' }]);
        expect(cells[3]).toMatchObject({ label: 'Satisfaction — Clean', operand_kind: 'number', numeric: true });
    });

    it('never offers the grid being edited, and offers any nameable cell to piped text', () => {
        expect(gridCellEntries([mx], palette, 'mx')).toEqual([]);
        const any = gridCellEntries([mx], palette, null, true).map((c) => c.key);

        expect(any).toContain('mx.c09.comment');
        expect(any).toContain('mx.b03.cut');
        expect(any.some((key) => key.includes('Row 4'))).toBe(false);
    });
});

describe('rowCellEntries', () => {
    it('offers "This row\'s …" for the columns to the left only', () => {
        expect(rowCellEntries(mx.config, 2, palette).map((c) => [c.key, c.label])).toEqual([
            ['.cut', 'This row’s Cut-off'],
            ['.keep', 'This row’s Keep it?'],
        ]);
        expect(rowCellEntries(mx.config, 0, palette)).toEqual([]);
    });
});

describe('cellNamesUsed', () => {
    it('finds every row and column of a grid named elsewhere, by who names it', () => {
        const used = cellNamesUsed('mx', [
            { owner: 'Follow-up', text: "${mx.a01.keep} = 'yes'" },
            { owner: 'Parts A01', text: "${mx.a01.keep} = 'close' or ${other.a01.keep} = 1" },
            { owner: 'Echo', text: 'You chose ${mx.b03.cut}.' },
            { owner: 'Nothing', text: null },
        ]);

        expect(Object.fromEntries(used)).toEqual({ a01: ['Follow-up', 'Parts A01'], keep: ['Follow-up', 'Parts A01'], b03: ['Echo'], cut: ['Echo'] });
    });
});

describe('cells in the describer and the reference picker', () => {
    it('describes a cell by its grid\'s words, and this row\'s cell as this row\'s', () => {
        const reading = describeCondition("${mx.a01.keep} = 'close' or ${.cut} = 'change'", { mx: 'Indicators' });

        expect(reading.status).toBe('described');
        expect(reading.status === 'described' ? reading.prose : '').toContain('Indicators — a01 — keep');
        expect(reading.status === 'described' ? reading.prose : '').toContain('this row’s cut');
    });

    it('offers a grid\'s always-asked cells in a formula and any of an earlier grid\'s in piped text, inserted whole', () => {
        const source = {
            fields: [
                { ...mx, form_section_id: null, sequence: 1 },
                { key: 'later', label: 'Later', field_type: 'short_text', form_section_id: null, sequence: 2 },
            ],
            sections: [],
            types: palette,
            kinds: [{ value: 'choice', offered: true }, { value: 'text', offered: true }, { value: 'object', offered: false }] as never,
        };
        const formula = referenceCandidates(source, { grammar: 'expression', ownKey: 'later' });
        const text = referenceCandidates(source, { grammar: 'template', host: { kind: 'field', key: 'later' } });

        expect(formula.filter((c) => c.group === 'Grid cells').map((c) => c.insert)).toEqual(['${mx.a01.cut}', '${mx.a01.keep}', '${mx.b03.keep}']);
        expect(text.filter((c) => c.group === 'Grid cells').map((c) => c.key)).toContain('mx.c09.comment');
        expect(referenceCandidates(source, { grammar: 'expression', ownKey: 'later', earlierOnly: true }).some((c) => c.group === 'Grid cells')).toBe(false);
    });

    it('keeps the picker open while a cell\'s path is typed', () => {
        expect(findTrigger('${mx.a0')).toEqual({ from: 0, query: 'mx.a0' });
        expect(findTrigger('Kept: ${mx.row-1.c')).toEqual({ from: 6, query: 'mx.row-1.c' });
    });
});
