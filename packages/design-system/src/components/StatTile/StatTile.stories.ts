import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import StatTile from './StatTile.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/StatTile',
    component: StatTile,
    tags: ['autodocs'],
    argTypes: {
        icon: { control: 'select', options: ['forms', 'submissions', 'users'] },
    },
    args: { label: 'Forms', value: '12', icon: 'forms' },
    render: (args) => ({
        components: { StatTile },
        setup: () => ({ args }),
        template: `<StatTile v-bind="args" style="max-width:280px" />`,
    }),
} satisfies Meta<typeof StatTile>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Forms: Story = {};
export const Submissions: Story = { args: { label: 'Submissions', value: '1,284', icon: 'submissions' } };
export const Members: Story = { args: { label: 'Members', value: 7, icon: 'users' } };
export const Zero: Story = { args: { label: 'Forms', value: 0, icon: 'forms' } };
export const SubmissionsDark: Story = {
    args: { label: 'Submissions', value: '1,284', icon: 'submissions' },
    decorators: [dark],
};

/* ── H24b1: the three ADR-0011 states ──────────────────────────────────────────────────── */

export const DeltaUp: Story = {
    args: {
        label: 'Submissions',
        value: '1,284',
        icon: 'submissions',
        delta: 12.4,
        deltaLabel: 'vs. previous 30 days',
    },
};

export const DeltaDown: Story = {
    args: { label: 'Submissions', value: '842', icon: 'submissions', delta: -6.1, deltaLabel: 'vs. previous 30 days' },
};

/** `change === null` means the prior period held no rows — undefined, not a rise from zero. */
export const DeltaUndefined: Story = {
    args: { label: 'Submissions', value: '12', icon: 'submissions', delta: null, deltaLabel: 'vs. previous 30 days' },
};

/** ADR-0011 §D5: a metric that cannot honestly be computed says so rather than rendering a zero. */
export const Unavailable: Story = {
    args: {
        label: 'Draft conversion',
        value: 0,
        icon: 'activity',
        unavailable: true,
        unavailableNote: 'Not available for periods reaching past the 30-day draft retention window.',
    },
};

/** §D5 again: both draft metrics state their denominator on the face of the tile. */
export const WithCaption: Story = {
    args: { label: 'Draft conversion', value: '62%', icon: 'activity', caption: 'of 48 saved drafts' },
};

export const DeltaDownDark: Story = {
    args: DeltaDown.args,
    decorators: [dark],
};

export const UnavailableDark: Story = {
    args: Unavailable.args,
    decorators: [dark],
};
