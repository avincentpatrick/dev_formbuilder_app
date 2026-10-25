/**
 * Choices narrowed by what another form recorded (M186, `R-ed7f3127`), through `FieldInput` alone: a list shows the
 * choices its set allows — or the others, for "no answer" — and keeps whatever the answer already holds; a cascade's
 * filtered level narrows every level above it too (`D162`); a set that could not be read narrows nothing and says so
 * (`D163`). Display only: the answer is never changed by narrowing.
 */
import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import FieldInput, { type ChoiceFilter, type EncodeField } from './FieldInput.vue';

function dropdown(filter: ChoiceFilter[] | null): EncodeField {
    return {
        key: 'facility',
        field_type: 'dropdown',
        label: 'Facility',
        hint: 'Where the visit was.',
        placeholder: null,
        required: false,
        options: [
            { value: 'rhu', label: 'San Jose RHU' },
            { value: 'bhs', label: 'Bayan BHS' },
            { value: 'clinic', label: 'Old Clinic' },
        ],
        choice_filter: filter,
        supported: true,
    };
}

function place(filter: ChoiceFilter[] | null): EncodeField {
    return {
        key: 'place',
        field_type: 'cascading_select',
        label: 'Place',
        hint: null,
        placeholder: null,
        required: false,
        options: [],
        cascade: {
            levels: [
                { key: 'region', label: 'Region' },
                { key: 'city', label: 'City' },
                { key: 'barangay', label: 'Barangay' },
            ],
            options: [
                { value: 'ncr', label: 'NCR', level: 'region', parent: null },
                { value: 'r1', label: 'Region I', level: 'region', parent: null },
                { value: 'manila', label: 'Manila', level: 'city', parent: 'ncr' },
                { value: 'pasig', label: 'Pasig', level: 'city', parent: 'ncr' },
                { value: 'batac', label: 'Batac', level: 'city', parent: 'r1' },
                { value: 'b1', label: 'Barangay 1', level: 'barangay', parent: 'manila' },
                { value: 'b2', label: 'Barangay 2', level: 'barangay', parent: 'manila' },
                { value: 'p1', label: 'Pinagbuhatan', level: 'barangay', parent: 'pasig' },
                { value: 'a1', label: 'Aglipay', level: 'barangay', parent: 'batac' },
            ],
            skip_single: false,
        },
        choice_filter: filter,
        supported: true,
    };
}

const values = (wrapper: VueWrapper, index = 0): string[] =>
    wrapper
        .findAll('select')
        [index].findAll('option')
        .map((o) => (o.element as HTMLOptionElement).value)
        .filter((v) => v !== '');

describe('a list narrowed by another form', () => {
    it('shows only the choices it has an answer for — matched by value or by label', () => {
        const wrapper = mount(FieldInput, { props: { field: dropdown([{ level: null, mode: 'present', values: ['rhu', 'Bayan BHS'] }]), modelValue: '' } });

        expect(values(wrapper)).toEqual(['rhu', 'bhs']);
    });

    it('shows only those it has no answer for, in the "no answer" mode', () => {
        const wrapper = mount(FieldInput, { props: { field: dropdown([{ level: null, mode: 'absent', values: ['rhu'] }]), modelValue: '' } });

        expect(values(wrapper)).toEqual(['bhs', 'clinic']);
    });

    it('keeps an answer the set no longer holds on screen, and never changes it', () => {
        const wrapper = mount(FieldInput, { props: { field: dropdown([{ level: null, mode: 'present', values: ['rhu'] }]), modelValue: 'clinic' } });

        expect(values(wrapper)).toEqual(['rhu', 'clinic']);
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('shows every choice, and says so, when the set could not be read (D163)', () => {
        const wrapper = mount(FieldInput, { props: { field: dropdown([{ level: null, mode: 'present', values: null }]), modelValue: '' } });

        expect(values(wrapper)).toEqual(['rhu', 'bhs', 'clinic']);
        expect(wrapper.text()).toContain('Where the visit was. These choices could not be narrowed just now, so every choice is shown.');
    });

    it('says when nothing is left to show', () => {
        const wrapper = mount(FieldInput, { props: { field: dropdown([{ level: null, mode: 'present', values: ['elsewhere'] }]), modelValue: '' } });

        expect(values(wrapper)).toEqual([]);
        expect(wrapper.text()).toContain('No choices are left to show.');
    });
});

describe('a cascade level narrowed by another form (D162)', () => {
    it('narrows the levels above to the choices with an allowed choice below them', async () => {
        const wrapper = mount(FieldInput, { props: { field: place([{ level: 2, mode: 'present', values: ['b2', 'p1'] }]), modelValue: [] } });

        // Region I's only barangay has no report, so Region I is not offered at all.
        expect(values(wrapper, 0)).toEqual(['ncr']);

        await wrapper.setProps({ modelValue: ['ncr'] });
        expect(values(wrapper, 1)).toEqual(['manila', 'pasig']);

        await wrapper.setProps({ modelValue: ['ncr', 'manila'] });
        expect(values(wrapper, 2)).toEqual(['b2']);
    });

    it('narrows nothing above a level whose filter could not be read, and says so', () => {
        const wrapper = mount(FieldInput, { props: { field: place([{ level: 2, mode: 'present', values: null }]), modelValue: [] } });

        expect(values(wrapper, 0)).toEqual(['ncr', 'r1']);
        expect(wrapper.text()).toContain('These choices could not be narrowed just now, so every choice is shown.');
    });

    it('keeps a saved path visible even where the set no longer allows it', () => {
        const wrapper = mount(FieldInput, { props: { field: place([{ level: 2, mode: 'present', values: ['p1'] }]), modelValue: ['r1', 'batac', 'a1'] } });

        expect(values(wrapper, 0)).toEqual(['ncr', 'r1']);
        expect(values(wrapper, 1)).toEqual(['batac']);
        expect(values(wrapper, 2)).toEqual(['a1']);
    });

    it('says when no choice is left anywhere', () => {
        const wrapper = mount(FieldInput, { props: { field: place([{ level: 2, mode: 'present', values: [] }]), modelValue: [] } });

        expect(values(wrapper, 0)).toEqual([]);
        expect(wrapper.text()).toContain('No choices are left to show.');
    });
});
