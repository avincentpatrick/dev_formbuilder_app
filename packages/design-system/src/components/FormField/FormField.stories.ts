import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import FormField from './FormField.vue';
import TextInput from '../TextInput/TextInput.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/FormField',
    component: FormField,
    tags: ['autodocs'],
    argTypes: {
        label: { control: 'text' },
        required: { control: 'boolean' },
        help: { control: 'text' },
        error: { control: 'text' },
    },
    args: { label: 'Work email', required: false },
    render: (args) => ({
        components: { FormField, TextInput },
        setup: () => ({ args }),
        template: `
            <FormField v-bind="args" v-slot="{ id, describedby, invalid }">
                <TextInput :id="id" type="email" :describedby="describedby" :invalid="invalid" />
            </FormField>
        `,
    }),
} satisfies Meta<typeof FormField>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = { args: { help: 'We only use this to send submission receipts.' } };
export const Required: Story = { args: { required: true } };
export const WithError: Story = { args: { error: 'Enter a valid email address.' } };
export const Dark: Story = {
    args: { help: 'We only use this to send submission receipts.' },
    decorators: [dark],
};
export const WithErrorDark: Story = {
    args: { error: 'Enter a valid email address.' },
    decorators: [dark],
};
