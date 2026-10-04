import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Radio from './Radio.vue';

/**
 * MdsRadio (DSR §3.2, M130). The contract is the native control underneath: a real radio that the keyboard
 * reaches and a screen reader names, grouped by `name`, describable and markable invalid like MdsCheckbox.
 */
describe('MdsRadio', () => {
    it('renders a real radio input, named and valued, inside its label', () => {
        const wrapper = mount(Radio, { props: { value: 'red', label: 'Red', name: 'colour' } });
        const input = wrapper.find('input');

        expect(input.attributes('type')).toBe('radio');
        expect(input.attributes('name')).toBe('colour');
        expect(input.attributes('value')).toBe('red');
        // The label wraps the input, so the visible text IS the accessible name.
        expect(wrapper.find('label').text()).toContain('Red');
    });

    it('is checked exactly when the group value equals its own', () => {
        const chosen = mount(Radio, { props: { modelValue: 'red', value: 'red', label: 'Red', name: 'c' } });
        const other = mount(Radio, { props: { modelValue: 'blue', value: 'red', label: 'Red', name: 'c' } });

        expect((chosen.find('input').element as HTMLInputElement).checked).toBe(true);
        expect((other.find('input').element as HTMLInputElement).checked).toBe(false);
    });

    it('emits its own value when chosen', async () => {
        const wrapper = mount(Radio, { props: { modelValue: '', value: 'red', label: 'Red', name: 'c' } });

        await wrapper.find('input').trigger('change');

        expect(wrapper.emitted('update:modelValue')).toEqual([['red']]);
    });

    it('carries a description and an invalid state on the control itself', () => {
        // What a summary jump lands on must say what is wrong — the reason the prop exists.
        const wrapper = mount(Radio, { props: { value: 'red', label: 'Red', name: 'c', describedby: 'err-1', invalid: true } });
        const input = wrapper.find('input');

        expect(input.attributes('aria-describedby')).toBe('err-1');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('.mds-radio__circle').classes()).toContain('mds-radio__circle--invalid');
    });

    it('omits aria-invalid entirely when valid, rather than writing false', () => {
        const wrapper = mount(Radio, { props: { value: 'red', label: 'Red', name: 'c' } });

        expect(wrapper.find('input').attributes('aria-invalid')).toBeUndefined();
    });

    it('passes disabled to the native control', () => {
        const wrapper = mount(Radio, { props: { value: 'red', label: 'Red', name: 'c', disabled: true } });

        expect((wrapper.find('input').element as HTMLInputElement).disabled).toBe(true);
        expect(wrapper.find('label').classes()).toContain('mds-radio--disabled');
    });
});
