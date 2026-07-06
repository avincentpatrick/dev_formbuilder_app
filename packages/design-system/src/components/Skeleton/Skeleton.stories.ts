import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Skeleton from './Skeleton.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Skeleton',
    component: Skeleton,
    tags: ['autodocs'],
    argTypes: {
        variant: { control: 'select', options: ['text', 'block', 'circle'] },
        width: { control: 'text' },
        height: { control: 'text' },
    },
    args: { variant: 'text', width: '200px' },
} satisfies Meta<typeof Skeleton>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Text: Story = { args: { variant: 'text', width: '240px' } };
export const Block: Story = { args: { variant: 'block', width: '320px', height: '80px' } };
export const Circle: Story = { args: { variant: 'circle' } };

export const TextDark: Story = { args: { variant: 'text', width: '240px' }, decorators: [dark] };
export const BlockDark: Story = {
    args: { variant: 'block', width: '320px', height: '80px' },
    decorators: [dark],
};
