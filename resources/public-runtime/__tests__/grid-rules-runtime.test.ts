import { describe, expect, it } from 'vitest';
import { createFormRuntime } from '../composables/useFormRuntime';
import { field, schemaResponse } from './fixtures';

/*
 * M246 (`grid-question-table`) — the runtime hands each grid what its own rules ask and each cell's own error. An
 * error shows on a cell once that cell is left, once the person tries to leave the page, or on Submit — never on every
 * cell of a table after its first answer — and a server refusal at a cell's address always shows, on that cell.
 */

function tableRuntime() {
    return createFormRuntime(
        schemaResponse({
            fields: [
                field({ key: 'gate', sequence: 1 }),
                field({
                    key: 'mx',
                    field_type: 'matrix',
                    sequence: 2,
                    config: {
                        rows: [
                            { value: 'a01', label: 'A01' },
                            { value: 'a02', label: 'A02', relevant: "${gate} = 'yes'" },
                        ],
                        columns: [
                            { value: 'cut', label: 'Cut-off', type: 'single_select', options: [{ value: 'change', label: 'Change' }, { value: 'use', label: 'Use' }] },
                            { value: 'why', label: 'Why', type: 'long_text', required_when: "${.cut} = 'change'" },
                        ],
                        cells: [],
                    },
                }),
            ],
        }),
    );
}

describe('a grid\'s own rules in the runtime', () => {
    it('reports which rows and cells are asked', () => {
        const runtime = tableRuntime();

        expect(runtime.gridRelevanceFor('mx')).toEqual({
            rows: { a01: true, a02: false },
            columns: { cut: true, why: true },
            cells: { a01: { cut: true, why: true } },
        });
        runtime.setAnswer('gate', 'yes');
        expect(runtime.gridRelevanceFor('mx')?.rows.a02).toBe(true);
        expect(runtime.gridRelevanceFor('gate')).toBeNull();
    });

    it('shows a cell its own error only once it is left, the page is attempted, or on Submit', () => {
        const runtime = tableRuntime();
        runtime.setAnswer('mx', { a01: { cut: 'change' } });

        // Leaving the grid as a whole (the row's focusout) is not leaving the comment cell.
        runtime.markTouched('mx');
        expect(runtime.cellErrorsFor('mx')).toEqual({});

        runtime.markTouched('mx.a01.why');
        expect(Object.keys(runtime.cellErrorsFor('mx'))).toEqual(['a01.why']);

        const fresh = tableRuntime();
        fresh.setAnswer('mx', { a01: { cut: 'change' } });
        fresh.markSubmitAttempted();
        expect(Object.keys(fresh.cellErrorsFor('mx'))).toEqual(['a01.why']);
    });

    it('lands a server refusal on its cell, and keeps the grid\'s own line for one that is not a cell\'s', () => {
        const runtime = tableRuntime();
        runtime.setServerErrors({ 'mx.a01.cut': ['That choice is gone.'], mx: ['This must be a grid of answers.'] });

        expect(runtime.cellErrorsFor('mx')).toEqual({ 'a01.cut': 'That choice is gone.' });
        expect(runtime.gridErrorFor('mx')).toBe('This must be a grid of answers.');
        expect(runtime.gridErrorFor('gate')).toBeUndefined();
    });
});
