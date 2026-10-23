import { describe, expect, it } from 'vitest';

import { asChoiceConfig, slotIsSourced, withChoiceConfig } from './grid-sources';

/**
 * M185 (`R-bb79aec0`, `D161`): a grid's rows and cells are read and written by the one chooser under a choice question's
 * key names; `tests/Unit/Forms/GridSourcesTest.php` holds the server's half.
 */
describe('grid-sources', () => {
    it('renames a slot’s keys to a question’s, each present only where the grid holds it', () => {
        expect(asChoiceConfig({ rows: [{ value: 'a', label: 'A' }], cells: [] }, 'rows')).toEqual({ options: [{ value: 'a', label: 'A' }] });
        expect(asChoiceConfig({ rows: [], rows_list: 'wards' }, 'rows')).toEqual({ options: [], options_list: 'wards' });
        expect(asChoiceConfig({ cells_source: { form_id: null, field_key: null } }, 'cells')).toEqual({ options: [], options_source: { form_id: null, field_key: null } });
    });

    it('writes the chooser’s answer back under the slot’s names, and clears the source it no longer takes', () => {
        const fromForm = { rows: [], rows_source: { form_id: 'f', field_key: 'k' }, columns: [{ value: 'c', label: 'C' }], layout: 'stacked' };

        expect(withChoiceConfig(fromForm, 'rows', { options: [], options_list: '' })).toEqual({
            rows: [],
            rows_list: '',
            columns: [{ value: 'c', label: 'C' }],
            layout: 'stacked',
        });
        expect(withChoiceConfig({ cells: [], cells_list: 'yn' }, 'cells', { options: [], options_source: null })).toEqual({ cells: [], cells_source: null });
    });

    it('tells a sourced slot from a typed one', () => {
        expect(slotIsSourced({ rows: [], rows_list: '' }, 'rows')).toBe(true);
        expect(slotIsSourced({ rows: [], rows_source: null }, 'rows')).toBe(false);
        expect(slotIsSourced({ cells: [{ value: 'y', label: 'Yes' }] }, 'cells')).toBe(false);
    });
});
