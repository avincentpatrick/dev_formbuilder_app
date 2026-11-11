import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import DonutChart from './DonutChart.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/DonutChart',
    component: DonutChart,
    tags: ['autodocs'],
    args: {
        title: 'Responses by channel',
        categoryLabel: 'Channel',
        valueLabel: 'Responses',
        data: [
            { key: 'guest', label: 'Guest', value: 324 },
            { key: 'manual', label: 'Manual', value: 143 },
            { key: 'offline', label: 'Offline sync', value: 79 },
        ],
    },
    render: (args) => ({
        components: { DonutChart },
        setup: () => ({ args }),
        template: `<div style="max-width:720px"><DonutChart v-bind="args" /></div>`,
    }),
} satisfies Meta<typeof DonutChart>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Every slice is named, valued and given its share in text beside the ring — never by colour alone. */
export const Default: Story = {};

/** The caller formats the centre text; the component never derives or localises it. */
export const WithCentreTotal: Story = {
    args: { centerValue: '546', centerLabel: 'Responses' },
};

/** Five is the cap; each outline carries its own dash pattern, matching its legend swatch. */
export const FiveSlices: Story = {
    args: {
        data: [
            { key: 'guest', label: 'Guest', value: 324 },
            { key: 'manual', label: 'Manual', value: 143 },
            { key: 'offline', label: 'Offline sync', value: 79 },
            { key: 'api', label: 'API import', value: 61 },
            { key: 'ocr', label: 'OCR', value: 33 },
        ],
        centerValue: '640',
        centerLabel: 'Responses',
    },
};

/** §D11's aggregated bucket, painted a neutral so it never reads as a peer category. */
export const WithOtherSlice: Story = {
    args: {
        title: 'Responses by form',
        categoryLabel: 'Form',
        data: [
            { key: 'a', label: 'Clinic Intake', value: 142 },
            { key: 'b', label: 'Household Roster', value: 98 },
            { key: 'c', label: 'Community Health Survey', value: 61 },
            { key: 'd', label: 'Branching Router', value: 24 },
            { key: 'other', label: 'Other (6 forms)', value: 37, neutral: true },
        ],
    },
};

/** One category holds everything: a full ring, drawn as two half arcs because one 360° arc renders nothing. */
export const SingleCategory: Story = {
    args: { data: [{ key: 'guest', label: 'Guest', value: 324 }] },
};

/** A zero draws no slice and keeps its row, its swatch and its 0%. */
export const WithZeroValue: Story = {
    args: {
        data: [
            { key: 'guest', label: 'Guest', value: 324 },
            { key: 'manual', label: 'Manual', value: 0 },
            { key: 'offline', label: 'Offline sync', value: 79 },
        ],
    },
};

/** The ordinary state of a quiet tenant — an empty track and 0% shares, never a NaN. */
export const AllZero: Story = {
    args: {
        data: [
            { key: 'guest', label: 'Guest', value: 0 },
            { key: 'manual', label: 'Manual', value: 0 },
        ],
        centerValue: '0',
        centerLabel: 'Responses',
    },
};

export const Empty: Story = { args: { data: [] } };

/** The §D12 data table, shown rather than only exposed to assistive tech. */
export const WithVisibleTable: Story = { args: { tableVisible: true } };

export const WithOtherSliceDark: Story = {
    args: WithOtherSlice.args,
    decorators: [dark],
};

export const FiveSlicesDark: Story = {
    args: FiveSlices.args,
    decorators: [dark],
};
