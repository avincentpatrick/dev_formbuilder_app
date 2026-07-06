import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import NumberInput from './NumberInput.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

// The bare number input gets an aria-label in stories (it normally gets its name from FormField's
// <label>) so axe can scan it without flagging a missing accessible name.
const meta = {
    title: 'Components/NumberInput',
    component: NumberInput,
    tags: ['autodocs'],
    argTypes: {
        modelValue: { control: 'number' },
        min: { control: 'number' },
        max: { control: 'number' },
        step: { control: 'number' },
        invalid: { control: 'boolean' },
        disabled: { control: 'boolean' },
    },
    args: { modelValue: 5, invalid: false, disabled: false },
    render: (args) => ({
        components: { NumberInput },
        setup: () => ({ args }),
        template: '<NumberInput v-bind="args" aria-label="Maximum instances" />',
    }),
} satisfies Meta<typeof NumberInput>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const Empty: Story = { args: { modelValue: null, placeholder: 'No limit' } };
export const Bounded: Story = { args: { modelValue: 3, min: 1, max: 10, step: 1 } };
export const Invalid: Story = { args: { invalid: true } };
export const Disabled: Story = { args: { disabled: true } };
export const Dark: Story = { decorators: [dark] };
export const InvalidDark: Story = { args: { invalid: true }, decorators: [dark] };
