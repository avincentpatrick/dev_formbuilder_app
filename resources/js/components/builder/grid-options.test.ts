import { describe, expect, it } from 'vitest';

import { addGridEntry, removeGridEntry, renameGridEntry } from './grid-options';

/**
 * M185 (`R-7418e6bd`) — how the Grid tab and the Preview add, rename and remove a grid's rows and columns: the same rule
 * `GridOptionList.vue` always applied, in one place.
 */
describe('grid-options', () => {
    it('adds the first free value from the list’s length up, labelled by its number', () => {
        expect(addGridEntry([], 'row', 'Row')).toEqual([{ value: 'row_1', label: 'Row 1' }]);
        expect(addGridEntry([{ value: 'a', label: 'A' }, { value: 'row_3', label: 'Taken' }], 'row', 'Row').at(-1)).toEqual({ value: 'row_4', label: 'Row 4' });
    });

    it('renames the label alone, keeping the value answers are saved under and every other key', () => {
        const columns = [{ value: 'col_1', label: 'Column 1', type: 'integer', required: true }];

        expect(renameGridEntry(columns, 0, 'Staff on duty')).toEqual([{ value: 'col_1', label: 'Staff on duty', type: 'integer', required: true }]);
        expect(columns[0].label).toBe('Column 1');
    });

    it('removes one entry', () => {
        expect(removeGridEntry([{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }], 0)).toEqual([{ value: 'b', label: 'B' }]);
    });
});
