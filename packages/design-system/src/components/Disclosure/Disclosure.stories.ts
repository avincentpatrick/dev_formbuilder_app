import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Disclosure from './Disclosure.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Disclosure',
    component: Disclosure,
    tags: ['autodocs'],
    args: { summary: 'More about this indicator', open: false },
    render: (args) => ({
        components: { Disclosure },
        setup: () => ({ args }),
        template:
            '<Disclosure v-bind="args"><p>How it is counted: the number of visits divided by the number of households, times 100.</p></Disclosure>',
    }),
} satisfies Meta<typeof Disclosure>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Closed: Story = {};
export const Open: Story = { args: { open: true } };
export const LongSummary: Story = {
    args: { summary: 'Research behind this indicator, from the studies the working group read before it was proposed', open: true },
};

export const ClosedDark: Story = { decorators: [dark] };
export const OpenDark: Story = { args: { open: true }, decorators: [dark] };
