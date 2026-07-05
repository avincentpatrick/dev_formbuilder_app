import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Spinner from './Spinner.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Spinner',
    component: Spinner,
    tags: ['autodocs'],
    argTypes: { size: { control: 'select', options: ['sm', 'md', 'lg'] }, label: { control: 'text' } },
    args: { size: 'md', label: 'Loading' },
} satisfies Meta<typeof Spinner>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Small: Story = { args: { size: 'sm' } };
export const Medium: Story = { args: { size: 'md' } };
export const Large: Story = { args: { size: 'lg' } };
export const Dark: Story = { args: { size: 'md' }, decorators: [dark] };
