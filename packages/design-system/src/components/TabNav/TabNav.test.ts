import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TabNav from './TabNav.vue';
import type { TabNavItem } from './TabNav.vue';

/**
 * Increment J2a.
 *
 * ⚠️ THE CASES THAT MATTER MOST HERE ARE THE ONES ASSERTING AN ABSENCE, because the defect this component
 * exists to prevent PASSES EVERY AUTOMATED GATE. A version of this built on role tablist / tab with a roving
 * tabindex is valid ARIA — axe checks that a tab lives in a tablist, not that the thing being switched is a
 * panel rather than a page — so the Storybook a11y job, vue-tsc and the e2e scans would all stay green over
 * a strip that announces "tab, 2 of 5" and then replaces the document, and whose non-active destinations
 * have been removed from the tab sequence entirely. Nothing but a test that names the absence catches it.
 */

const items: TabNavItem[] = [
    { key: 'overview', label: 'Overview', href: '/forms/abc' },
    { key: 'submissions', label: 'Submissions', href: '/forms/abc/submissions', badge: 12 },
    { key: 'builder', label: 'Builder', href: '/forms/abc/builder' },
];

function mountNav(current = 'overview', overrides: TabNavItem[] = items) {
    return mount(TabNav, { props: { items: overrides, current, ariaLabel: 'Clinic Intake' } });
}

describe('MdsTabNav — navigation, not the ARIA tabs widget', () => {
    it('is a navigation landmark named for the resource', () => {
        const wrapper = mountNav();

        // Named after the FORM, not "Tabs": a hub page also renders the shell's own nav landmarks, and
        // axe's `landmark-unique` tells them apart by accessible name alone.
        expect(wrapper.get('nav').attributes('aria-label')).toBe('Clinic Intake');

        wrapper.unmount();
    });

    it('renders every item as a real link carrying its href', () => {
        const wrapper = mountNav();
        const links = wrapper.findAll('a');

        expect(links).toHaveLength(3);
        expect(links.map((l) => l.attributes('href'))).toEqual([
            '/forms/abc',
            '/forms/abc/submissions',
            '/forms/abc/builder',
        ]);

        wrapper.unmount();
    });

    it('uses NO tab/tablist role anywhere — the whole point of the component', () => {
        // Mutation: swap the nav for role="tablist" and the links for role="tab". Reddens here and nowhere
        // else in the repo. See this file's header for why every other gate would stay green.
        const wrapper = mountNav();

        expect(wrapper.html()).not.toContain('tablist');
        expect(wrapper.find('[role="tab"]').exists()).toBe(false);
        expect(wrapper.find('[aria-selected]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('leaves every destination in the tab sequence — no roving tabindex', () => {
        // The concrete harm of the tabs pattern applied to links: a roving tabindex marks the inactive
        // items `tabindex="-1"`, so a keyboard user can reach exactly one of the destinations by Tab and has
        // to guess that arrow keys now move between things that look like ordinary links.
        const wrapper = mountNav();

        for (const link of wrapper.findAll('a')) {
            expect(link.attributes('tabindex')).toBeUndefined();
        }

        wrapper.unmount();
    });

    it('marks exactly one item aria-current="page"', () => {
        const wrapper = mountNav('submissions');
        const current = wrapper.findAll('[aria-current]');

        expect(current).toHaveLength(1);
        expect(current[0].attributes('aria-current')).toBe('page');
        expect(current[0].text()).toContain('Submissions');

        wrapper.unmount();
    });

    it('marks nothing current when `current` names no item, rather than defaulting to the first', () => {
        // A hub route that gains a page before it gains a tab key would otherwise light up Overview while
        // the reader is somewhere else — a wrong answer is worse than no answer here.
        const wrapper = mountNav('nonexistent');

        expect(wrapper.findAll('[aria-current]')).toHaveLength(0);

        wrapper.unmount();
    });

    it('renders a badge of ZERO, which a truthiness check would silently drop', () => {
        // The MdsStatTile `??`-not-`||` lesson in a second component: "0 responses" is a real, useful answer
        // and it is exactly the number a brand-new form shows. `v-if="item.badge"` passes every other case
        // in this file.
        const wrapper = mountNav('overview', [{ key: 'a', label: 'Responses', href: '/a', badge: 0 }]);

        expect(wrapper.get('a').text()).toContain('0');

        wrapper.unmount();
    });

    it('renders no badge when the item declares none', () => {
        const wrapper = mountNav('overview', [{ key: 'a', label: 'Overview', href: '/a' }]);

        expect(wrapper.find('.mds-badge').exists()).toBe(false);

        wrapper.unmount();
    });

    it('does not make the scroll region a focus stop', () => {
        // Deliberately unlike MdsDataTable. axe's `scrollable-region-focusable` fires only on a scrollable
        // region with no focusable descendants; every item here is a link, so a tabindex would add a
        // redundant stop in front of the very links it would exist to reach.
        const wrapper = mountNav();

        expect(wrapper.get('.mds-tabnav__list').attributes('tabindex')).toBeUndefined();
        expect(wrapper.get('.mds-tabnav__list').attributes('role')).toBeUndefined();

        wrapper.unmount();
    });
});
