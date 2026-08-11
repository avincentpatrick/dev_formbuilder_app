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

    it('still renders a numeric zero now that `value` is optional', () => {
        // `value ?? '—'`, never `value || '—'`. The dash fallback added for the `unavailable` state is
        // one character away from turning every real zero on the dashboard into "no data".
        const wrapper = mount(StatTile, { props: { label: 'Forms', value: 0, icon: 'forms' } });

        expect(wrapper.find('.mds-stat-tile__value').text()).toBe('0');
        wrapper.unmount();
    });
});

/**
 * H24b1 — the three states ADR-0011 added, each of which exists to stop the tile stating something the
 * data does not support.
 */
describe('MdsStatTile — honest states (ADR-0011 §D5)', () => {
    it('renders a null delta as an em dash, never as +100%', () => {
        // `AnalyticsMetricsService::total()` returns `change === null` when the PRIOR period held no
        // rows — the change is undefined, not a rise from zero. A tile that printed "+100%" there would
        // be inventing a denominator.
        const wrapper = mount(StatTile, {
            props: {
                label: 'Submissions',
                value: 12,
                icon: 'submissions',
                delta: null,
                deltaLabel: 'vs. previous 30 days',
            },
        });

        const delta = wrapper.find('.mds-stat-tile__delta');
        expect(delta.exists()).toBe(true);
        expect(delta.text()).toContain('—');
        expect(delta.text()).not.toContain('%');
        expect(delta.text()).toContain('vs. previous 30 days');
        wrapper.unmount();
    });

    it('signs the delta and pairs every direction with an arrow, so colour is never the only channel', () => {
        const up = mount(StatTile, {
            props: { label: 'Submissions', value: 12, icon: 'submissions', delta: 8.5, deltaLabel: 'vs. prior' },
        });
        expect(up.find('.mds-stat-tile__delta').text()).toContain('+8.5%');
        expect(up.find('.mds-badge').classes()).toContain('mds-badge--success');
        expect(up.findAll('svg.mds-icon').length).toBe(2); // the tile badge glyph + the direction arrow
        up.unmount();

        const down = mount(StatTile, {
            props: { label: 'Submissions', value: 12, icon: 'submissions', delta: -8.5, deltaLabel: 'vs. prior' },
        });
        expect(down.find('.mds-stat-tile__delta').text()).toContain('-8.5%');
        expect(down.find('.mds-badge').classes()).toContain('mds-badge--danger');
        expect(down.findAll('svg.mds-icon').length).toBe(2);
        down.unmount();
    });

    it('treats an exactly-flat delta as neutral, with no arrow to imply a direction', () => {
        const wrapper = mount(StatTile, {
            props: { label: 'Submissions', value: 12, icon: 'submissions', delta: 0, deltaLabel: 'vs. prior' },
        });

        expect(wrapper.find('.mds-badge').classes()).toContain('mds-badge--neutral');
        expect(wrapper.findAll('svg.mds-icon').length).toBe(1);
        wrapper.unmount();
    });

    it('says a suppressed metric is unavailable rather than rendering it as zero', () => {
        // §D5's hazard, concretely: drafts are hard-deleted at expiry, so a conversion rate over a period
        // reaching past the retention window has lost its own denominator. Rendering that as "0%" is a
        // wrong number presented as a right one.
        const wrapper = mount(StatTile, {
            props: {
                label: 'Draft conversion',
                value: 0,
                icon: 'activity',
                unavailable: true,
                unavailableNote: 'Not available for periods older than the 30-day draft retention window.',
                delta: 0,
                deltaLabel: 'vs. prior',
            },
        });

        expect(wrapper.find('.mds-stat-tile__value').text()).toBe('—');
        expect(wrapper.text()).toContain('draft retention window');
        // A suppressed metric has no comparison either — a delta beside an em dash implies a number.
        expect(wrapper.find('.mds-stat-tile__delta').exists()).toBe(false);
        wrapper.unmount();
    });

    it('carries a denominator caption, which §D5 requires on the face of the tile', () => {
        const wrapper = mount(StatTile, {
            props: { label: 'Draft conversion', value: '62%', icon: 'activity', caption: 'of 48 saved drafts' },
        });

        expect(wrapper.find('.mds-stat-tile__caption').text()).toBe('of 48 saved drafts');
        wrapper.unmount();
    });

    it('omits the delta row entirely when no comparison was supplied', () => {
        const wrapper = mount(StatTile, { props: { label: 'Forms', value: 3, icon: 'forms' } });

        expect(wrapper.find('.mds-stat-tile__delta').exists()).toBe(false);
        expect(wrapper.find('.mds-stat-tile__caption').exists()).toBe(false);
        wrapper.unmount();
    });
});

/** Increment J2a — every tile on the dashboard named something and led nowhere. */
describe('MdsStatTile — the optional link', () => {
    it('stays a plain container when no href is supplied', () => {
        // SEVENTEEN call sites predate this prop (counted, across seven files). The default must remain a
        // non-interactive element, or the app gains 17 tab stops that go nowhere.
        const wrapper = mount(StatTile, { props: { label: 'Forms', value: 3, icon: 'forms' } });

        expect(wrapper.element.tagName).toBe('DIV');
        expect(wrapper.attributes('href')).toBeUndefined();
        expect(wrapper.classes()).not.toContain('mds-stat-tile--link');
        wrapper.unmount();
    });

    it('becomes a real anchor when one is', () => {
        // An anchor, not a div with a click handler: the latter has no role, no accessible name, no
        // keyboard route and no default hover affordance, and would fail the axe job that gates this
        // package while looking identical in a screenshot.
        const wrapper = mount(StatTile, {
            props: { label: 'Forms', value: 3, icon: 'forms', href: '/forms' },
        });

        expect(wrapper.element.tagName).toBe('A');
        expect(wrapper.attributes('href')).toBe('/forms');
        expect(wrapper.classes()).toContain('mds-stat-tile--link');
        wrapper.unmount();
    });

    it('stays a plain container for an EMPTY href, and grows no bogus attribute', () => {
        // `:href="row.can.view ? url : ''"` is the obvious call-site shape, and ADR-0011 §D9's
        // absent-not-locked doctrine pushes callers toward exactly that ternary. An unguarded `:href`
        // bound `href=""` onto the DIV — invalid HTML, not a link, and no `--link` class, so it looked
        // exactly like the inert tile it used to be. Found by the J2a adversarial review.
        const wrapper = mount(StatTile, { props: { label: 'Forms', value: 3, icon: 'forms', href: '' } });

        expect(wrapper.element.tagName).toBe('DIV');
        expect(wrapper.attributes('href')).toBeUndefined();
        wrapper.unmount();
    });

    it('puts the tile\'s WHOLE text inside the link, asserted exactly so it cannot drift', () => {
        // ⚠️ The first version of this case asserted `toContain('Submissions')` under the title "takes its
        // accessible name from the value and label" — which is true of literally any implementation that
        // renders the props, including the one shipped, whose name also carries the delta and the caption.
        // A test that cannot fail its own title is worse than no test.
        //
        // ⚠️ AND THIS ASSERTS `textContent`, NOT THE ACCESSIBLE NAME, which is a real distinction rather
        // than pedantry: neither happy-dom nor vue-test-utils computes an accessible name, and a browser
        // inserts separators between block-level descendants that the raw concatenation below does not
        // have ("1,284Submissions" here reaches a screen reader as "1,284 Submissions"). What this case
        // actually pins is the SET of content inside the anchor — which is what the name is computed from,
        // and the thing that silently grows when a prop is added.
        const wrapper = mount(StatTile, {
            props: {
                label: 'Submissions',
                value: '1,284',
                icon: 'submissions',
                href: '/submissions',
                delta: 12.4,
                deltaLabel: 'vs. previous 30 days',
                caption: 'of 48 saved drafts',
            },
        });

        const link = wrapper.get('a');
        expect(link.text().replace(/\s+/g, ' ').trim()).toBe(
            '1,284Submissions +12.4%vs. previous 30 daysof 48 saved drafts',
        );
        // The glyph stays decorative — a linked tile must not start announcing its icon.
        expect(wrapper.get('.mds-stat-tile__badge').attributes('aria-hidden')).toBe('true');
        // No aria-label: one would REPLACE the name above and hide the delta from screen-reader users
        // while sighted readers keep seeing it.
        expect(link.attributes('aria-label')).toBeUndefined();
        wrapper.unmount();
    });
});
