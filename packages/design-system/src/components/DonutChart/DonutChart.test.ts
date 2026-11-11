import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DonutChart from './DonutChart.vue';
import type { BarDatum } from '../../charts/types';

/**
 * Same contract, same reason as the other chart suites: ADR-0011 §D12 puts the chart's text alternative
 * on Vitest rather than on the axe gate, because axe cannot tell a summarising `aria-label` from a
 * decorative one and will pass a picture nobody can read.
 *
 * happy-dom lays nothing out and resolves no `var()`, so everything asserted below is DOM structure or a
 * `d` string this repo's own arithmetic produced. The ring is centred on (50, 50) with radii 48 and 32.
 */

const forms: BarDatum[] = [
    { key: 'a', label: 'Clinic Intake', value: 42 },
    { key: 'b', label: 'Household Roster', value: 18 },
    { key: 'c', label: 'Other (3 forms)', value: 9, neutral: true },
];

const FULL_RING =
    'M50 2 A48 48 0 1 1 50 98 A48 48 0 1 1 50 2 Z M50 18 A32 32 0 1 0 50 82 A32 32 0 1 0 50 18 Z';

describe('MdsDonutChart — the text alternative (ADR-0011 §D12)', () => {
    it('ships a real data table with every value and its share', () => {
        const wrapper = mount(DonutChart, {
            props: { data: forms, title: 'Responses by form', categoryLabel: 'Form', valueLabel: 'Responses' },
        });

        expect(wrapper.find('caption').text()).toBe('Responses by form');
        expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Form', 'Responses', 'Share']);

        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(3);
        expect(rows.map((row) => row.find('th').text())).toEqual(forms.map((datum) => datum.label));
        expect(rows.map((row) => row.findAll('td').map((td) => td.text()))).toEqual([
            ['42', '61%'],
            ['18', '26%'],
            ['9', '13%'],
        ]);
        wrapper.unmount();
    });

    it('gives every header a scope so a screen reader can pair a value with its category', () => {
        const wrapper = mount(DonutChart, { props: { data: forms, title: 'Responses' } });

        expect(wrapper.findAll('thead th').map((th) => th.attributes('scope'))).toEqual(['col', 'col', 'col']);
        expect(wrapper.find('tbody th').attributes('scope')).toBe('row');
        wrapper.unmount();
    });

    it('hides the table visually by default and reveals it on request', () => {
        const hidden = mount(DonutChart, { props: { data: forms, title: 'Responses' } });
        expect(hidden.find('.mds-donut__table-wrap').classes()).toContain('mds-donut__table-wrap--sr');
        hidden.unmount();

        const shown = mount(DonutChart, { props: { data: forms, title: 'Responses', tableVisible: true } });
        expect(shown.find('.mds-donut__table-wrap').classes()).not.toContain('mds-donut__table-wrap--sr');
        shown.unmount();
    });

    it('summarises the total and the largest category behind role="img"', () => {
        const wrapper = mount(DonutChart, { props: { data: forms, title: 'Responses by form' } });

        const plot = wrapper.find('.mds-donut__plot');
        expect(plot.attributes('role')).toBe('img');
        expect(plot.attributes('aria-label')).toBe(
            'Responses by form. Donut chart of 3 categories, 69 in total. Largest: Clinic Intake, 42 (61%).',
        );
        wrapper.unmount();
    });

    it('puts role="img" on the group, so the legend inside it is not announced beside the table', () => {
        // The legend prints every value. Readable AND tabulated would announce the dataset twice — the
        // defect `MdsBarChart` records. Inside the image it is pruned, and the ring is not a second image.
        const wrapper = mount(DonutChart, { props: { data: forms, title: 'Responses' } });

        const plot = wrapper.find('.mds-donut__plot');
        expect(plot.find('.mds-donut__legend').exists()).toBe(true);
        expect(plot.find('.mds-donut__table-wrap').exists()).toBe(false);

        const svg = wrapper.find('svg.mds-donut__canvas');
        expect(svg.attributes('aria-hidden')).toBe('true');
        expect(svg.attributes('role')).toBeUndefined();
        wrapper.unmount();
    });

    it('runs the summary, the legend and the table through formatValue', () => {
        const wrapper = mount(DonutChart, {
            props: { data: forms, title: 'Storage', formatValue: (value: number) => `${value} MB` },
        });

        expect(wrapper.find('.mds-donut__plot').attributes('aria-label')).toBe(
            'Storage. Donut chart of 3 categories, 69 MB in total. Largest: Clinic Intake, 42 MB (61%).',
        );
        expect(wrapper.find('.mds-donut__value').text()).toBe('42 MB');
        expect(wrapper.find('tbody td').text()).toBe('42 MB');
        wrapper.unmount();
    });

    it('lets the caller replace the generated summary', () => {
        const wrapper = mount(DonutChart, {
            props: { data: forms, title: 'Responses', summary: 'Clinic Intake is most of it.' },
        });

        expect(wrapper.find('.mds-donut__plot').attributes('aria-label')).toBe('Clinic Intake is most of it.');
        wrapper.unmount();
    });

    it('drops role="img" rather than labelling an empty picture', () => {
        const wrapper = mount(DonutChart, { props: { data: [], title: 'Responses' } });

        const plot = wrapper.find('.mds-donut__plot');
        expect(plot.attributes('role')).toBeUndefined();
        expect(plot.attributes('aria-label')).toBeUndefined();
        expect(wrapper.findAll('.mds-donut__slice')).toHaveLength(0);
        expect(wrapper.find('.mds-donut__legend').exists()).toBe(false);
        expect(wrapper.findAll('tbody tr')).toHaveLength(0);
        expect(wrapper.find('caption').text()).toBe('Responses');
        wrapper.unmount();
    });
});

describe('MdsDonutChart — geometry', () => {
    it('starts at 12 o\'clock and runs clockwise in data order', () => {
        const wrapper = mount(DonutChart, {
            props: {
                data: [
                    { key: 'a', label: 'A', value: 3 },
                    { key: 'b', label: 'B', value: 1 },
                ],
                title: 'Split',
            },
        });

        // A is three quarters: 12 o'clock round to 9 o'clock, the long way (large-arc 1). B is the rest.
        expect(wrapper.findAll('.mds-donut__slice').map((slice) => slice.attributes('d'))).toEqual([
            'M50 2 A48 48 0 1 1 2 50 L18 50 A32 32 0 1 0 50 18 Z',
            'M2 50 A48 48 0 0 1 50 2 L50 18 A32 32 0 0 0 18 50 Z',
        ]);
        wrapper.unmount();
    });

    it('draws a lone category as two half arcs — one 360° arc renders nothing', () => {
        // An SVG arc whose endpoints coincide is omitted, so the ordinary "one form has every response"
        // breakdown would be an empty ring with no error anywhere.
        const wrapper = mount(DonutChart, {
            props: { data: [{ key: 'a', label: 'Clinic Intake', value: 12 }], title: 'Responses' },
        });

        expect(wrapper.findAll('.mds-donut__slice').map((slice) => slice.attributes('d'))).toEqual([FULL_RING]);
        wrapper.unmount();
    });

    it('draws no slice for a value of zero or below, and keeps it in the legend and the table', () => {
        const wrapper = mount(DonutChart, {
            props: {
                data: [
                    { key: 'a', label: 'A', value: 0 },
                    { key: 'b', label: 'B', value: 8 },
                    { key: 'c', label: 'C', value: -2 },
                ],
                title: 'Split',
            },
        });

        // B is the whole ring; the -2 is not subtracted from the total.
        const slices = wrapper.findAll('.mds-donut__slice');
        expect(slices.map((slice) => slice.attributes('d'))).toEqual([FULL_RING]);
        // Colour follows the category: B is the SECOND real category whether or not A has a slice today.
        expect(slices[0].classes()).toContain('mds-donut--s2');

        expect(wrapper.findAll('.mds-donut__item').map((item) => item.find('.mds-donut__label').text())).toEqual([
            'A',
            'B',
            'C',
        ]);
        expect(wrapper.findAll('.mds-donut__share').map((share) => share.text())).toEqual(['0%', '100%', '0%']);
        expect(wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((td) => td.text()))).toEqual([
            ['0', '0%'],
            ['8', '100%'],
            ['-2', '0%'],
        ]);
        wrapper.unmount();
    });

    it('keeps the ring a circle: the default aspect ratio, not the stretched canvas', () => {
        const wrapper = mount(DonutChart, { props: { data: forms, title: 'Responses' } });

        const svg = wrapper.find('svg.mds-donut__canvas');
        expect(svg.attributes('viewBox')).toBe('0 0 100 100');
        expect(svg.attributes('preserveAspectRatio')).toBeUndefined();
        expect(svg.findAll('text')).toHaveLength(0);
        for (const slice of wrapper.findAll('.mds-donut__slice')) {
            expect(slice.attributes('vector-effect')).toBe('non-scaling-stroke');
        }
        wrapper.unmount();
    });

    it('lays the centre text over the hole as HTML, and only when asked', () => {
        const without = mount(DonutChart, { props: { data: forms, title: 'Responses' } });
        expect(without.find('.mds-donut__center').exists()).toBe(false);
        without.unmount();

        const withCentre = mount(DonutChart, {
            props: { data: forms, title: 'Responses', centerValue: '69', centerLabel: 'Responses' },
        });
        const centre = withCentre.find('.mds-donut__ring > .mds-donut__center');
        expect(centre.element.tagName).toBe('DIV');
        expect(centre.find('.mds-donut__center-value').text()).toBe('69');
        expect(centre.find('.mds-donut__center-label').text()).toBe('Responses');
        withCentre.unmount();
    });
});

describe('MdsDonutChart — degenerate input never reaches the DOM as NaN', () => {
    const cases: Record<string, BarDatum[]> = {
        'all-zero': [
            { key: 'a', label: 'A', value: 0 },
            { key: 'b', label: 'B', value: 0 },
        ],
        empty: [],
        'a single category': [{ key: 'a', label: 'A', value: 7 }],
        'a single zero': [{ key: 'a', label: 'A', value: 0 }],
        'all negative': [{ key: 'a', label: 'A', value: -4 }],
        'a non-finite value': [
            { key: 'a', label: 'A', value: Number.NaN },
            { key: 'b', label: 'B', value: 3 },
        ],
    };

    for (const [name, data] of Object.entries(cases)) {
        it(name, () => {
            const wrapper = mount(DonutChart, { props: { data, title: 'Responses', tableVisible: true } });

            const html = wrapper.html();
            expect(html).not.toContain('NaN');
            expect(html).not.toContain('Infinity');
            expect(html).not.toContain('undefined');
            wrapper.unmount();
        });
    }

    it('reports an all-zero breakdown as 0% shares of a zero total, with no slice', () => {
        // A quiet tenant's ordinary state, and 0 / 0 is where a NaN% would come from.
        const wrapper = mount(DonutChart, { props: { data: cases['all-zero'], title: 'Responses' } });

        expect(wrapper.findAll('.mds-donut__slice')).toHaveLength(0);
        expect(wrapper.findAll('.mds-donut__share').map((share) => share.text())).toEqual(['0%', '0%']);
        expect(wrapper.find('.mds-donut__plot').attributes('aria-label')).toBe(
            'Responses. Donut chart of 2 categories, 0 in total. Largest: A, 0 (0%).',
        );
        wrapper.unmount();
    });
});

describe('MdsDonutChart — colour and the five-slice cap (ADR-0011 §D11)', () => {
    const six: BarDatum[] = Array.from({ length: 6 }, (_, i) => ({ key: `k${i}`, label: `Form ${i + 1}`, value: 1 }));

    it('assigns one static series class per slot, and the same one to the legend swatch', () => {
        const wrapper = mount(DonutChart, { props: { data: forms, title: 'Responses' } });

        expect(wrapper.findAll('.mds-donut__slice').map((slice) => slice.classes().join(' '))).toEqual([
            'mds-donut__slice mds-donut--s1',
            'mds-donut__slice mds-donut--s2',
            'mds-donut__slice mds-donut--other',
        ]);
        expect(wrapper.findAll('.mds-donut__swatch').map((swatch) => swatch.classes().join(' '))).toEqual([
            'mds-donut__swatch mds-donut--s1',
            'mds-donut__swatch mds-donut--s2',
            'mds-donut__swatch mds-donut--other',
        ]);
        wrapper.unmount();
    });

    it('plots five and LISTS all six — nothing is hidden, only un-plotted', () => {
        const wrapper = mount(DonutChart, { props: { data: six, title: 'By form' } });

        const slices = wrapper.findAll('.mds-donut__slice');
        expect(slices.map((slice) => slice.classes()[1])).toEqual([
            'mds-donut--s1',
            'mds-donut--s2',
            'mds-donut--s3',
            'mds-donut--s4',
            'mds-donut--s5',
        ]);
        expect(wrapper.findAll('.mds-donut__item')).toHaveLength(6);
        expect(wrapper.findAll('tbody tr')).toHaveLength(6);
        expect(wrapper.findAll('tbody tr')[5].find('th').text()).toBe('Form 6');
        // The sixth has no slot, so its swatch carries no series class and paints nothing.
        expect(wrapper.findAll('.mds-donut__swatch')[5].classes()).toEqual(['mds-donut__swatch']);
        wrapper.unmount();
    });

    it('keeps the un-plotted sixth in the WHOLE, so the five painted slices keep their true share', () => {
        // Six equal parts: each painted slice is a sixth of the ring, and the last sixth is left to the
        // track. Re-normalising to fifths would contradict the 17% printed beside every one of them.
        const wrapper = mount(DonutChart, { props: { data: six, title: 'By form' } });

        const fifth = wrapper.findAll('.mds-donut__slice')[4].attributes('d') ?? '';
        // 240° to 300° — 8 o'clock to 10 o'clock — and not on round to 12.
        expect(fifth).toBe('M8.43 74 A48 48 0 0 1 8.43 26 L22.29 34 A32 32 0 0 0 22.29 66 Z');
        expect(wrapper.findAll('.mds-donut__share').map((share) => share.text())).toEqual(Array(6).fill('17%'));
        wrapper.unmount();
    });

    it('paints a neutral datum as "Other" without spending one of the five', () => {
        const withOther: BarDatum[] = [
            { key: 'other', label: 'Other (4 forms)', value: 2, neutral: true },
            ...six.slice(0, 5),
        ];
        const wrapper = mount(DonutChart, { props: { data: withOther, title: 'By form' } });

        expect(wrapper.findAll('.mds-donut__slice').map((slice) => slice.classes()[1])).toEqual([
            'mds-donut--other',
            'mds-donut--s1',
            'mds-donut--s2',
            'mds-donut--s3',
            'mds-donut--s4',
            'mds-donut--s5',
        ]);
        wrapper.unmount();
    });

    it('ignores href — a slice is not a link, and the plot is a role="img" leaf', () => {
        const wrapper = mount(DonutChart, {
            props: { data: [{ key: 'a', label: 'Clinic Intake', value: 4, href: '/forms/a' }], title: 'Responses' },
        });

        expect(wrapper.findAll('a')).toHaveLength(0);
        expect(wrapper.find('.mds-donut__plot').attributes('role')).toBe('img');
        wrapper.unmount();
    });

    it('emits no inline style — geometry is attributes and colour is classes (§D10)', () => {
        const wrapper = mount(DonutChart, {
            props: { data: forms, title: 'Responses', centerValue: '69', centerLabel: 'Responses', tableVisible: true },
        });

        expect(wrapper.html()).not.toContain('style=');
        wrapper.unmount();
    });
});
