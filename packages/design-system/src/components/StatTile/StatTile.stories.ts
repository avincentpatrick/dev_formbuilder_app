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
