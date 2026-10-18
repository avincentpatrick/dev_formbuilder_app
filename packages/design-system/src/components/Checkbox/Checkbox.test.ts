import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Checkbox from './Checkbox.vue';

/**
 * M177 (`R-4a6f004f`, `D146`): `hideLabel` — a tick box whose row already names what it selects (the builder's Structure
 * list) keeps its label as the accessible name, read by a screen reader, and draws no visible text beside the box.
 */
describe('MdsCheckbox — hideLabel (M177)', () => {
    it('keeps the label in the DOM as the accessible name, visually hidden', () => {
        const wrapper = mount(Checkbox, { props: { label: 'Select Full name', hideLabel: true } });

        const label = wrapper.get('.mds-checkbox__label');
        expect(label.text()).toBe('Select Full name');
        expect(label.classes()).toContain('mds-checkbox__label--hidden');
        expect(wrapper.find('label').text()).toContain('Select Full name');
        wrapper.unmount();
    });

    it('shows the label as before when hideLabel is not set', () => {
        const wrapper = mount(Checkbox, { props: { label: 'Remember this device' } });

        expect(wrapper.get('.mds-checkbox__label').classes()).not.toContain('mds-checkbox__label--hidden');
        wrapper.unmount();
    });
});
