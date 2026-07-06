import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Checkbox from './Checkbox.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Checkbox',
    component: Checkbox,
    tags: ['autodocs'],
    argTypes: {
        modelValue: { control: 'boolean' },
        label: { control: 'text' },
        invalid: { control: 'boolean' },
        disabled: { control: 'boolean' },
    },
    args: { modelValue: false, label: 'Remember this device' },
} satisfies Meta<typeof Checkbox>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Unchecked: Story = { args: { modelValue: false } };
export const Checked: Story = { args: { modelValue: true } };
export const Invalid: Story = { args: { modelValue: false, invalid: true, label: 'Accept the terms' } };
export const Disabled: Story = { args: { modelValue: true, disabled: true } };

export const UncheckedDark: Story = { args: { modelValue: false }, decorators: [dark] };
export const CheckedDark: Story = { args: { modelValue: true }, decorators: [dark] };
export const InvalidDark: Story = {
    args: { modelValue: false, invalid: true, label: 'Accept the terms' },
    decorators: [dark],
};
