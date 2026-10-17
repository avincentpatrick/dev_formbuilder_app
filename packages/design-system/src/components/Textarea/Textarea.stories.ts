import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Textarea from './Textarea.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

// The bare textarea gets an aria-label in stories (it normally gets its name from FormField's <label>)
// so axe can scan it without flagging a missing accessible name.
const meta = {
    title: 'Components/Textarea',
    component: Textarea,
    tags: ['autodocs'],
    argTypes: {
        modelValue: { control: 'text' },
        rows: { control: 'number' },
        invalid: { control: 'boolean' },
        disabled: { control: 'boolean' },
    },
    args: { modelValue: '', rows: 3, invalid: false, disabled: false },
    render: (args) => ({
        components: { Textarea },
        setup: () => ({ args }),
        template: '<Textarea v-bind="args" aria-label="Form description" />',
    }),
} satisfies Meta<typeof Textarea>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const WithValue: Story = { args: { modelValue: 'A quarterly household survey for the field team.' } };
export const Invalid: Story = { args: { invalid: true, modelValue: '' } };
export const Disabled: Story = { args: { disabled: true, modelValue: 'Locked while publishing.' } };
export const Dark: Story = {
    args: { modelValue: 'A quarterly household survey for the field team.' },
    decorators: [dark],
};
export const InvalidDark: Story = { args: { invalid: true, modelValue: '' }, decorators: [dark] };

// M176 (`R-923c5041`): the builder's question label — one line to start, growing with the text.
export const Autosize: Story = {
    args: { autosize: true, modelValue: 'How many people usually sleep in this house, including children and anyone staying for the season?' },
};
// …and saying how near its limit it is once it gets there.
export const AutosizeWithCounter: Story = {
    args: { autosize: true, maxlength: 500, countFrom: 450, modelValue: 'A long question. '.repeat(27).trim() },
};
