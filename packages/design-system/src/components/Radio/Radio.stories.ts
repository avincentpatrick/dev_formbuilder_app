import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Radio from './Radio.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Radio',
    component: Radio,
    tags: ['autodocs'],
    argTypes: {
        modelValue: { control: 'text' },
        label: { control: 'text' },
        invalid: { control: 'boolean' },
        disabled: { control: 'boolean' },
    },
    args: { modelValue: '', value: 'yes', name: 'story-radio', label: 'Yes, contact me' },
} satisfies Meta<typeof Radio>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Unselected: Story = { args: { modelValue: '' } };
export const Selected: Story = { args: { modelValue: 'yes' } };
export const Invalid: Story = { args: { modelValue: '', invalid: true, label: 'Choose one' } };
export const Disabled: Story = { args: { modelValue: 'yes', disabled: true } };

export const UnselectedDark: Story = { args: { modelValue: '' }, decorators: [dark] };
export const SelectedDark: Story = { args: { modelValue: 'yes' }, decorators: [dark] };
export const InvalidDark: Story = { args: { modelValue: '', invalid: true, label: 'Choose one' }, decorators: [dark] };
