import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import BarChart from './BarChart.vue';
import type { BarDatum } from '../../charts/types';

/**
 * Same contract, same reason as the time-series suite: ADR-0011 §D12 puts the chart's text alternative
 * on Vitest rather than on the axe gate, because axe cannot tell a summarising `aria-label` from a
 * decorative one and will pass a picture nobody can read.
 */

const forms: BarDatum[] = [
    { key: 'a', label: 'Clinic Intake', value: 42 },
    { key: 'b', label: 'Household Roster', value: 18 },
    { key: 'c', label: 'Other (3 categories)', value: 9, neutral: true },
];

describe('MdsBarChart — the text alternative (ADR-0011 §D12)', () => {
    it('ships a real data table beside the bars', () => {
        const wrapper = mount(BarChart, { props: { data: forms, title: 'Top forms', valueLabel: 'Responses' } });

        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(3);
        expect(rows[0].find('th').text()).toBe('Clinic Intake');
        expect(rows[0].find('td').text()).toBe('42');
        expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Category', 'Responses']);
        expect(wrapper.find('caption').text()).toBe('Top forms');
        wrapper.unmount();
    });

    it('summarises the total and the largest category behind role="img"', () => {
        const wrapper = mount(BarChart, { props: { data: forms, title: 'Top forms' } });

        const plot = wrapper.find('.mds-bar__plot');
        expect(plot.attributes('role')).toBe('img');
        const label = plot.attributes('aria-label') ?? '';
        expect(label).toContain('3 categories, 69 in total');
        expect(label).toContain('Largest: Clinic Intake, 42');
        wrapper.unmount();
    });

    it('puts role="img" on the group, not on each track, so tracks are not announced as images', () => {
        const wrapper = mount(BarChart, { props: { data: forms, title: 'Top forms' } });

        for (const track of wrapper.findAll('svg.mds-bar__track')) {
            expect(track.attributes('aria-hidden')).toBe('true');
            expect(track.attributes('role')).toBeUndefined();
        }
        wrapper.unmount();
    });

    it('drops role="img" rather than labelling an empty picture', () => {
        const wrapper = mount(BarChart, { props: { data: [], title: 'Top forms' } });

        expect(wrapper.find('.mds-bar__plot').attributes('role')).toBeUndefined();
        expect(wrapper.findAll('tbody tr')).toHaveLength(0);
        wrapper.unmount();
    });

    it('hides the table visually by default and reveals it on request', () => {
        const hidden = mount(BarChart, { props: { data: forms, title: 'Top forms' } });
        expect(hidden.find('.mds-bar__table-wrap').classes()).toContain('mds-bar__table-wrap--sr');
        hidden.unmount();

        const shown = mount(BarChart, { props: { data: forms, title: 'Top forms', tableVisible: true } });
        expect(shown.find('.mds-bar__table-wrap').classes()).not.toContain('mds-bar__table-wrap--sr');
        shown.unmount();
    });
});

describe('MdsBarChart — geometry and colour', () => {
    it('scales bars against the largest value', () => {
        const wrapper = mount(BarChart, { props: { data: forms, title: 'Top forms' } });

        expect(wrapper.findAll('.mds-bar__fill').map((r) => r.attributes('width'))).toEqual([
            '100',
            '42.86',
            '21.43',
        ]);
        wrapper.unmount();
    });

    it('survives an all-zero breakdown instead of emitting width="NaN"', () => {
        // A quiet tenant's ordinary state, not an exotic one: 0/0 renders no bar and raises nothing.
        const wrapper = mount(BarChart, {
            props: { data: [{ key: 'a', label: 'Clinic Intake', value: 0 }], title: 'Top forms' },
        });

        expect(wrapper.find('.mds-bar__fill').attributes('width')).toBe('0');
        wrapper.unmount();
    });

    it('paints the aggregated bucket a NEUTRAL so it never reads as a peer category (§D11)', () => {
        const wrapper = mount(BarChart, { props: { data: forms, title: 'Top forms' } });

        const classes = wrapper.findAll('.mds-bar__fill').map((r) => r.classes());
        expect(classes[0]).toContain('mds-bar__fill--series');
        expect(classes[1]).toContain('mds-bar__fill--series');
        expect(classes[2]).toContain('mds-bar__fill--other');
        wrapper.unmount();
    });

    it('uses ONE fill for every real category — a single-series chart needs no palette (§D12)', () => {
        const many: BarDatum[] = Array.from({ length: 7 }, (_, i) => ({
            key: `k${i}`,
            label: `Form ${i}`,
            value: 10 - i,
        }));
        const wrapper = mount(BarChart, { props: { data: many, title: 'Top forms' } });

        const series = wrapper.findAll('.mds-bar__fill--series');
        expect(series).toHaveLength(7);
        // Categories are told apart by their visible labels, so the five-token cap is never strained.
        expect(wrapper.findAll('.mds-bar__label').map((s) => s.text())).toEqual(many.map((d) => d.label));
        wrapper.unmount();
    });

    it('keeps the category label as HTML, so a long form title wraps instead of overflowing', () => {
        const wrapper = mount(BarChart, {
            props: {
                data: [{ key: 'a', label: 'Community Health Survey — Q3 follow-up round', value: 1 }],
                title: 'Top forms',
            },
        });

        // SVG <text> has no wrapping and no ellipsis; this is why the label is a <span>.
        expect(wrapper.find('.mds-bar__label').element.tagName).toBe('SPAN');
        expect(wrapper.find('svg.mds-bar__track').findAll('text')).toHaveLength(0);
        wrapper.unmount();
    });
});
