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
        // Eleven call sites predate this prop. The default must remain a non-interactive element, or the
        // dashboard gains eleven tab stops that go nowhere.
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

    it('takes its accessible name from the value and label, adding no extra ARIA', () => {
        const wrapper = mount(StatTile, {
            props: { label: 'Submissions', value: '1,284', icon: 'submissions', href: '/submissions' },
        });

        const link = wrapper.get('a');
        expect(link.text()).toContain('Submissions');
        expect(link.text()).toContain('1,284');
        // The glyph stays decorative — a linked tile must not start announcing its icon.
        expect(wrapper.get('.mds-stat-tile__badge').attributes('aria-hidden')).toBe('true');
        expect(link.attributes('aria-label')).toBeUndefined();
        wrapper.unmount();
    });
});
