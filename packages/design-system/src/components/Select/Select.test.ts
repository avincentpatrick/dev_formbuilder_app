import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Select from './Select.vue';

/**
 * M249 (`dropdown-placeholder`, `D233`) — the two opt-in additions to the native select.
 *
 * `clearable` lets the placeholder be chosen, which empties the answer; without it the placeholder stays a disabled
 * prompt. An option's `shortLabel` is what the CLOSED box shows while it is chosen: drawn over the select in an
 * `aria-hidden` span, while the select's own option — what the open list and every screen reader read — keeps the full
 * label. (Layout and `forced-colors` are CSS that happy-dom does not compute; the Storybook axe scan and the guest
 * walk cover them.)
 */
const options = [
    { value: 'include', label: 'Include. Use this indicator as it stands.', shortLabel: 'Include' },
    { value: 'drop', label: 'Drop it' },
];

describe('MdsSelect — a placeholder that can clear (M249)', () => {
    it('keeps the placeholder a disabled prompt by default', () => {
        const wrapper = mount(Select, { props: { options, placeholder: 'Choose…' } });

        const prompt = wrapper.get('option[value=""]');
        expect(prompt.text()).toBe('Choose…');
        expect(prompt.attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('lets a clearable placeholder be chosen, emptying the answer', async () => {
        const wrapper = mount(Select, { props: { options, placeholder: 'Choose…', clearable: true, modelValue: 'drop' } });

        expect(wrapper.get('option[value=""]').attributes('disabled')).toBeUndefined();
        await wrapper.get('select').setValue('');

        expect(wrapper.emitted('update:modelValue')).toEqual([['']]);
        wrapper.unmount();
    });
});

describe('MdsSelect — a short label in the closed box (M249)', () => {
    it('draws the chosen option\'s short label over the box, hidden from screen readers, and keeps the full option text', () => {
        const wrapper = mount(Select, { props: { options, modelValue: 'include' } });

        const short = wrapper.get('[data-select-short]');
        expect(short.text()).toBe('Include');
        expect(short.attributes('aria-hidden')).toBe('true');
        expect(wrapper.classes()).toContain('mds-select--short');
        expect(wrapper.get('option[value="include"]').text()).toBe('Include. Use this indicator as it stands.');
        wrapper.unmount();
    });

    it('draws nothing over the box for a choice with no short label, or with nothing chosen', async () => {
        const wrapper = mount(Select, { props: { options, modelValue: 'drop', placeholder: 'Choose…' } });
        expect(wrapper.find('[data-select-short]').exists()).toBe(false);
        expect(wrapper.classes()).not.toContain('mds-select--short');

        await wrapper.setProps({ modelValue: '' });
        expect(wrapper.find('[data-select-short]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('reads a short label from a grouped option too, and ignores a blank one', () => {
        const grouped = mount(Select, { props: { groups: [{ label: 'Keep', options }], modelValue: 'include' } });
        expect(grouped.get('[data-select-short]').text()).toBe('Include');
        grouped.unmount();

        const blank = mount(Select, { props: { options: [{ value: 'a', label: 'A long label', shortLabel: '  ' }], modelValue: 'a' } });
        expect(blank.find('[data-select-short]').exists()).toBe(false);
        blank.unmount();
    });
});
