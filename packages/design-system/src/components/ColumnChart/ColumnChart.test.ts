import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ColumnChart from './ColumnChart.vue';
import type { ColumnSeries } from '../../charts/types';

/**
 * Same contract, same reason as the other chart suites: ADR-0011 §D12 puts the chart's text alternative
 * on Vitest rather than on the axe gate, because axe cannot tell a summarising `aria-label` from a
 * decorative one and will pass a picture nobody can read.
 *
 * happy-dom lays nothing out and resolves no `var()`, so everything asserted below is DOM structure or a
 * rect this repo's own arithmetic produced. The geometry cases are hand-computed, not snapshotted.
 */

const quarters = ['Q1', 'Q2'];

/** Two bands of 50 units, 15% inset each side: the padded band is 35 wide and starts at 7.5 / 57.5. */
const channels: ColumnSeries[] = [
    { key: 'guest', label: 'Guest', values: [10, 5] },
    { key: 'manual', label: 'Manual', values: [2, null] },
];

const rectOf = (bar: { attributes: (name: string) => string | undefined }): string =>
    ['x', 'y', 'width', 'height'].map((name) => bar.attributes(name)).join(' ');

describe('MdsColumnChart — the text alternative (ADR-0011 §D12)', () => {
    it('ships a real data table of every value, not only an aria-label', () => {
        const wrapper = mount(ColumnChart, {
            props: { categories: quarters, series: channels, title: 'Responses by channel', categoryLabel: 'Quarter' },
        });

        expect(wrapper.find('caption').text()).toBe('Responses by channel');
        expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Quarter', 'Guest', 'Manual']);

        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(2);
        expect(rows[0].find('th').text()).toBe('Q1');
        expect(rows[0].findAll('td').map((td) => td.text())).toEqual(['10', '2']);
        // `null` is "no value": an em dash, never a zero the data did not contain.
        expect(rows[1].findAll('td').map((td) => td.text())).toEqual(['5', '—']);
        wrapper.unmount();
    });

    it('heads a single series with the value label, since its own label would repeat the title', () => {
        const wrapper = mount(ColumnChart, {
            props: { categories: quarters, series: channels.slice(0, 1), title: 'Responses', valueLabel: 'Responses' },
        });

        expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Category', 'Responses']);
        wrapper.unmount();
    });

    it('gives every header a scope so a screen reader can pair a value with its category', () => {
        const wrapper = mount(ColumnChart, { props: { categories: quarters, series: channels, title: 'Responses' } });

        expect(wrapper.find('thead th').attributes('scope')).toBe('col');
        expect(wrapper.find('tbody th').attributes('scope')).toBe('row');
        wrapper.unmount();
    });

    it('hides the table visually by default and reveals it on request', () => {
        const hidden = mount(ColumnChart, { props: { categories: quarters, series: channels, title: 'Responses' } });
        expect(hidden.find('.mds-col__table-wrap').classes()).toContain('mds-col__table-wrap--sr');
        hidden.unmount();

        const shown = mount(ColumnChart, {
            props: { categories: quarters, series: channels, title: 'Responses', tableVisible: true },
        });
        expect(shown.find('.mds-col__table-wrap').classes()).not.toContain('mds-col__table-wrap--sr');
        shown.unmount();
    });

    it('summarises the plot behind role="img": the shape, the series and the data extremes', () => {
        const wrapper = mount(ColumnChart, {
            props: { categories: quarters, series: channels, title: 'Responses by channel' },
        });

        const svg = wrapper.find('svg.mds-col__canvas');
        expect(svg.attributes('role')).toBe('img');
        // The DATA's extremes — 2, not the zero the axis is widened to include.
        expect(svg.attributes('aria-label')).toBe(
            'Responses by channel. Column chart of 2 categories, 2 series: Guest, Manual. Values from 2 to 10.',
        );
        wrapper.unmount();
    });

    it('says "stacked" in stacked mode and omits the series clause for a single series', () => {
        const wrapper = mount(ColumnChart, {
            props: { categories: ['Q1'], series: channels.slice(0, 1), title: 'Responses', mode: 'stacked' },
        });

        expect(wrapper.find('svg.mds-col__canvas').attributes('aria-label')).toBe(
            'Responses. Stacked column chart of 1 category. Values from 10 to 10.',
        );
        wrapper.unmount();
    });

    it('runs the summary and the table through formatValue, and the axis through formatTick', () => {
        const wrapper = mount(ColumnChart, {
            props: {
                categories: quarters,
                series: channels.slice(0, 1),
                title: 'Completion',
                formatValue: (value: number) => `${value}%`,
                formatTick: (value: number) => `${value} pts`,
            },
        });

        expect(wrapper.find('svg.mds-col__canvas').attributes('aria-label')).toContain('Values from 5% to 10%.');
        expect(wrapper.find('tbody td').text()).toBe('10%');
        expect(wrapper.find('.mds-col__y span').text()).toBe('10 pts');
        wrapper.unmount();
    });

    it('lets the caller replace the generated summary', () => {
        const wrapper = mount(ColumnChart, {
            props: { categories: quarters, series: channels, title: 'Responses', summary: 'Guest led both quarters.' },
        });

        expect(wrapper.find('svg.mds-col__canvas').attributes('aria-label')).toBe('Guest led both quarters.');
        wrapper.unmount();
    });

    it('drops role="img" rather than labelling an empty picture', () => {
        const wrapper = mount(ColumnChart, { props: { categories: [], series: [], title: 'Responses' } });

        const svg = wrapper.find('svg.mds-col__canvas');
        expect(svg.attributes('role')).toBeUndefined();
        expect(svg.attributes('aria-label')).toBeUndefined();
        expect(svg.attributes('aria-hidden')).toBe('true');
        expect(wrapper.findAll('tbody tr')).toHaveLength(0);
        expect(wrapper.find('caption').text()).toBe('Responses');
        wrapper.unmount();
    });

    it('treats categories with not one number among them as empty too', () => {
        // Nothing is drawn, so there is no picture to label — but the rows are real and stay tabulated.
        const wrapper = mount(ColumnChart, {
            props: { categories: quarters, series: [{ key: 'a', label: 'A', values: [null, null] }], title: 'Responses' },
        });

        expect(wrapper.find('svg.mds-col__canvas').attributes('role')).toBeUndefined();
        expect(wrapper.findAll('.mds-col__bar')).toHaveLength(0);
        expect(wrapper.findAll('tbody td').map((td) => td.text())).toEqual(['—', '—']);
        wrapper.unmount();
    });

    it('keeps the axis labels out of the accessibility tree — the table is the alternative', () => {
        const wrapper = mount(ColumnChart, { props: { categories: quarters, series: channels, title: 'Responses' } });

        expect(wrapper.find('.mds-col__y').attributes('aria-hidden')).toBe('true');
        expect(wrapper.find('.mds-col__x').attributes('aria-hidden')).toBe('true');
        wrapper.unmount();
    });
});

describe('MdsColumnChart — geometry', () => {
    it('splits each padded band evenly among the grouped series', () => {
        const wrapper = mount(ColumnChart, { props: { categories: quarters, series: channels, title: 'Responses' } });

        // Domain [0, 10]. Slot width 35 / 2 = 17.5. Guest 10 fills the plot; Manual 2 is a fifth of it;
        // Manual has no Q2 value, so no rect — and Guest's Q2 bar does NOT widen into the empty slot.
        expect(wrapper.findAll('.mds-col__bar').map(rectOf)).toEqual([
            '7.5 0 17.5 100',
            '57.5 50 17.5 50',
            '25 80 17.5 20',
        ]);
        wrapper.unmount();
    });

    it('hangs a negative grouped value below the zero line', () => {
        const wrapper = mount(ColumnChart, {
            props: { categories: ['Q1'], series: [{ key: 'net', label: 'Net', values: [-5] }], title: 'Net change' },
        });

        // Domain [-5, 0]: zero is the TOP of the plot, and the bar runs the full height down from it.
        expect(wrapper.findAll('.mds-col__bar').map(rectOf)).toEqual(['15 0 70 100']);
        expect(wrapper.find('.mds-col__axis').attributes('y1')).toBe('0');
        wrapper.unmount();
    });

    it('stacks segments upward from zero in series order, on an axis sized to the tallest stack', () => {
        const stack: ColumnSeries[] = [
            { key: 'guest', label: 'Guest', values: [10, 5] },
            { key: 'manual', label: 'Manual', values: [10, 15] },
        ];
        const wrapper = mount(ColumnChart, {
            props: { categories: quarters, series: stack, title: 'Responses', mode: 'stacked' },
        });

        // Domain [0, 20] — the stack totals, not the largest part (15), which would overflow the plot.
        expect(wrapper.findAll('.mds-col__bar').map(rectOf)).toEqual([
            '7.5 50 35 50',
            '57.5 75 35 25',
            '7.5 0 35 50',
            '57.5 0 35 75',
        ]);
        wrapper.unmount();
    });

    it('lets a negative or null value contribute nothing to a stack', () => {
        const stack: ColumnSeries[] = [
            { key: 'a', label: 'A', values: [4, -3] },
            { key: 'b', label: 'B', values: [null, 6] },
            { key: 'c', label: 'C', values: [6, 4] },
        ];
        const wrapper = mount(ColumnChart, {
            props: { categories: quarters, series: stack, title: 'Responses', mode: 'stacked' },
        });

        // Domain [0, 10]. Q1: A 0-4, C 4-10. Q2: B 0-6, C 6-10 — the -3 neither draws nor lowers the pile.
        expect(wrapper.findAll('.mds-col__bar').map(rectOf)).toEqual([
            '7.5 60 35 40',
            '57.5 40 35 60',
            '7.5 0 35 60',
            '57.5 0 35 40',
        ]);
        // Un-plotted, not hidden: the table still says -3.
        expect(wrapper.findAll('tbody tr')[1].findAll('td').map((td) => td.text())).toEqual(['-3', '6', '4']);
        wrapper.unmount();
    });

    it('always includes zero in the value domain, so a bar length cannot be truncated', () => {
        const wrapper = mount(ColumnChart, {
            props: { categories: quarters, series: [{ key: 'a', label: 'A', values: [400, 410] }], title: 'Responses' },
        });

        const ticks = wrapper.findAll('.mds-col__y span').map((span) => span.text());
        expect(ticks[ticks.length - 1]).toBe('0');
        wrapper.unmount();
    });

    it('keeps every stroked mark non-scaling, because the canvas is stretched to fit', () => {
        const wrapper = mount(ColumnChart, { props: { categories: quarters, series: channels, title: 'Responses' } });

        expect(wrapper.find('svg.mds-col__canvas').attributes('preserveAspectRatio')).toBe('none');
        const stroked = wrapper.findAll('.mds-col__bar, .mds-col__grid line, .mds-col__axis');
        expect(stroked.length).toBeGreaterThan(4);
        for (const mark of stroked) {
            expect(mark.attributes('vector-effect')).toBe('non-scaling-stroke');
        }
        wrapper.unmount();
    });

    it('puts no text inside the stretched SVG', () => {
        const wrapper = mount(ColumnChart, { props: { categories: quarters, series: channels, title: 'Responses' } });

        expect(wrapper.find('svg.mds-col__canvas').findAll('text')).toHaveLength(0);
        wrapper.unmount();
    });

    it('renders one x cell per category and prints only the thinned labels, so each stays under its band', () => {
        const months = Array.from({ length: 12 }, (_, i) => `M${i + 1}`);
        const wrapper = mount(ColumnChart, {
            props: {
                categories: months,
                series: [{ key: 'a', label: 'A', values: months.map((_, i) => i) }],
                title: 'Responses',
                maxTicks: 4,
            },
        });

        const cells = wrapper.findAll('.mds-col__x > span').map((span) => span.text());
        expect(cells).toHaveLength(12);
        expect(cells.filter((text) => text !== '')).toEqual(['M1', 'M5', 'M8', 'M12']);
        expect(cells[0]).toBe('M1');
        expect(cells[11]).toBe('M12');
        wrapper.unmount();
    });
});

describe('MdsColumnChart — degenerate input never reaches the DOM as NaN', () => {
    const cases: Record<string, { categories: string[]; series: ColumnSeries[] }> = {
        'all-zero': { categories: quarters, series: [{ key: 'a', label: 'A', values: [0, 0] }] },
        empty: { categories: [], series: [] },
        'categories without series': { categories: quarters, series: [] },
        'series without categories': { categories: [], series: channels },
        'a single category': { categories: ['Q1'], series: [{ key: 'a', label: 'A', values: [7] }] },
        'null-containing': { categories: quarters, series: [{ key: 'a', label: 'A', values: [null, 3] }] },
        'all-null': { categories: quarters, series: [{ key: 'a', label: 'A', values: [null, null] }] },
        'values shorter than categories': { categories: quarters, series: [{ key: 'a', label: 'A', values: [3] }] },
        'a non-finite value': { categories: quarters, series: [{ key: 'a', label: 'A', values: [Number.NaN, 3] }] },
    };

    for (const mode of ['grouped', 'stacked'] as const) {
        for (const [name, props] of Object.entries(cases)) {
            it(`${name}, ${mode}`, () => {
                const wrapper = mount(ColumnChart, { props: { ...props, title: 'Responses', mode } });

                const html = wrapper.html();
                expect(html).not.toContain('NaN');
                expect(html).not.toContain('Infinity');
                expect(html).not.toContain('undefined');
                wrapper.unmount();
            });
        }
    }

    it('draws an all-zero breakdown as zero-height bars on the baseline', () => {
        // A quiet tenant's ordinary state. The rects exist, at the bottom of a [0, 1] axis.
        const wrapper = mount(ColumnChart, { props: { ...cases['all-zero'], title: 'Responses' } });

        expect(wrapper.findAll('.mds-col__bar').map(rectOf)).toEqual(['7.5 100 35 0', '57.5 100 35 0']);
        wrapper.unmount();
    });

    it('centres a single category in the plot', () => {
        const wrapper = mount(ColumnChart, { props: { ...cases['a single category'], title: 'Responses' } });

        // Domain [0, 8]: 7 is 87.5% of the height.
        expect(wrapper.findAll('.mds-col__bar').map(rectOf)).toEqual(['15 12.5 70 87.5']);
        wrapper.unmount();
    });
});

describe('MdsColumnChart — colour and the five-series cap (ADR-0011 §D11)', () => {
    const six: ColumnSeries[] = Array.from({ length: 6 }, (_, i) => ({
        key: `s${i}`,
        label: `Series ${i + 1}`,
        values: [i + 1],
    }));

    it('plots five and TABULATES all six — nothing is hidden, only un-plotted', () => {
        const wrapper = mount(ColumnChart, { props: { categories: ['Q1'], series: six, title: 'By form' } });

        expect(wrapper.findAll('.mds-col__bar')).toHaveLength(5);
        expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual([
            'Category',
            'Series 1',
            'Series 2',
            'Series 3',
            'Series 4',
            'Series 5',
            'Series 6',
        ]);
        expect(wrapper.find('tbody tr').findAll('td').map((td) => td.text())).toEqual(['1', '2', '3', '4', '5', '6']);
        expect(wrapper.findAll('.mds-chart-legend__item')).toHaveLength(5);
        wrapper.unmount();
    });

    it('assigns one static series class per slot, never an interpolated token name', () => {
        const wrapper = mount(ColumnChart, { props: { categories: ['Q1'], series: six, title: 'By form' } });

        expect(wrapper.findAll('.mds-col__bar').map((bar) => bar.classes().join(' '))).toEqual([
            'mds-col__bar mds-col--s1',
            'mds-col__bar mds-col--s2',
            'mds-col__bar mds-col--s3',
            'mds-col__bar mds-col--s4',
            'mds-col__bar mds-col--s5',
        ]);
        wrapper.unmount();
    });

    it('paints a neutral series as "Other" without spending one of the five', () => {
        // Listed FIRST on purpose: it must not push the real series off their own slots.
        const withOther: ColumnSeries[] = [
            { key: 'other', label: 'Other (4 forms)', values: [9], neutral: true },
            ...six.slice(0, 5),
        ];
        const wrapper = mount(ColumnChart, { props: { categories: ['Q1'], series: withOther, title: 'By form' } });

        expect(wrapper.findAll('.mds-col__bar').map((bar) => bar.classes()[1])).toEqual([
            'mds-col--other',
            'mds-col--s1',
            'mds-col--s2',
            'mds-col--s3',
            'mds-col--s4',
            'mds-col--s5',
        ]);

        const swatches = wrapper.findAll('.mds-chart-legend__swatch').map((swatch) => swatch.classes()[1]);
        expect(swatches[0]).toBe('mds-chart-legend__swatch--other');
        expect(swatches[1]).toBe('mds-chart-legend__swatch--1');
        expect(swatches[5]).toBe('mds-chart-legend__swatch--5');
        wrapper.unmount();
    });

    it('shows a legend only when there is more than one plotted series to tell apart', () => {
        const one = mount(ColumnChart, { props: { categories: ['Q1'], series: six.slice(0, 1), title: 'By form' } });
        expect(one.find('.mds-chart-legend').exists()).toBe(false);
        one.unmount();

        const many = mount(ColumnChart, { props: { categories: ['Q1'], series: six.slice(0, 3), title: 'By form' } });
        expect(many.findAll('.mds-chart-legend__item').map((item) => item.text())).toEqual([
            'Series 1',
            'Series 2',
            'Series 3',
        ]);
        many.unmount();
    });

    it('emits no inline style — geometry is attributes and colour is classes (§D10)', () => {
        for (const mode of ['grouped', 'stacked'] as const) {
            const wrapper = mount(ColumnChart, {
                props: { categories: quarters, series: channels, title: 'Responses', mode, tableVisible: true },
            });

            expect(wrapper.html()).not.toContain('style=');
            wrapper.unmount();
        }
    });
});
