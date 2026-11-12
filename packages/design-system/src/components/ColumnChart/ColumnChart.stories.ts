import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import ColumnChart from './ColumnChart.vue';
import type { ColumnSeries } from '../../charts/types';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];

/** Fixed numbers — no Math.random, so the axe snapshot is stable across runs. */
const channels: ColumnSeries[] = [
    { key: 'guest', label: 'Guest', values: [42, 51, 38, 64, 58, 71] },
    { key: 'manual', label: 'Manual', values: [18, 22, 27, 19, 31, 26] },
    { key: 'offline', label: 'Offline sync', values: [9, 14, 6, 17, 12, 21] },
];

const meta = {
    title: 'Components/ColumnChart',
    component: ColumnChart,
    tags: ['autodocs'],
    args: {
        title: 'Responses per month',
        categoryLabel: 'Month',
        valueLabel: 'Responses',
        categories: months,
        series: [{ key: 'responses', label: 'Responses', values: [69, 87, 71, 100, 101, 118] }],
    },
    render: (args) => ({
        components: { ColumnChart },
        setup: () => ({ args }),
        template: `<div style="max-width:720px"><ColumnChart v-bind="args" /></div>`,
    }),
} satisfies Meta<typeof ColumnChart>;

export default meta;
type Story = StoryObj<typeof meta>;

/** One series: no categorical colour and no legend — the axis labels tell the columns apart (§D12). */
export const SingleSeries: Story = {};

/** Side by side within each band; the dash on each outline is the redundant channel the legend keys. */
export const Grouped: Story = {
    args: { title: 'Responses by channel', series: channels },
};

/** The same data as parts of a monthly total. The axis is sized to the tallest stack. */
export const Stacked: Story = {
    args: { title: 'Responses by channel', series: channels, mode: 'stacked' },
};

/** §D11's aggregated bucket: a neutral series that does not spend one of the five hues. */
export const WithOtherSeries: Story = {
    args: {
        title: 'Responses by form',
        mode: 'stacked',
        series: [
            { key: 'intake', label: 'Clinic Intake', values: [30, 34, 28, 41, 37, 44] },
            { key: 'roster', label: 'Household Roster', values: [21, 25, 19, 27, 30, 33] },
            { key: 'survey', label: 'Community Health Survey', values: [11, 16, 14, 18, 17, 22] },
            { key: 'other', label: 'Other (6 forms)', values: [7, 12, 10, 14, 17, 19], neutral: true },
        ],
    },
};

/** Five is the cap. Grouped, so all five outline patterns are visible at once. */
export const FiveSeries: Story = {
    args: {
        title: 'Responses by channel',
        categories: ['Q1', 'Q2', 'Q3'],
        categoryLabel: 'Quarter',
        series: [
            { key: 'guest', label: 'Guest', values: [131, 193, 178] },
            { key: 'manual', label: 'Manual', values: [67, 76, 84] },
            { key: 'offline', label: 'Offline sync', values: [29, 50, 41] },
            { key: 'api', label: 'API import', values: [12, 25, 38] },
            { key: 'ocr', label: 'OCR', values: [4, 9, 16] },
        ],
    },
};

/** Grouped mode only: a negative value hangs below the zero line, and a missing one leaves its slot empty. */
export const NegativeAndMissing: Story = {
    args: {
        title: 'Net change in responses',
        valueLabel: 'Net change',
        series: [
            { key: 'guest', label: 'Guest', values: [12, -8, 5, null, -3, 9] },
            { key: 'manual', label: 'Manual', values: [-4, 6, null, 7, 2, -6] },
        ],
    },
};

/** Twelve bands, six labels: thinning empties cells rather than removing them, so each stays under its band. */
export const ThinnedLabels: Story = {
    args: {
        categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        series: [
            { key: 'responses', label: 'Responses', values: [69, 87, 71, 100, 101, 118, 96, 84, 110, 125, 131, 90] },
        ],
    },
};

/** The ordinary state of a quiet tenant — zero-height bars on the baseline, never a NaN rect. */
export const AllZero: Story = {
    args: { series: [{ key: 'responses', label: 'Responses', values: [0, 0, 0, 0, 0, 0] }] },
};

export const Empty: Story = { args: { categories: [], series: [] } };

/** The §D12 data table, shown rather than only exposed to assistive tech. */
export const WithVisibleTable: Story = {
    args: { title: 'Responses by channel', series: channels, tableVisible: true },
};

export const GroupedDark: Story = {
    args: Grouped.args,
    decorators: [dark],
};

export const WithOtherSeriesDark: Story = {
    args: WithOtherSeries.args,
    decorators: [dark],
};
