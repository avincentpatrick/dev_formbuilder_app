import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Switch from './Switch.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Switch',
    component: Switch,
    tags: ['autodocs'],
    argTypes: {
        modelValue: { control: 'boolean' },
        label: { control: 'text' },
        invalid: { control: 'boolean' },
        disabled: { control: 'boolean' },
    },
    args: { modelValue: false, label: 'Maintenance mode' },
} satisfies Meta<typeof Switch>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Off: Story = { args: { modelValue: false } };
export const On: Story = { args: { modelValue: true } };
export const Invalid: Story = { args: { modelValue: false, invalid: true } };
export const Disabled: Story = { args: { modelValue: false, disabled: true } };

// The locked shape I5's notification preferences depend on: on AND non-interactive, explained by adjacent
// text rather than by the control. Its own story because the axe run scans states, not just defaults.
export const DisabledOn: Story = { args: { modelValue: true, disabled: true, label: 'Email' } };

export const OffDark: Story = { args: { modelValue: false }, decorators: [dark] };
export const OnDark: Story = { args: { modelValue: true }, decorators: [dark] };
export const InvalidDark: Story = { args: { modelValue: false, invalid: true }, decorators: [dark] };
export const DisabledOnDark: Story = {
    args: { modelValue: true, disabled: true, label: 'Email' },
    decorators: [dark],
};
