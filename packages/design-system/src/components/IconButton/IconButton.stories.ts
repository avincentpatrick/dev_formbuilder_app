import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import IconButton from './IconButton.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

// `label` is required and drives the accessible name, so axe's button-name check is satisfied by design.
const meta = {
    title: 'Components/IconButton',
    component: IconButton,
    tags: ['autodocs'],
    argTypes: {
        icon: { control: 'text' },
        variant: { control: 'inline-radio', options: ['tertiary', 'danger'] },
        size: { control: 'inline-radio', options: ['sm', 'md'] },
        disabled: { control: 'boolean' },
        loading: { control: 'boolean' },
    },
    args: { icon: 'edit', label: 'Rename form', variant: 'tertiary', size: 'md', disabled: false, loading: false },
} satisfies Meta<typeof IconButton>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const Small: Story = { args: { size: 'sm' } };
export const Danger: Story = { args: { icon: 'trash', label: 'Archive form', variant: 'danger' } };
export const Disabled: Story = { args: { disabled: true } };
export const Dark: Story = { decorators: [dark] };
export const DangerDark: Story = {
    args: { icon: 'trash', label: 'Archive form', variant: 'danger' },
    decorators: [dark],
};
