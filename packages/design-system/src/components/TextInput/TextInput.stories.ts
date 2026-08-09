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
// Native date input (manual-encoding date fields, Increment F4b) — the browser control is accessible
// out of the box; the aria-label keeps the bare (FormField-less) story axe-scannable.
export const DateType: Story = {
    args: { type: 'date', modelValue: '2026-07-10' },
    render: (args) => ({
        components: { TextInput },
        setup: () => ({ args }),
        template: '<TextInput v-bind="args" aria-label="Visit date" />',
    }),
};
/**
 * Increment J1a — the keyword-filter and global-search field. Rendered with a value, because the UA's
 * clear affordance (`::-webkit-search-cancel-button`) only exists while the field is non-empty, and it is
 * deliberately not suppressed: axe scanning the EMPTY state would never see it at all.
 *
 * ⚠️ This story's input is `role="searchbox"`, not `textbox` — see TextInput.vue's docblock.
 */
export const Search: Story = {
    args: { type: 'search', modelValue: 'clinic intake' },
    render: (args) => ({
        components: { TextInput },
        setup: () => ({ args }),
        template: '<TextInput v-bind="args" aria-label="Search submissions" />',
    }),
};
export const Invalid: Story = { args: { invalid: true, modelValue: 'not-an-email' } };
export const Disabled: Story = { args: { disabled: true, modelValue: 'locked@example.org' } };
export const Dark: Story = { args: { modelValue: 'jordan@example.org' }, decorators: [dark] };
export const InvalidDark: Story = {
    args: { invalid: true, modelValue: 'not-an-email' },
    decorators: [dark],
};
export const SearchDark: Story = {
    args: { type: 'search', modelValue: 'clinic intake' },
    decorators: [dark],
    render: (args) => ({
        components: { TextInput },
        setup: () => ({ args }),
        template: '<TextInput v-bind="args" aria-label="Search submissions" />',
    }),
};
