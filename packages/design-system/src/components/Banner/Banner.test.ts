import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Banner from './Banner.vue';

/**
 * MdsBanner (DSR §3.8) — a persistent page-level notice.
 *
 * The accessibility contract is the substance of this component, not its colours: colour is never the only
 * channel (WCAG 1.4.1), and the live-region politeness is what separates a standing condition from an
 * interruption. Both are the kind of thing a later refactor "tidies" without noticing.
 */
describe('MdsBanner', () => {
    it('is a polite live region, not an assertive one', () => {
        // ⭐ `role="alert"` would interrupt whatever the user is reading — right for an error that just
        // occurred, wrong for a condition that was already true when the page loaded. Switch it and a
        // screen-reader user hears the banner before every page heading, forever.
        const wrapper = mount(Banner, { props: { icon: 'info', message: 'Maintenance mode is on.' } });

        expect(wrapper.attributes('role')).toBe('status');
    });

    it('carries its message as text, so colour is never the only channel', () => {
        const wrapper = mount(Banner, {
            props: { tone: 'danger', icon: 'shield', message: 'You are signed in as Ada.' },
        });

        expect(wrapper.text()).toContain('You are signed in as Ada.');
        expect(wrapper.classes()).toContain('mds-banner--danger');
    });

    it('defaults to the info tone', () => {
        const wrapper = mount(Banner, { props: { icon: 'info', message: 'Heads up.' } });

        expect(wrapper.classes()).toContain('mds-banner--info');
    });

    it('renders the action slot only when one is given', () => {
        const without = mount(Banner, { props: { icon: 'info', message: 'No action here.' } });
        expect(without.find('.mds-banner__action').exists()).toBe(false);

        const withAction = mount(Banner, {
            props: { icon: 'info', message: 'Do something.' },
            slots: { action: '<button type="button">Exit</button>' },
        });
        expect(withAction.find('.mds-banner__action button').text()).toBe('Exit');
    });

    it('hides the icon from assistive tech, because the message already says it', () => {
        // A decorative glyph announced beside the text is noise; the tone it signals is carried by words.
        const wrapper = mount(Banner, { props: { tone: 'warning', icon: 'alert', message: 'Quota is low.' } });

        expect(wrapper.find('.mds-banner__icon').attributes('aria-hidden')).toBe('true');
    });
});
