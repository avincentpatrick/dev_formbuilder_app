import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import { ref } from 'vue';
import SegmentedControl from './SegmentedControl.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const themeOptions = [
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    { value: 'system', label: 'Match System', icon: 'monitor' },
];

const meta = {
    title: 'Components/SegmentedControl',
    component: SegmentedControl,
    tags: ['autodocs'],
    args: { modelValue: 'system', options: themeOptions, ariaLabel: 'Theme', compact: false },
    render: (args) => ({
        components: { SegmentedControl },
        setup() {
            const value = ref(args.modelValue);
            return { args, value };
        },
        template: '<SegmentedControl v-bind="args" v-model="value" />',
    }),
} satisfies Meta<typeof SegmentedControl>;

export default meta;
type Story = StoryObj<typeof meta>;

export const System: Story = { args: { modelValue: 'system' } };
export const Light: Story = { args: { modelValue: 'light' } };
export const Dark: Story = { args: { modelValue: 'dark' } };
export const Compact: Story = { args: { modelValue: 'light', compact: true } };
export const SystemDark: Story = { args: { modelValue: 'system' }, decorators: [dark] };
export const DarkSelectedDark: Story = { args: { modelValue: 'dark' }, decorators: [dark] };
