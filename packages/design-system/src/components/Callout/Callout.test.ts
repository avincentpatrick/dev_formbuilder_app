import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Callout from './Callout.vue';

/**
 * MdsCallout (M130). The contract that separates it from MdsAlert is what it does NOT do: it is a note, read in
 * order, never a live region that announces itself when it appears.
 */
describe('MdsCallout', () => {
    it('is a note, never a live region', () => {
        const wrapper = mount(Callout, { slots: { default: 'Bring your health card.' } });

        expect(wrapper.attributes('role')).toBe('note');
        expect(wrapper.attributes('aria-live')).toBeUndefined();
    });

    it('renders its content and its tone, with an icon as the non-colour channel', () => {
        const wrapper = mount(Callout, { props: { tone: 'warning' }, slots: { default: 'Fasting is required.' } });

        expect(wrapper.text()).toContain('Fasting is required.');
        expect(wrapper.classes()).toContain('mds-callout--warning');
        expect(wrapper.find('.mds-callout__icon').exists()).toBe(true);
    });

    it('defaults to the info tone', () => {
        expect(mount(Callout, { slots: { default: 'x' } }).classes()).toContain('mds-callout--info');
    });
});
