import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { markRaw } from 'vue';
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
        // The substring check above is weak on its own and the `[role="tab"]` selector is exact-match, so
        // neither would catch `role="tab presentation"`. The links must carry NO explicit role at all —
        // an anchor with an href is already a link.
        for (const link of wrapper.findAll('a')) {
            expect(link.attributes('role')).toBeUndefined();
        }

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

    it('marks aria-current ONCE even when two items share a key', () => {
        // A per-row `item.key === current` comparison renders TWO `aria-current="page"` elements here —
        // an ARIA error — and Vue emits no duplicate-key warning on initial mount, so nothing reports it.
        // Resolving the active item once by index is what makes this structurally impossible.
        // Found by the J2a adversarial review.
        const wrapper = mountNav('dup', [
            { key: 'dup', label: 'First', href: '/1' },
            { key: 'dup', label: 'Second', href: '/2' },
        ]);

        const current = wrapper.findAll('[aria-current]');
        expect(current).toHaveLength(1);
        expect(current[0].text()).toContain('First');
        expect(wrapper.findAll('.is-current')).toHaveLength(1);

        wrapper.unmount();
    });

    it('renders NOTHING at all for an empty item set', () => {
        // Reachable: the `ReducedForRole` story's own premise is that refused destinations are absent, so
        // a role permitted none would otherwise get an empty named landmark plus a stray full-width rule
        // with 20px of margin under it.
        const wrapper = mountNav('overview', []);

        expect(wrapper.find('nav').exists()).toBe(false);
        expect(wrapper.html()).toBe('<!--v-if-->');

        wrapper.unmount();
    });

    it('keeps list semantics that `list-style: none` would otherwise strip', () => {
        // Safari/VoiceOver drops list semantics from a list styled `list-style: none`. This repo already
        // knows it — `NotificationBell.vue` carries the same fix with the same comment.
        const wrapper = mountNav();

        expect(wrapper.get('ul').attributes('role')).toBe('list');

        wrapper.unmount();
    });

    it('does not make the scroll region a focus stop', () => {
        // Deliberately unlike MdsDataTable. axe's `scrollable-region-focusable` fires only on a scrollable
        // region with no focusable descendants; every item here is a link, so a tabindex would add a
        // redundant stop in front of the very links it would exist to reach.
        //
        // The role assertion is `list`, NOT absent: `role="list"` is there to survive `list-style: none`
        // (see the case above). What must never appear is MdsDataTable's `role="group"`, which exists
        // solely to name a focus stop this element does not have.
        const list = mountNav().get('.mds-tabnav__list');

        expect(list.attributes('tabindex')).toBeUndefined();
        expect(list.attributes('role')).toBe('list');
        expect(list.attributes('aria-label')).toBeUndefined();
    });

    /**
     * J2b. The strip is a form's primary navigation, so a bare anchor here means a full document load on
     * every tab switch — the persistent app shell torn down and rebuilt. The app passes Inertia's Link
     * instead, and this package still imports no router.
     *
     * `href` must arrive as a real PROP rather than a fallthrough attribute: Inertia's Link declares it, and
     * a version binding it as an attribute would render a working-looking anchor that never becomes a client
     * visit. `aria-current` must survive the substitution too — it is one of the two non-colour channels
     * marking the active item.
     */
    it('renders each item through an injected component, prop and aria-current intact', () => {
        // `markRaw`, and it is not test hygiene — it is the call-site contract. A component object handed
        // through a reactive props bag gets deep-proxied, which Vue warns about explicitly. Inertia's real
        // Link is a module-level import and therefore already raw, so the app never trips it; a stub
        // declared inline inside a mount call would, and the warning would then read as a defect in this
        // component rather than in the fixture.
        const Stub = markRaw({
            name: 'RouterLinkStub',
            props: { href: { type: String, required: true } },
            template: '<a class="stub" :href="href"><slot /></a>',
        });

        const wrapper = mount(TabNav, {
            props: { items, current: 'submissions', ariaLabel: 'Clinic Intake', linkComponent: Stub },
        });

        const stubs = wrapper.findAllComponents(Stub);

        expect(stubs).toHaveLength(3);
        expect(stubs.map((s) => s.props('href'))).toEqual([
            '/forms/abc',
            '/forms/abc/submissions',
            '/forms/abc/builder',
        ]);
        expect(wrapper.findAll('[aria-current="page"]')).toHaveLength(1);
        expect(wrapper.get('[aria-current="page"]').text()).toContain('Submissions');

        wrapper.unmount();
    });

    it('still renders plain anchors when nothing is injected — the default is load-bearing', () => {
        // Every story, every J2a call site and every other case in this file depends on it, and Storybook
        // renders these components with no router present at all.
        expect(mountNav().get('.mds-tabnav__link').element.tagName).toBe('A');
    });
});
