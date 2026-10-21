import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import LikertMatrixEditor from './LikertMatrixEditor.vue';
import MatrixEditor from './MatrixEditor.vue';

/**
 * M183 (`R-5033e87e`, `D129`; `R-1c1d7266`): the Grid tab gives each matrix column its own type, its own choices and a
 * required flag, says which columns still draw on the shared Cell choices, and offers both grids a layout. Every
 * change emits a fresh array (one debounced history entry), and a column keeps every key it already had.
 */
const rows = [{ value: 'a', label: 'Clinic A' }];
const cells = [{ value: 'good', label: 'Good' }];

function editor(columns: Record<string, unknown>[], layout: string | null = null) {
    return mount(MatrixEditor, { props: { rows, columns, cells, layout } });
}

function lastColumns(wrapper: ReturnType<typeof editor>): Record<string, unknown>[] {
    const emitted = wrapper.emitted('update:columns') ?? [];

    return emitted[emitted.length - 1]?.[0] as Record<string, unknown>[];
}

describe('MatrixEditor — a type for every column (M183)', () => {
    it('offers the six types a grid cell holds, and writes the choice onto that column alone', async () => {
        const wrapper = editor([{ value: 'c1', label: 'C1' }, { value: 'c2', label: 'C2', label_translations: { fil: 'K2' } }]);
        const select = wrapper.get('[data-column-type="1"]');

        expect(select.findAll('option').map((o) => o.text())).toEqual(['Single choice', 'Multiple choice', 'Short text', 'Whole number', 'Decimal number', 'Date']);

        await select.setValue('integer');

        expect(lastColumns(wrapper)).toEqual([{ value: 'c1', label: 'C1' }, { value: 'c2', label: 'C2', label_translations: { fil: 'K2' }, type: 'integer' }]);
    });

    it('drops a column’s own choices when its new type takes none', async () => {
        const wrapper = editor([{ value: 'svc', label: 'Services', type: 'multi_select', options: [{ value: 'lab', label: 'Lab' }] }]);

        await wrapper.get('[data-column-type="0"]').setValue('date');

        expect(lastColumns(wrapper)).toEqual([{ value: 'svc', label: 'Services', type: 'date' }]);
    });

    it('marks a column required in every row', async () => {
        const wrapper = editor([{ value: 'c1', label: 'C1' }]);

        await wrapper.get('[data-column-required="0"] input').setValue(true);

        expect(lastColumns(wrapper)).toEqual([{ value: 'c1', label: 'C1', required: true }]);
    });

    it('gives a choice column a list of its own, and none to a column that takes no choices', async () => {
        const wrapper = editor([{ value: 'c1', label: 'C1' }, { value: 'n', label: 'Count', type: 'integer' }]);

        expect(wrapper.find('[data-column-choices="1"]').exists()).toBe(false);
        const own = wrapper.get('[data-column-choices="0"]');
        expect(own.text()).toContain('Choices for C1');

        await own.findAll('button').find((b) => b.text() === 'Add choice')!.trigger('click');

        expect(lastColumns(wrapper)[0]).toEqual({ value: 'c1', label: 'C1', options: [{ value: 'choice_1', label: 'Choice 1' }] });
    });

    it('says which columns draw on the shared Cell choices', () => {
        const wrapper = editor([
            { value: 'c1', label: 'Rating' },
            { value: 'c2', label: 'Services', type: 'multi_select', options: [{ value: 'lab', label: 'Lab' }] },
            { value: 'c3', label: 'Count', type: 'integer' },
        ]);
        expect(wrapper.get('[data-cell-choices-users]').text()).toBe('Used by: Rating.');

        const none = editor([{ value: 'c3', label: 'Count', type: 'integer' }]);
        expect(none.get('[data-cell-choices-users]').text()).toBe('No column uses these: every choice column has its own list.');
    });
});

describe('Grid layout (M183)', () => {
    it('offers a table where it fits or cards everywhere, on both grids, and stores nothing for the table', async () => {
        const matrix = editor([{ value: 'c1', label: 'C1' }], 'stacked');
        const select = matrix.get('[data-grid-layout] select');
        expect(select.findAll('option').map((o) => o.text())).toEqual(['Table where it fits', 'Cards everywhere']);
        expect((select.element as HTMLSelectElement).value).toBe('stacked');

        await select.setValue('table');
        expect(matrix.emitted('update:layout')).toEqual([[null]]);

        const likert = mount(LikertMatrixEditor, { props: { rows, columns: [{ value: '1', label: 'Low' }], layout: null } });
        await likert.get('[data-grid-layout] select').setValue('stacked');
        expect(likert.emitted('update:layout')).toEqual([['stacked']]);
    });
});
