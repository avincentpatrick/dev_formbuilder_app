import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TimeSeriesChart from './TimeSeriesChart.vue';
import type { ChartSeries } from '../../charts/types';

/**
 * ADR-0011 §D12 makes this suite — not the merge-blocking axe job — the gate on the chart's text
 * alternative, and says why: **axe cannot detect a MISSING text alternative for an SVG that carries a
 * plausible `aria-label`.** A chart with a confident label and no data table passes `design-system-a11y`
 * and is still an unreadable picture. So the table is asserted here, per primitive, by hand.
 *
 * happy-dom lays nothing out and resolves no `var()`, so nothing below reads a colour or a measured box.
 * Everything asserted is either DOM structure or a `d` string this repo's own arithmetic produced.
 */

const days = (values: number[]): ChartSeries[] => [
    {
        key: 'responses',
        label: 'Responses',
        points: values.map((value, i) => ({ label: `Mar ${i + 1}`, value })),
    },
];

describe('MdsTimeSeriesChart — the text alternative (ADR-0011 §D12)', () => {
    it('ships a real data table of every plotted point, not only an aria-label', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: days([1, 4, 2]), title: 'Responses per day', valueLabel: 'Responses' },
        });

        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(3);
        expect(rows[1].find('th').text()).toBe('Mar 2');
        expect(rows[1].find('td').text()).toBe('4');
        expect(wrapper.find('caption').text()).toBe('Responses per day');
        expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Period', 'Responses']);
        wrapper.unmount();
    });

    it('gives every row a scope so a screen reader can pair a value with its period', () => {
        const wrapper = mount(TimeSeriesChart, { props: { series: days([1, 2]), title: 'Responses' } });

        expect(wrapper.find('thead th').attributes('scope')).toBe('col');
        expect(wrapper.find('tbody th').attributes('scope')).toBe('row');
        wrapper.unmount();
    });

    it('hides the table visually by default and reveals it on request', () => {
        const hidden = mount(TimeSeriesChart, { props: { series: days([1, 2]), title: 'Responses' } });
        expect(hidden.find('.mds-tsc__table-wrap').classes()).toContain('mds-tsc__table-wrap--sr');
        hidden.unmount();

        const shown = mount(TimeSeriesChart, {
            props: { series: days([1, 2]), title: 'Responses', tableVisible: true },
        });
        expect(shown.find('.mds-tsc__table-wrap').classes()).not.toContain('mds-tsc__table-wrap--sr');
        shown.unmount();
    });

    it('summarises the plot behind role="img", including the span and the extremes', () => {
        const wrapper = mount(TimeSeriesChart, { props: { series: days([1, 9, 3]), title: 'Responses' } });

        const svg = wrapper.find('svg.mds-tsc__canvas');
        expect(svg.attributes('role')).toBe('img');
        const label = svg.attributes('aria-label') ?? '';
        expect(label).toContain('Responses');
        expect(label).toContain('3 points from Mar 1 to Mar 3');
        // The DATA's extremes, not the padded axis domain. The domain is widened to include zero so the
        // plot cannot be truncated; saying "from 0" would report a padding decision as an observation.
        expect(label).toContain('Values from 1 to 9');
        wrapper.unmount();
    });

    it('names every series in the summary when there is more than one', () => {
        const series: ChartSeries[] = [
            { key: 'a', label: 'Guest', points: [{ label: 'Mar 1', value: 2 }] },
            { key: 'b', label: 'Manual', points: [{ label: 'Mar 1', value: 5 }] },
        ];
        const wrapper = mount(TimeSeriesChart, { props: { series, title: 'By channel' } });

        expect(wrapper.find('svg.mds-tsc__canvas').attributes('aria-label')).toContain('2 series: Guest, Manual');
        wrapper.unmount();
    });

    it('drops role="img" rather than labelling an empty picture', () => {
        const wrapper = mount(TimeSeriesChart, { props: { series: [], title: 'Responses' } });

        const svg = wrapper.find('svg.mds-tsc__canvas');
        expect(svg.attributes('role')).toBeUndefined();
        expect(svg.attributes('aria-hidden')).toBe('true');
        expect(wrapper.findAll('tbody tr')).toHaveLength(0);
        wrapper.unmount();
    });

    it('keeps the axis labels out of the accessibility tree — the table is the alternative', () => {
        const wrapper = mount(TimeSeriesChart, { props: { series: days([1, 2, 3]), title: 'Responses' } });

        expect(wrapper.find('.mds-tsc__y').attributes('aria-hidden')).toBe('true');
        expect(wrapper.find('.mds-tsc__x').attributes('aria-hidden')).toBe('true');
        wrapper.unmount();
    });
});

describe('MdsTimeSeriesChart — geometry', () => {
    it('plots an all-zero series flat on the baseline instead of emitting NaN', () => {
        // The ordinary state of a quiet 30-day window: every bucket is 0, so the value span is 0 and
        // every consumer divides by it. Without niceTicks' degenerate-domain guard the path reads
        // "M0 NaN L50 NaN L100 NaN" — which draws nothing, throws nothing, and fails no gate.
        const wrapper = mount(TimeSeriesChart, { props: { series: days([0, 0, 0]), title: 'Responses' } });

        const d = wrapper.find('.mds-tsc__line').attributes('d') ?? '';
        expect(d).not.toContain('NaN');
        expect(d).toBe('M0 100 L50 100 L100 100');
        wrapper.unmount();
    });

    it('always includes zero in the value domain, so the axis cannot be truncated', () => {
        // A truncated axis turns a 400→410 wobble into a cliff. Callers cannot switch this off.
        const wrapper = mount(TimeSeriesChart, { props: { series: days([400, 410]), title: 'Responses' } });

        expect(wrapper.find('.mds-tsc__y').text()).toContain('0');
        wrapper.unmount();
    });

    it('renders a single point as a dot, never as a full-width line claiming a constant', () => {
        const wrapper = mount(TimeSeriesChart, { props: { series: days([7]), title: 'Responses' } });

        // Zero-length subpath + stroke-linecap="round" paints a dot; a circle would render as an
        // ellipse under preserveAspectRatio="none". x is the plot's midpoint, not its left edge; y is
        // 7 on the zero-anchored [0, 8] domain, i.e. 12.5% down — NOT the top, which is what it would
        // be if the domain were fitted to the single value.
        expect(wrapper.find('.mds-tsc__line').attributes('d')).toBe('M50 12.5');
        expect(wrapper.find('.mds-tsc__line').attributes('stroke-linecap')).toBe('round');
        wrapper.unmount();
    });

    it('keeps every stroked mark non-scaling, because the canvas is stretched to fit', () => {
        const wrapper = mount(TimeSeriesChart, { props: { series: days([1, 2, 3]), title: 'Responses' } });

        expect(wrapper.find('svg.mds-tsc__canvas').attributes('preserveAspectRatio')).toBe('none');
        for (const path of wrapper.findAll('.mds-tsc__line, .mds-tsc__grid line, .mds-tsc__axis')) {
            expect(path.attributes('vector-effect')).toBe('non-scaling-stroke');
        }
        wrapper.unmount();
    });

    it('closes the area variant down to the zero baseline', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: days([0, 10]), title: 'Responses', variant: 'area' },
        });

        expect(wrapper.find('.mds-tsc__area').attributes('d')).toBe('M0 100 L100 0 L100 100 L0 100 Z');
        wrapper.unmount();
    });

    it('thins a 30-day x-axis to at most maxTicks labels, keeping both ends', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: days(Array.from({ length: 30 }, (_, i) => i)), title: 'Responses' },
        });

        const labels = wrapper.findAll('.mds-tsc__x span').map((s) => s.text());
        expect(labels.length).toBeLessThanOrEqual(6);
        expect(labels[0]).toBe('Mar 1');
        expect(labels[labels.length - 1]).toBe('Mar 30');
        wrapper.unmount();
    });
});

describe('MdsTimeSeriesChart — the five-series cap (ADR-0011 §D11)', () => {
    const six: ChartSeries[] = Array.from({ length: 6 }, (_, i) => ({
        key: `s${i}`,
        label: `Series ${i + 1}`,
        points: [{ label: 'Mar 1', value: i + 1 }],
    }));

    it('plots five and TABULATES all six — nothing is hidden, only un-plotted', () => {
        // §D11's own wording. Dropping the sixth from both the plot and the table would be the silent
        // truncation that rule exists to prevent; the caller is the one that decides to fold extras
        // into an "Other" bucket.
        const wrapper = mount(TimeSeriesChart, { props: { series: six, title: 'By form' } });

        expect(wrapper.findAll('.mds-tsc__line')).toHaveLength(5);
        expect(wrapper.findAll('thead th')).toHaveLength(7); // the category column + six series
        expect(wrapper.find('tbody tr').findAll('td')).toHaveLength(6);
        wrapper.unmount();
    });

    it('assigns one static series class per index, never an interpolated token name', () => {
        const wrapper = mount(TimeSeriesChart, { props: { series: six, title: 'By form' } });

        expect(wrapper.findAll('.mds-tsc__line').map((p) => p.classes().join(' '))).toEqual([
            'mds-tsc__line mds-tsc--s1',
            'mds-tsc__line mds-tsc--s2',
            'mds-tsc__line mds-tsc--s3',
            'mds-tsc__line mds-tsc--s4',
            'mds-tsc__line mds-tsc--s5',
        ]);
        wrapper.unmount();
    });

    it('shows a legend only when there is more than one series to tell apart', () => {
        const one = mount(TimeSeriesChart, { props: { series: six.slice(0, 1), title: 'By form' } });
        expect(one.find('.mds-chart-legend').exists()).toBe(false);
        one.unmount();

        const many = mount(TimeSeriesChart, { props: { series: six.slice(0, 3), title: 'By form' } });
        expect(many.findAll('.mds-chart-legend__item')).toHaveLength(3);
        many.unmount();
    });

    it('leaves a blank cell where a shorter series has no point, rather than shifting the row', () => {
        const uneven: ChartSeries[] = [
            { key: 'a', label: 'A', points: [{ label: 'Mar 1', value: 1 }, { label: 'Mar 2', value: 2 }] },
            { key: 'b', label: 'B', points: [{ label: 'Mar 1', value: 5 }] },
        ];
        const wrapper = mount(TimeSeriesChart, { props: { series: uneven, title: 'Uneven' } });

        const cells = wrapper.findAll('tbody tr')[1].findAll('td');
        expect(cells.map((c) => c.text())).toEqual(['2', '—']);
        wrapper.unmount();
    });
});
