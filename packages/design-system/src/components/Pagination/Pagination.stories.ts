import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Pagination from './Pagination.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Pagination',
    component: Pagination,
    tags: ['autodocs'],
    args: { currentPage: 2, lastPage: 6, total: 138, perPage: 25 },
} satisfies Meta<typeof Pagination>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const FirstPage: Story = { args: { currentPage: 1 } };
export const LastPage: Story = { args: { currentPage: 6 } };
export const SinglePage: Story = { args: { currentPage: 1, lastPage: 1, total: 12 } };
export const DefaultDark: Story = { decorators: [dark] };
