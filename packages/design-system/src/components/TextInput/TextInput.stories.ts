import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import TextInput from './TextInput.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

// Stories give the bare input an aria-label (it normally gets its name from FormField's <label>)
// so axe can scan it without flagging a missing accessible name.
const meta = {
    title: 'Components/TextInput',
    component: TextInput,
    tags: ['autodocs'],
    argTypes: {
        modelValue: { control: 'text' },
        invalid: { control: 'boolean' },
        disabled: { control: 'boolean' },
    },
    args: { modelValue: '', invalid: false, disabled: false },
    render: (args) => ({
        components: { TextInput },
        setup: () => ({ args }),
        template: '<TextInput v-bind="args" aria-label="Work email" />',
    }),
} satisfies Meta<typeof TextInput>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const WithValue: Story = { args: { modelValue: 'jordan@example.org' } };
export const Invalid: Story = { args: { invalid: true, modelValue: 'not-an-email' } };
export const Disabled: Story = { args: { disabled: true, modelValue: 'locked@example.org' } };
export const Dark: Story = { args: { modelValue: 'jordan@example.org' }, decorators: [dark] };
export const InvalidDark: Story = {
    args: { invalid: true, modelValue: 'not-an-email' },
    decorators: [dark],
};
