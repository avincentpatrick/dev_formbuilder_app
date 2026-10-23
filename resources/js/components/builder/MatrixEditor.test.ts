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

describe('MatrixEditor — rows, cells and columns from a list or a form (M185, R-bb79aec0, D161)', () => {
    const SOURCES = [{ id: 'src-1', title: 'Facility Register', questions: [{ key: 'facility_name', label: 'Facility name' }] }];

    function sourced(config: Record<string, unknown>) {
        return mount(MatrixEditor, {
            props: {
                rows: (config.rows as never) ?? [],
                columns: (config.columns as never) ?? [],
                cells: (config.cells as never) ?? [],
                config,
                sources: SOURCES,
            },
        });
    }

    function lastConfig(wrapper: ReturnType<typeof sourced>): Record<string, unknown> {
        return (wrapper.emitted('update:config') ?? []).at(-1)?.[0] as Record<string, unknown>;
    }

    it('offers no source without the grid’s config, as T2 drew it', () => {
        const wrapper = editor([{ value: 'c1', label: 'C1' }]);

        expect(wrapper.find('[data-grid-rows-source]').exists()).toBe(false);
        expect(wrapper.find('[data-column-source="0"]').exists()).toBe(false);
    });

    it('takes the rows from another form in one whole-config change, and hides the typed rows once it does', async () => {
        const config = { rows: [], columns: [{ value: 'c', label: 'C' }], cells: [], layout: 'stacked' };
        const wrapper = sourced(config);

        expect(wrapper.text()).toContain('Each row is a question.');
        await wrapper.get('[data-grid-rows-source]').find('input[type="radio"][value="form"]').setValue(true);
        expect(lastConfig(wrapper)).toEqual({ ...config, rows_source: { form_id: null, field_key: null } });

        await wrapper.setProps({ config: lastConfig(wrapper) });
        expect(wrapper.text()).not.toContain('Each row is a question.');
    });

    it('gives a choice column its own source, the other columns untouched, and stops counting it among the cells’ users', async () => {
        const columns = [{ value: 'svc', label: 'Services', type: 'multi_select' }, { value: 'grade', label: 'Grade' }];
        const wrapper = sourced({ rows: [{ value: 'r', label: 'R' }], columns, cells: [] });

        expect(wrapper.get('[data-cell-choices-users]').text()).toBe('Used by: Services, Grade.');
        await wrapper.get('[data-column-source="0"]').find('input[type="radio"][value="form"]').setValue(true);
        const next = lastConfig(wrapper);
        expect(next.columns).toEqual([
            { value: 'svc', label: 'Services', type: 'multi_select', options: [], options_source: { form_id: null, field_key: null } },
            columns[1],
        ]);

        await wrapper.setProps({ config: next, columns: next.columns as never });
        expect(wrapper.get('[data-cell-choices-users]').text()).toBe('Used by: Grade.');
        expect(wrapper.get('[data-column-choices="0"]').text()).not.toContain('Choices for Services');
    });

    it('offers no source on a column that takes no choices', () => {
        const wrapper = sourced({ rows: [], columns: [{ value: 'n', label: 'Count', type: 'integer' }], cells: [] });

        expect(wrapper.find('[data-column-source="0"]').exists()).toBe(false);
    });
});

describe('LikertMatrixEditor — statements from a list or a form (M185)', () => {
    it('takes its rows from another form and keeps its scale typed', async () => {
        const config = { rows: [], columns: [{ value: '1', label: 'Poor' }] };
        const wrapper = mount(LikertMatrixEditor, { props: { rows: [], columns: config.columns, config, sources: [] } });

        await wrapper.get('[data-grid-rows-source]').find('input[type="radio"][value="form"]').setValue(true);

        expect((wrapper.emitted('update:config') ?? []).at(-1)?.[0]).toEqual({ ...config, rows_source: { form_id: null, field_key: null } });
        expect(wrapper.text()).toContain('Scale points');
    });
});
