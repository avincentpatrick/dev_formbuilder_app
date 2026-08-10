import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import SearchField from './SearchField.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/SearchField',
    component: SearchField,
    tags: ['autodocs'],
    argTypes: {
        label: { control: 'text' },
        placeholder: { control: 'text' },
        modelValue: { control: 'text' },
        applied: { control: 'text' },
    },
    args: { label: 'Search forms', placeholder: 'Title, description or slug', modelValue: '', applied: '' },
} satisfies Meta<typeof SearchField>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};

/**
 * The state a page restored from `?q=clinic` renders: the box holds the query and `applied` agrees with it,
 * so pressing Enter is a deliberate no-op rather than a redundant round-trip.
 */
export const WithQuery: Story = { args: { modelValue: 'clinic intake', applied: 'clinic intake' } };

/**
 * Mid-edit: the box has moved on and the list underneath is still showing the previous query's results.
 * This is the only state in which Enter (or a blur) actually emits `submit`.
 */
export const Uncommitted: Story = { args: { modelValue: 'clinic intak', applied: 'household' } };

export const Dark: Story = { args: { modelValue: 'clinic intake', applied: 'clinic intake' }, decorators: [dark] };
