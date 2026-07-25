import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import StatTile from './StatTile.vue';

describe('MdsStatTile', () => {
    it('renders the label and value', () => {
        const wrapper = mount(StatTile, {
            props: { label: 'Submissions', value: '1,284', icon: 'submissions' },
        });

        expect(wrapper.text()).toContain('Submissions');
        expect(wrapper.text()).toContain('1,284');
        wrapper.unmount();
    });

    it('renders a numeric zero (not a blank tile) and a decorative icon', () => {
        const wrapper = mount(StatTile, {
            props: { label: 'Forms', value: 0, icon: 'forms' },
        });

        expect(wrapper.text()).toContain('0');
        expect(wrapper.find('svg.mds-icon').exists()).toBe(true);
        // The icon is decorative — hidden from the a11y tree; the value/label carry the meaning.
        expect(wrapper.find('.mds-stat-tile__badge').attributes('aria-hidden')).toBe('true');
        wrapper.unmount();
    });
});
