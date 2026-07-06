import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Select from './Select.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const options = [
    { value: 'text', label: 'Short text' },
    { value: 'integer', label: 'Whole number' },
    { value: 'single_select', label: 'Single choice' },
    { value: 'date', label: 'Date' },
];

// The bare select gets an aria-label in stories (it normally gets its name from FormField's <label>)
// so axe can scan it without flagging a missing accessible name.
const meta = {
    title: 'Components/Select',
    component: Select,
    tags: ['autodocs'],
    argTypes: {
        modelValue: { control: 'text' },
        invalid: { control: 'boolean' },
        disabled: { control: 'boolean' },
    },
    args: { modelValue: 'integer', options, invalid: false, disabled: false },
    render: (args) => ({
        components: { Select },
        setup: () => ({ args }),
        template: '<Select v-bind="args" aria-label="Field type" />',
    }),
} satisfies Meta<typeof Select>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const Placeholder: Story = { args: { modelValue: '', placeholder: 'Choose a field type…' } };
export const Invalid: Story = { args: { invalid: true } };
export const Disabled: Story = { args: { disabled: true } };
export const Dark: Story = { decorators: [dark] };
export const InvalidDark: Story = { args: { invalid: true }, decorators: [dark] };
