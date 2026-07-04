import type { Meta, StoryObj } from '@storybook/vue3';
import Button from './Button.vue';

const meta = {
    title: 'Components/Button',
    component: Button,
    tags: ['autodocs'],
    argTypes: {
        variant: {
            control: 'select',
            options: ['primary', 'secondary', 'destructive'],
        },
        disabled: { control: 'boolean' },
        label: { control: 'text' },
    },
    args: {
        variant: 'primary',
        disabled: false,
        label: 'Save form',
    },
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
export const Destructive: Story = { args: { variant: 'destructive' } };
export const Disabled: Story = { args: { disabled: true } };
