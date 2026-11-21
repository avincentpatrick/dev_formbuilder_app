import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Disclosure from './Disclosure.vue';

/**
 * MdsDisclosure (M245, `R-93e22765`). axe does not resolve `aria-controls` (DSR §4's note), so the relationship is
 * asserted here: the id the toggle names must be in the document whether the panel is open or not.
 */
describe('MdsDisclosure', () => {
    it('starts closed, with a toggle that says so and names a panel that is in the document', () => {
        const wrapper = mount(Disclosure, { props: { summary: 'More about this indicator' }, slots: { default: '<p>The formula.</p>' } });
        const toggle = wrapper.get('button');
        const panelId = toggle.attributes('aria-controls');

        expect(toggle.attributes('type')).toBe('button');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.text()).toBe('More about this indicator');
        expect(panelId).toBeTruthy();
        expect(wrapper.find(`#${panelId}`).exists()).toBe(true);
        expect(wrapper.get(`#${panelId}`).attributes('style')).toContain('display: none');
    });

    it('opens on a click and closes on another, telling v-model each time', async () => {
        const wrapper = mount(Disclosure, { props: { summary: 'Research behind this' }, slots: { default: '<p>A study.</p>' } });
        const toggle = wrapper.get('button');

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.get(`#${toggle.attributes('aria-controls')}`).attributes('style') ?? '').not.toContain('display: none');
        expect(wrapper.text()).toContain('A study.');
        expect(wrapper.emitted('update:open')?.[0]).toEqual([true]);

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.emitted('update:open')?.[1]).toEqual([false]);
    });

    it('can be opened by its owner', () => {
        const wrapper = mount(Disclosure, { props: { summary: 'Open', open: true }, slots: { default: 'Inside' } });

        expect(wrapper.get('button').attributes('aria-expanded')).toBe('true');
        expect(wrapper.classes()).toContain('mds-disclosure--open');
    });

    it('gives two disclosures on one page their own panels', () => {
        const page = mount({
            components: { Disclosure },
            template: '<div><Disclosure summary="One">A</Disclosure><Disclosure summary="Two">B</Disclosure></div>',
        });
        const [first, second] = page.findAll('button').map((button) => button.attributes('aria-controls'));

        expect(first).toBeTruthy();
        expect(first).not.toBe(second);
    });
});
