import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { markRaw } from 'vue';
import Breadcrumb from './Breadcrumb.vue';
import type { BreadcrumbItem } from './Breadcrumb.vue';

/**
 * Increment J2a.
 *
 * The contract worth testing is the LAST crumb, in both directions: it must be text even when the caller
 * supplies an href, and it must carry `aria-current`. axe sees neither — a trailing self-link is valid HTML
 * with a valid destination, and a trail with no `aria-current` anywhere passes every rule in the ruleset.
 */

const trail: BreadcrumbItem[] = [
    { label: 'Forms', href: '/forms' },
    { label: 'Clinic Intake', href: '/forms/abc' },
    { label: 'Responses', href: '/forms/abc/submissions' },
];

describe('MdsBreadcrumb', () => {
    it('is a navigation landmark named Breadcrumb by default', () => {
        const wrapper = mount(Breadcrumb, { props: { items: trail } });

        expect(wrapper.get('nav').attributes('aria-label')).toBe('Breadcrumb');

        wrapper.unmount();
    });

    it('renders the last crumb as TEXT even though it was given an href', () => {
        // Mutation: decide link-ness from `item.href` alone. Every crumb in this fixture has one, so that
        // version renders a self-link at the tail — valid HTML, a real destination, and invisible to axe.
        const wrapper = mount(Breadcrumb, { props: { items: trail } });

        const links = wrapper.findAll('a');

        expect(links).toHaveLength(2);
        expect(links.map((l) => l.text())).toEqual(['Forms', 'Clinic Intake']);
        expect(wrapper.get('[aria-current="page"]').element.tagName).toBe('SPAN');
        expect(wrapper.get('[aria-current="page"]').text()).toBe('Responses');

        wrapper.unmount();
    });

    it('marks exactly one crumb aria-current, and it is the last', () => {
        const wrapper = mount(Breadcrumb, { props: { items: trail } });

        expect(wrapper.findAll('[aria-current]')).toHaveLength(1);

        wrapper.unmount();
    });

    it('renders a lone crumb as the current page, not as a link', () => {
        const wrapper = mount(Breadcrumb, { props: { items: [{ label: 'Forms', href: '/forms' }] } });

        expect(wrapper.findAll('a')).toHaveLength(0);
        expect(wrapper.get('[aria-current="page"]').text()).toBe('Forms');

        wrapper.unmount();
    });

    it('renders a mid-trail crumb without an href as plain text and NOT as current', () => {
        // A grouping label with no page of its own ("Settings" above "Branding"). It must not steal
        // `aria-current` from the tail — two currents is the same defect as none.
        const wrapper = mount(Breadcrumb, {
            props: {
                items: [
                    { label: 'Forms', href: '/forms' },
                    { label: 'Archived' },
                    { label: 'Clinic Intake' },
                ],
            },
        });

        const currents = wrapper.findAll('[aria-current]');

        expect(currents).toHaveLength(1);
        expect(currents[0].text()).toBe('Clinic Intake');

        wrapper.unmount();
    });

    it('hides every separator from assistive technology', () => {
        // Read out, a trail becomes "Forms slash Clinic Intake slash Responses". The separator is
        // decoration; the list structure is what carries the relationship.
        const wrapper = mount(Breadcrumb, { props: { items: trail } });

        const seps = wrapper.findAll('.mds-breadcrumb__sep');

        expect(seps).toHaveLength(2); // n-1, never a trailing one
        for (const sep of seps) {
            expect(sep.attributes('aria-hidden')).toBe('true');
        }

        wrapper.unmount();
    });

    it('keeps list semantics that `list-style: none` would otherwise strip', () => {
        // Load-bearing here rather than merely correct: the separators are `aria-hidden` BECAUSE the list
        // structure conveys the relationship, so a Safari/VoiceOver reader who loses that structure gets
        // an unpunctuated run of words. Found by the J2a adversarial review.
        const wrapper = mount(Breadcrumb, { props: { items: trail } });

        expect(wrapper.get('ol').attributes('role')).toBe('list');

        wrapper.unmount();
    });

    it('renders NOTHING at all for an empty trail, rather than an empty named landmark', () => {
        const wrapper = mount(Breadcrumb, { props: { items: [] } });

        expect(wrapper.find('nav').exists()).toBe(false);

        wrapper.unmount();
    });

    it('accepts an override name so two trails on one page are distinguishable', () => {
        const wrapper = mount(Breadcrumb, { props: { items: trail, ariaLabel: 'Form location' } });

        expect(wrapper.get('nav').attributes('aria-label')).toBe('Form location');

        wrapper.unmount();
    });

    /**
     * J2b. The app passes Inertia's Link so a crumb is a client visit rather than a document load; this
     * package must never import a router to make that possible, so the element is injected.
     *
     * Two directions matter, and the second is the one a naive implementation gets wrong: the substituted
     * component must receive `href` as a real PROP (Inertia's Link declares it — bound as a plain attribute
     * it would land in `$attrs` and the visit would never happen), and it must NOT reach the last crumb,
     * which is not a link at all. Routing every crumb through it would resurrect the self-link this
     * component's whole contract refuses.
     */
    it('renders link crumbs through an injected component, and never the current one', () => {
        // `markRaw` for the reason TabNav.test.ts records: a component object handed through a reactive
        // props bag gets deep-proxied and Vue warns. Inertia's Link is a module-level import and already
        // raw, so the real call site never trips it.
        const Stub = markRaw({
            name: 'RouterLinkStub',
            props: { href: { type: String, required: true } },
            template: '<a class="stub" :href="href"><slot /></a>',
        });

        const wrapper = mount(Breadcrumb, { props: { items: trail, linkComponent: Stub } });

        const stubs = wrapper.findAllComponents(Stub);

        expect(stubs).toHaveLength(2);
        expect(stubs.map((s) => s.props('href'))).toEqual(['/forms', '/forms/abc']);
        expect(wrapper.get('[aria-current="page"]').element.tagName).toBe('SPAN');

        wrapper.unmount();
    });

    it('still renders plain anchors when nothing is injected — the default is load-bearing', () => {
        // Every story, every other case in this file and every J2a call site depends on it. A default that
        // drifted to a router component would break Storybook, which renders with no router present.
        const wrapper = mount(Breadcrumb, { props: { items: trail } });

        expect(wrapper.get('.mds-breadcrumb__link').element.tagName).toBe('A');

        wrapper.unmount();
    });
});
