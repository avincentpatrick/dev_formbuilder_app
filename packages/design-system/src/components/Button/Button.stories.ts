import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Button from './Button.vue';

// Force a story to render in dark mode regardless of the toolbar, so axe scans the dark palette.
// Runs after the project decorator (which sets the toolbar value), so this wins.
const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Button',
    component: Button,
    tags: ['autodocs'],
    argTypes: {
        variant: {
            control: 'select',
            options: ['primary', 'secondary', 'tertiary', 'destructive'],
        },
        size: { control: 'select', options: ['sm', 'md', 'lg'] },
        disabled: { control: 'boolean' },
        loading: { control: 'boolean' },
        label: { control: 'text' },
    },
    args: { variant: 'primary', size: 'md', disabled: false, loading: false, label: 'Save form' },
    render: (args) => ({
        components: { Button },
        setup: () => ({ args }),
        template: '<Button v-bind="args">{{ args.label }}</Button>',
    }),
} satisfies Meta<typeof Button & { label: string }>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Primary: Story = { args: { variant: 'primary' } };
export const Secondary: Story = { args: { variant: 'secondary' } };
export const Tertiary: Story = { args: { variant: 'tertiary' } };
export const Destructive: Story = { args: { variant: 'destructive', label: 'Delete form' } };

export const Small: Story = { args: { size: 'sm' } };
export const Large: Story = { args: { size: 'lg' } };

export const Loading: Story = { args: { loading: true, label: 'Saving' } };
export const Disabled: Story = { args: { disabled: true } };

export const PrimaryDark: Story = { args: { variant: 'primary' }, decorators: [dark] };
export const SecondaryDark: Story = { args: { variant: 'secondary' }, decorators: [dark] };
export const TertiaryDark: Story = { args: { variant: 'tertiary' }, decorators: [dark] };
export const DestructiveDark: Story = {
    args: { variant: 'destructive', label: 'Delete form' },
    decorators: [dark],
};
