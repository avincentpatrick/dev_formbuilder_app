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

// M249 (`dropdown-placeholder`, `D233`): a chosen option's short label drawn over the closed box, the full text in the
// open list; and a placeholder an optional answer can be cleared back to. Both themes, for the Storybook axe scan.
const indicatorUse = [
    { value: 'include', label: 'Include. Use this indicator as it stands, with the cut-off it has today.', shortLabel: 'Include' },
    { value: 'change', label: 'Change it. Keep the indicator, but change how it is measured.', shortLabel: 'Change it' },
    { value: 'drop', label: 'Drop it' },
];
export const ShortLabel: Story = { args: { modelValue: 'include', options: indicatorUse, placeholder: 'Choose…' } };
export const ShortLabelDark: Story = { args: { modelValue: 'include', options: indicatorUse, placeholder: 'Choose…' }, decorators: [dark] };
export const Clearable: Story = { args: { modelValue: 'drop', options: indicatorUse, placeholder: 'Choose…', clearable: true } };
