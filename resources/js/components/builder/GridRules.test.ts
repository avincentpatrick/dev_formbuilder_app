import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GridColumnRules from './GridColumnRules.vue';
import GridRowRules from './GridRowRules.vue';
import type { ConditionCatalogue } from './types';

/*
 * M246 (`grid-question-table`) — a grid row's and a Grid column's own settings in the Grid tab, folded behind a button
 * that says what is set. What each panel writes is the row or column with one setting changed; an emptied setting is
 * taken out, so a row or column with none is exactly what it was before M246.
 */

const catalogue: ConditionCatalogue = {
    fields: [{ key: 'gate', label: 'Gate', numeric: false, operand_kind: 'text', options: [] }],
    repeatables: [],
    kinds: [{ value: 'text', offered: true, orders: false, equals: true, includes: false, orders_with: [], literal_input: null }],
};

describe('GridRowRules', () => {
    it('says what is set while closed, opens on its button, and writes "Not needed" ticks in column order', async () => {
        const wrapper = mount(GridRowRules, {
            props: {
                row: { value: 'b03', label: 'B03', not_needed: ['comment'] },
                index: 1,
                columns: [{ value: 'cut', label: 'Cut-off' }, { value: 'comment', label: 'Comment' }],
                catalogue,
                formId: 'form-1',
            },
        });
        const toggle = wrapper.find('[data-grid-row-settings="1"]');

        expect(toggle.text()).toBe('Row settings — 1 not needed');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');

        await wrapper.find('[data-grid-not-needed-ticks="1"] input').setValue(true);
        expect(wrapper.emitted('update:row')?.at(-1)).toEqual([{ value: 'b03', label: 'B03', not_needed: ['cut', 'comment'] }]);
    });

    it('takes an emptied setting out of the row, and warns when the row is named elsewhere', async () => {
        const wrapper = mount(GridRowRules, {
            props: { row: { value: 'a', label: 'A', not_needed: ['cut'] }, index: 0, columns: [{ value: 'cut', label: 'Cut-off' }], catalogue, formId: 'form-1', namedBy: ['Follow-up'] },
        });

        await wrapper.find('[data-grid-not-needed-ticks="0"] input').setValue(false);
        expect(wrapper.emitted('update:row')?.at(-1)).toEqual([{ value: 'a', label: 'A' }]);
        expect(wrapper.find('[data-grid-named-by]').text()).toContain('Named by Follow-up.');
    });

    it('has no "Not needed" ticks for a Likert grid, and edits details with no picture', async () => {
        const wrapper = mount(GridRowRules, { props: { row: { value: 'r', label: 'R' }, index: 0, columns: [], catalogue, formId: 'form-1' } });
        await wrapper.find('[data-grid-row-settings="0"]').trigger('click');

        expect(wrapper.find('[data-grid-not-needed-ticks="0"]').exists()).toBe(false);
        expect(wrapper.find('[data-content-notice="row-details"]').exists()).toBe(true);
        expect(wrapper.find('input[type="file"]').exists()).toBe(false);
    });
});

describe('GridColumnRules', () => {
    it('offers its per-row rules this row\'s cells to the left, and hides "required when" when required in every row', async () => {
        const wrapper = mount(GridColumnRules, {
            props: {
                column: { value: 'comment', label: 'Comment', needed_when: "${.cut} = 'change'" },
                index: 1,
                catalogue,
                rowCells: [{ key: '.cut', label: 'This row’s Cut-off', numeric: false, operand_kind: 'text', options: [] }],
            },
        });

        expect(wrapper.find('[data-grid-column-settings="1"]').text()).toBe('Column rules — needed only sometimes');
        await wrapper.find('[data-grid-column-settings="1"]').trigger('click');
        expect(wrapper.findAll('fieldset legend').map((l) => l.text())).toEqual(expect.arrayContaining([
            'Show this column only when…',
            'In each row, this cell is needed only when…',
            'In each row, this cell is required when…',
        ]));

        await wrapper.setProps({ column: { value: 'comment', label: 'Comment', required: true } });
        expect(wrapper.text()).toContain('Required in every row is on');
    });
});
