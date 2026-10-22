/**
 * M183 — the grid drawn as a table (`R-1c1d7266`), a cell per column type (`R-5033e87e`) and KoboToolbox's Likert
 * grid (`R-f03cf999`). jsdom draws no layout, so what is pinned here is the DOM both drawings share — one `<table>`
 * with its header cells, one control per cell by its column's type, every control named by its row and its column —
 * and the switch the CSS keys on. The table-or-cards look itself is the e2e spec's and the real-browser look's.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';

import type { AnswerValue, EncodeField } from './FieldInput.vue';
import MatrixGrid from './MatrixGrid.vue';

function typedGrid(overrides: Partial<EncodeField> = {}, layout: 'table' | 'stacked' = 'table'): EncodeField {
    return {
        key: 'clinics',
        field_type: 'matrix',
        label: 'Clinics',
        hint: 'One row per clinic.',
        placeholder: null,
        required: false,
        options: [],
        supported: true,
        matrix: {
            rows: [{ value: 'a', label: 'Clinic A' }, { value: 'b', label: 'Clinic B' }],
            columns: [
                { value: 'staff', label: 'Staff on duty', type: 'integer', options: [], required: true },
                { value: 'open', label: 'Opened', type: 'date', options: [], required: false },
                { value: 'svc', label: 'Services', type: 'multi_select', options: [{ value: 'lab', label: 'Lab' }, { value: 'xray', label: 'X-ray' }], required: false },
                { value: 'note', label: 'Note', type: 'short_text', options: [], required: false },
                { value: 'rate', label: 'Rating', type: 'single_select', options: [{ value: 'poor', label: 'Poor' }, { value: 'good', label: 'Good' }], required: false },
            ],
            cells: [{ value: 'poor', label: 'Poor' }, { value: 'good', label: 'Good' }],
            layout,
        },
        ...overrides,
    };
}

function likertGrid(): EncodeField {
    return {
        key: 'sat',
        field_type: 'likert_matrix',
        label: 'Rate the clinic',
        hint: null,
        placeholder: null,
        required: false,
        options: [],
        supported: true,
        matrix: {
            rows: [{ value: 'clean', label: 'Cleanliness' }, { value: 'staff', label: 'Staff friendliness' }],
            columns: [{ value: '1', label: 'Poor' }, { value: '2', label: 'Fair' }, { value: '3', label: 'Good' }],
            cells: [],
            layout: 'table',
        },
    };
}

function grid(field: EncodeField, modelValue: AnswerValue = null, error?: string) {
    return mount(MatrixGrid, {
        props: { field, kind: field.field_type === 'likert_matrix' ? 'likert-matrix' : 'matrix', modelValue, error },
        attachTo: document.body,
    });
}

/** The words a control's `aria-labelledby` reads out, resolved through the document. */
function nameOf(control: Element): string {
    return (control.getAttribute('aria-labelledby') ?? '')
        .split(' ')
        .map((id) => document.getElementById(id)?.textContent?.trim() ?? `<missing ${id}>`)
        .join(' ');
}

function lastValue(wrapper: ReturnType<typeof grid>): unknown {
    const emitted = wrapper.emitted('update:modelValue') ?? [];

    return emitted[emitted.length - 1]?.[0];
}

describe('MatrixGrid — one table, a control per column type (M183, R-1c1d7266, R-5033e87e)', () => {
    it('draws one table: the columns across the top and a header cell for every row', () => {
        const wrapper = grid(typedGrid());

        expect(wrapper.findAll('table')).toHaveLength(1);
        expect(wrapper.findAll('th[scope="col"]').map((th) => th.text())).toEqual(['Staff on duty', 'Opened', 'Services', 'Note', 'Rating']);
        expect(wrapper.findAll('th[scope="row"]').map((th) => th.text())).toEqual(['Clinic A', 'Clinic B']);
        expect(wrapper.findAll('tbody tr')).toHaveLength(2);
        wrapper.unmount();
    });

    it('draws each cell by its column’s type', () => {
        const wrapper = grid(typedGrid());
        const cells = wrapper.findAll('tbody tr')[0].findAll('td');

        expect(cells[0].find('input[type="number"]').exists()).toBe(true);
        expect(cells[1].find('input[type="date"]').exists()).toBe(true);
        expect(cells[2].find('[role="group"]').findAll('input[type="checkbox"]')).toHaveLength(2);
        expect(cells[2].text()).toContain('X-ray');
        expect(cells[3].find('input[type="text"]').exists()).toBe(true);
        expect(cells[4].findAll('select option').map((o) => o.text())).toEqual(['Select…', 'Poor', 'Good']);
        wrapper.unmount();
    });

    it('names every control by its row and its column, so either drawing reads the same', () => {
        const wrapper = grid(typedGrid());
        const second = wrapper.findAll('tbody tr')[1];

        expect(nameOf(second.find('input[type="number"]').element)).toBe('Clinic B Staff on duty');
        expect(nameOf(second.find('[role="group"]').element)).toBe('Clinic B Services');
        expect(nameOf(second.find('select').element)).toBe('Clinic B Rating');
        wrapper.unmount();
    });

    it('writes each kind of cell as a question of its type stores it, and drops a cell emptied', async () => {
        const wrapper = grid(typedGrid(), { a: { svc: ['xray'], rate: 'good' } });
        const row = wrapper.findAll('tbody tr')[0];

        await row.find('input[type="number"]').setValue('3');
        expect(lastValue(wrapper)).toEqual({ a: { svc: ['xray'], rate: 'good', staff: 3 } });

        await row.find('[role="group"]').findAll('input[type="checkbox"]')[0].setValue(true);
        expect(lastValue(wrapper)).toEqual({ a: { svc: ['xray', 'lab'], rate: 'good' } });

        await row.find('select').setValue('');
        expect(lastValue(wrapper)).toEqual({ a: { svc: ['xray'] } });
        wrapper.unmount();
    });

    it('keeps the cards at every width when the author chose them', () => {
        const cards = grid(typedGrid({}, 'stacked'));
        const table = grid(typedGrid());

        expect(cards.classes()).toContain('mds-grid--stacked');
        expect(table.classes()).not.toContain('mds-grid--stacked');
        cards.unmount();
        table.unmount();
    });

    it('describes the grid with its hint and its error, and marks each control with the error', () => {
        const wrapper = grid(typedGrid(), null, 'Enter the staff on duty for every clinic.');
        const hint = wrapper.get('.mds-grid__help');
        const error = wrapper.get('.mds-grid__error');

        expect((wrapper.get('fieldset').attributes('aria-describedby') ?? '').split(' ')).toEqual([hint.attributes('id'), error.attributes('id')]);
        const number = wrapper.get('input[type="number"]');
        expect(number.attributes('aria-invalid')).toBe('true');
        expect(number.attributes('aria-describedby')).toBe(error.attributes('id'));
        wrapper.unmount();
    });
});

describe('MatrixGrid — a Likert matrix, KoboToolbox’s way (M183, R-f03cf999)', () => {
    it('draws the scale’s words across the top and one radio per cell, named by statement and point', () => {
        const wrapper = grid(likertGrid());

        expect(wrapper.classes()).toContain('mds-grid--likert');
        expect(wrapper.findAll('th[scope="col"]').map((th) => th.text())).toEqual(['Poor', 'Fair', 'Good']);
        const radios = wrapper.findAll('tbody tr')[0].findAll('input[type="radio"]');
        expect(radios).toHaveLength(3);
        expect(nameOf(radios[2].element)).toBe('Cleanliness Good');
        wrapper.unmount();
    });

    it('gives every row its own group, and every rendered copy its own groups — never the question’s key', () => {
        const Pair = defineComponent({
            setup: () => () =>
                h('div', [
                    h(MatrixGrid, { field: likertGrid(), kind: 'likert-matrix', modelValue: { clean: '1' } }),
                    h(MatrixGrid, { field: likertGrid(), kind: 'likert-matrix', modelValue: { clean: '3' } }),
                ]),
        });
        const wrapper = mount(Pair, { attachTo: document.body });
        const names = wrapper.findAll('fieldset.mds-grid').map((set) => set.findAll('tbody tr').map((tr) => new Set(tr.findAll('input[type="radio"]').map((r) => r.attributes('name')))));

        expect(names.flat().map((set) => set.size)).toEqual([1, 1, 1, 1]);
        const all = names.flat().map((set) => [...set][0]);
        expect(new Set(all).size).toBe(4);
        expect(all.some((name) => name?.includes('sat'))).toBe(false);
        wrapper.unmount();
    });

    it('writes the chosen point for its row', async () => {
        const wrapper = grid(likertGrid(), { staff: '2' });

        await wrapper.findAll('tbody tr')[0].findAll('input[type="radio"]')[2].setValue(true);

        expect(lastValue(wrapper)).toEqual({ staff: '2', clean: '3' });
        wrapper.unmount();
    });
});
