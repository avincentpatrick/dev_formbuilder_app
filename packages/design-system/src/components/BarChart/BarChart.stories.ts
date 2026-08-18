import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import BarChart from './BarChart.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/BarChart',
    component: BarChart,
    tags: ['autodocs'],
    args: {
        title: 'Top forms',
        categoryLabel: 'Form',
        valueLabel: 'Responses',
        data: [
            { key: 'a', label: 'Clinic Intake', value: 142 },
            { key: 'b', label: 'Household Roster', value: 98 },
            { key: 'c', label: 'Community Health Survey', value: 61 },
            { key: 'd', label: 'Branching Router', value: 24 },
        ],
    },
    render: (args) => ({
        components: { BarChart },
        setup: () => ({ args }),
        template: `<div style="max-width:720px"><BarChart v-bind="args" /></div>`,
    }),
} satisfies Meta<typeof BarChart>;

export default meta;
type Story = StoryObj<typeof meta>;

export const TopForms: Story = {};

/** §D11's aggregated bucket, painted a neutral so it never reads as a peer category. */
export const WithOtherBucket: Story = {
    args: {
        data: [
            { key: 'a', label: 'Clinic Intake', value: 142 },
            { key: 'b', label: 'Household Roster', value: 98 },
            { key: 'c', label: 'Community Health Survey', value: 61 },
            { key: 'd', label: 'Branching Router', value: 24 },
            { key: 'unassigned', label: 'Unassigned', value: 12 },
            { key: 'other', label: 'Other (6 categories)', value: 37, neutral: true },
        ],
    },
};

/** A long title has to wrap rather than squeeze the track — the 375px failure this shape prevents. */
export const LongLabels: Story = {
    args: {
        data: [
            { key: 'a', label: 'Community Health Survey — Q3 follow-up round, Central Visayas', value: 42 },
            { key: 'b', label: 'Household Roster', value: 18 },
        ],
    },
};

export const AllZero: Story = {
    args: {
        data: [
            { key: 'a', label: 'Clinic Intake', value: 0 },
            { key: 'b', label: 'Household Roster', value: 0 },
        ],
    },
};

export const Empty: Story = { args: { data: [] } };

export const WithVisibleTable: Story = { args: { tableVisible: true } };

/**
 * J2a — linked categories. The plot drops `role="img"` in this shape and the summary moves to a
 * visually-hidden paragraph; see the component docblock for why leaving links inside `role="img"` would
 * have made them unreachable rather than merely unlabelled. The aggregated bucket stays inert.
 */
export const LinkedCategories: Story = {
    args: {
        data: [
            { key: 'a', label: 'Clinic Intake', value: 42, href: '#clinic-intake' },
            { key: 'b', label: 'Household Roster', value: 18, href: '#household-roster' },
            { key: 'c', label: 'Other (3 categories)', value: 9, neutral: true },
        ],
    },
};

export const LinkedCategoriesDark: Story = {
    args: LinkedCategories.args,
    decorators: [dark],
};

export const WithOtherBucketDark: Story = {
    args: WithOtherBucket.args,
    decorators: [dark],
};
