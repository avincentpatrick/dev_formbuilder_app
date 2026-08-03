import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import ChartLegend from './ChartLegend.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/ChartLegend',
    component: ChartLegend,
    tags: ['autodocs'],
    args: {
        ariaLabel: 'Responses by channel',
        items: [
            { key: 'guest', label: 'Guest', series: 1 },
            { key: 'manual', label: 'Manual', series: 2 },
            { key: 'offline', label: 'Offline sync', series: 3 },
        ],
    },
    render: (args) => ({
        components: { ChartLegend },
        setup: () => ({ args }),
        template: `<div style="max-width:480px"><ChartLegend v-bind="args" /></div>`,
    }),
} satisfies Meta<typeof ChartLegend>;

export default meta;
type Story = StoryObj<typeof meta>;

export const ThreeSeries: Story = {};

/** Five is the cap, and each swatch shows its dash pattern as well as its hue. */
export const FiveSeries: Story = {
    args: {
        items: [
            { key: 'guest', label: 'Guest', series: 1 },
            { key: 'manual', label: 'Manual', series: 2 },
            { key: 'offline', label: 'Offline sync', series: 3 },
            { key: 'api', label: 'API import', series: 4 },
            { key: 'ocr', label: 'OCR', series: 5 },
        ],
    },
};

export const WithOtherBucket: Story = {
    args: {
        items: [
            { key: 'guest', label: 'Guest', series: 1 },
            { key: 'manual', label: 'Manual', series: 2 },
            { key: 'other', label: 'Other (4 categories)', neutral: true },
        ],
    },
};

export const FiveSeriesDark: Story = {
    args: FiveSeries.args,
    decorators: [dark],
};
