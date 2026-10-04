import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Callout from './Callout.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Callout',
    component: Callout,
    tags: ['autodocs'],
    argTypes: {
        tone: { control: 'select', options: ['info', 'success', 'warning', 'danger'] },
    },
    args: { tone: 'info' },
    render: (args) => ({
        components: { Callout },
        setup: () => ({ args }),
        template: '<Callout v-bind="args">Bring your health card and a list of the medicines you take.</Callout>',
    }),
} satisfies Meta<typeof Callout>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Info: Story = { args: { tone: 'info' } };
export const Success: Story = { args: { tone: 'success' } };
export const Warning: Story = { args: { tone: 'warning' } };
export const Danger: Story = { args: { tone: 'danger' } };

export const InfoDark: Story = { args: { tone: 'info' }, decorators: [dark] };
export const SuccessDark: Story = { args: { tone: 'success' }, decorators: [dark] };
export const WarningDark: Story = { args: { tone: 'warning' }, decorators: [dark] };
export const DangerDark: Story = { args: { tone: 'danger' }, decorators: [dark] };
