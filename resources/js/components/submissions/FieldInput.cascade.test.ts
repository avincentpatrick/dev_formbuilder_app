/**
 * A cascade's levels with one choice (M186, `R-70c7673a`), through `FieldInput` alone: such a level is SHOWN filled in at
 * once, but nothing is emitted until the person picks — an emit on render would start the encode page's autosave and race
 * a guest draft's restore — and then the filled-in levels are written with their pick.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import FieldInput, { type EncodeField } from './FieldInput.vue';

function cascadeField(skipSingle: boolean): EncodeField {
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
                { key: 'province', label: 'Province or district' },
                { key: 'barangay', label: 'Barangay' },
            ],
            options: [
                { value: '13', label: 'NCR', level: 'region', parent: null },
                { value: '1380600000', label: 'City of Manila', level: 'province', parent: '13' },
                { value: '1380601001', label: 'Barangay 1', level: 'barangay', parent: '1380600000' },
            ],
            skip_single: skipSingle,
        },
        supported: true,
    };
}

describe('a cascade level with one choice', () => {
    it('is shown filled in, and nothing is emitted before the person picks', () => {
        const wrapper = mount(FieldInput, { props: { field: cascadeField(true), modelValue: [] } });

        expect(wrapper.findAll('[data-implied]').map((p) => p.text())).toEqual(['NCR', 'City of Manila']);
        // The last level is never filled in, even with one choice: there is always something to answer.
        expect(wrapper.findAll('select')).toHaveLength(1);
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('is written with the person’s pick', async () => {
        const wrapper = mount(FieldInput, { props: { field: cascadeField(true), modelValue: [] } });

        await wrapper.find('select').setValue('1380601001');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['13', '1380600000', '1380601001']]);
    });

    it('is a select like any other when the cascade does not fill levels in', () => {
        const wrapper = mount(FieldInput, { props: { field: cascadeField(false), modelValue: [] } });

        expect(wrapper.find('[data-implied]').exists()).toBe(false);
        expect(wrapper.findAll('select')).toHaveLength(3);
    });

    it('reads the same once the answer holds it: a one-choice level stays text after it is written', () => {
        const wrapper = mount(FieldInput, { props: { field: cascadeField(true), modelValue: ['13', '1380600000'] } });

        expect(wrapper.findAll('[data-implied]').map((p) => p.text())).toEqual(['NCR', 'City of Manila']);
        expect(wrapper.findAll('select')).toHaveLength(1);
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});
