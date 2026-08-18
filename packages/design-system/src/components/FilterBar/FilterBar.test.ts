import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import FilterBar from './FilterBar.vue';

/**
 * Increment J1e.
 *
 * ⚠️ THIS COMPONENT EXISTS FOR ONE CONTRACT AND IT IS THE ONE axe CANNOT SEE IN CI. `heading-order` fails
 * only when the `<h1>` (PageHeader) and the `<h3>` (MdsEmptyState) are both rendered with nothing between
 * them — i.e. only when the list is EMPTY. Every e2e scan of these six pages runs against a seeded database
 * where they are not, so the one state this heading protects is the one the a11y job never visits. These
 * cases are the gate; the Storybook stories add a scan of the populated case for free.
 */
describe('MdsFilterBar — the heading contract', () => {
    it('renders an h2, unconditionally', () => {
        // Mutation: make the heading `v-if="$slots.default"` — plausible, and it reddens here while leaving
        // every seeded e2e scan green.
        const wrapper = mount(FilterBar);

        expect(wrapper.get('h2').text()).toBe('Filters');

        wrapper.unmount();
    });

    it('names the section with the heading, so the landmark is not anonymous', () => {
        const wrapper = mount(FilterBar);

        const labelledBy = wrapper.get('section').attributes('aria-labelledby');
        const headingId = wrapper.get('h2').attributes('id');

        // Resolved, not merely present. A dangling `aria-labelledby` is worse than an absent one — a screen
        // reader announces nothing for the region — and axe does not follow the reference, so nothing else
        // in this repo would catch the two drifting apart. The same reasoning as J1d's activedescendant.
        expect(labelledBy).toBeTruthy();
        expect(headingId).toBe(labelledBy);

        wrapper.unmount();
    });

    it('gives two bars on ONE page distinct heading ids', () => {
        // `useId()` rather than a fixed string. Duplicate ids are an axe failure in their own right, and
        // the second bar's `aria-labelledby` would resolve to the first bar's heading.
        //
        // ⚠️ THE TWO BARS MUST SHARE AN APP INSTANCE OR THIS CASE PROVES NOTHING. Vue's id counter is
        // per-app, so two separate `mount()` calls both yield `v-0` — a hardcoded id would pass that
        // version of the test, and a correct implementation would fail it. Mounting a host component is
        // what makes the assertion about the real page.
        const wrapper = mount(
            {
                components: { FilterBar },
                template: '<div><FilterBar /><FilterBar /></div>',
            },
            { global: { components: { FilterBar } } },
        );

        const [first, second] = wrapper.findAll('h2');

        expect(first.attributes('id')).toBeTruthy();
        expect(first.attributes('id')).not.toBe(second.attributes('id'));

        wrapper.unmount();
    });

    it('renders its controls inside the grid', () => {
        const wrapper = mount(FilterBar, { slots: { default: '<label data-test="control">Status</label>' } });

        expect(wrapper.get('.mds-filterbar__grid [data-test="control"]').text()).toBe('Status');

        wrapper.unmount();
    });
});
