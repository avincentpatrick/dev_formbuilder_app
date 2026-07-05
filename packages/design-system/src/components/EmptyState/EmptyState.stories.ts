import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import EmptyState from './EmptyState.vue';
import Button from '../Button/Button.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/EmptyState',
    component: EmptyState,
    tags: ['autodocs'],
    argTypes: {
        headline: { control: 'text' },
        description: { control: 'text' },
        illustration: { control: 'select', options: ['default', 'search', 'lock'] },
    },
    args: {
        headline: 'No forms yet',
        description: 'Create your first form to start collecting responses.',
        illustration: 'default',
    },
    render: (args) => ({
        components: { EmptyState, Button },
        setup: () => ({ args }),
        template: `
            <EmptyState v-bind="args">
                <template #action><Button variant="primary">Create form</Button></template>
            </EmptyState>
        `,
    }),
} satisfies Meta<typeof EmptyState>;

export default meta;
type Story = StoryObj<typeof meta>;

export const FirstRun: Story = {};

// Permission-restricted: distinct copy explaining WHY, no CTA.
export const PermissionRestricted: Story = {
    args: {
        headline: 'You don’t have access to forms',
        description: 'Ask a workspace admin to grant you the Form Editor role.',
        illustration: 'lock',
    },
    render: (args) => ({
        components: { EmptyState },
        setup: () => ({ args }),
        template: '<EmptyState v-bind="args" />',
    }),
};

// Filtered-to-zero: lighter variant with a "clear filters" tertiary action.
export const FilteredZero: Story = {
    args: {
        headline: 'No matches',
        description: 'No submissions match the current filters.',
        illustration: 'search',
    },
    render: (args) => ({
        components: { EmptyState, Button },
        setup: () => ({ args }),
        template: `
            <EmptyState v-bind="args">
                <template #action><Button variant="tertiary">Clear filters</Button></template>
            </EmptyState>
        `,
    }),
};

export const FirstRunDark: Story = { decorators: [dark] };
