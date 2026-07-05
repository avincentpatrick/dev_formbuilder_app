import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import PasswordInput from './PasswordInput.vue';
import FormField from '../FormField/FormField.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

// PasswordInput's root is a wrapper <div>, so it takes its accessible name from a real
// FormField <label> (id wiring) rather than a fall-through aria-label.
const meta = {
    title: 'Components/PasswordInput',
    component: PasswordInput,
    tags: ['autodocs'],
    argTypes: {
        modelValue: { control: 'text' },
        disabled: { control: 'boolean' },
    },
    args: { modelValue: '', disabled: false },
    render: (args) => ({
        components: { PasswordInput, FormField },
        setup: () => ({ args }),
        template: `
            <FormField label="Password" v-slot="{ id, describedby }">
                <PasswordInput
                    :id="id"
                    :describedby="describedby"
                    :model-value="args.modelValue"
                    :disabled="args.disabled"
                    autocomplete="current-password"
                />
            </FormField>
        `,
    }),
} satisfies Meta<typeof PasswordInput>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const WithValue: Story = { args: { modelValue: 'correct horse battery staple' } };
export const Disabled: Story = { args: { disabled: true, modelValue: 'secret' } };
export const Dark: Story = { args: { modelValue: 'correct horse battery staple' }, decorators: [dark] };
