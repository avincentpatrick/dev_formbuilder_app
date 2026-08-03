import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import TimeSeriesChart from './TimeSeriesChart.vue';
import type { ChartSeries } from '../../charts/types';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

/** A deterministic 30-day shape — no Math.random, so the axe snapshot is stable across runs. */
function month(seed: number[], key = 'responses', label = 'Responses'): ChartSeries {
    return {
        key,
        label,
        points: Array.from({ length: 30 }, (_, i) => ({
            label: `Mar ${i + 1}`,
            value: seed[i % seed.length],
        })),
    };
}

const meta = {
    title: 'Components/TimeSeriesChart',
    component: TimeSeriesChart,
    tags: ['autodocs'],
    args: {
        series: [month([4, 7, 3, 9, 6, 11, 5])],
        title: 'Responses per day',
        categoryLabel: 'Day',
        valueLabel: 'Responses',
    },
    render: (args) => ({
        components: { TimeSeriesChart },
        setup: () => ({ args }),
        template: `<div style="max-width:720px"><TimeSeriesChart v-bind="args" /></div>`,
    }),
} satisfies Meta<typeof TimeSeriesChart>;

export default meta;
type Story = StoryObj<typeof meta>;

export const SingleSeries: Story = {};

export const Area: Story = { args: { variant: 'area' } };

/** Five is the cap, and the dash patterns are the redundant channel (§D12). */
export const FiveSeries: Story = {
    args: {
        title: 'Responses by channel',
        series: [
            month([4, 7, 3, 9, 6, 11, 5], 'guest', 'Guest'),
            month([2, 3, 5, 2, 4, 3, 6], 'manual', 'Manual'),
            month([1, 0, 2, 1, 3, 1, 0], 'offline', 'Offline sync'),
            month([0, 2, 1, 4, 2, 0, 1], 'api', 'API import'),
            month([3, 1, 0, 2, 1, 5, 2], 'ocr', 'OCR'),
        ],
    },
};

/** The ordinary state of a quiet tenant — flat on the baseline, never a NaN path. */
export const AllZero: Story = { args: { series: [month([0])] } };

/** One bucket: a dot, not a line implying a constant. */
export const SinglePoint: Story = {
    args: { series: [{ key: 'responses', label: 'Responses', points: [{ label: 'Mar 14', value: 7 }] }] },
};

export const Empty: Story = { args: { series: [] } };

/** The §D12 data table, shown rather than only exposed to assistive tech. */
export const WithVisibleTable: Story = {
    args: { series: [month([4, 7, 3])], tableVisible: true },
};

export const FiveSeriesDark: Story = {
    args: FiveSeries.args,
    decorators: [dark],
};
