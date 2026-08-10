import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import FilterBar from './FilterBar.vue';
import FormField from '../FormField/FormField.vue';
import Select from '../Select/Select.vue';
import SearchField from '../SearchField/SearchField.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

/**
 * ⚠️ EVERY STORY RENDERS AN `<h1>` ABOVE THE BAR, AND IT IS NOT DECORATION. `FilterBar`'s `<h2>` exists so
 * axe's `heading-order` rule passes on a page whose only other heading is an `MdsEmptyState` `<h3>`; a story
 * that scanned the `<h2>` with no `<h1>` above it would fail that same rule for the opposite reason and send
 * the next reader off to "fix" the component. The `<h1>` here stands in for `PageHeader`.
 */
const meta = {
    title: 'Components/FilterBar',
    component: FilterBar,
    tags: ['autodocs'],
    render: () => ({
        components: { FilterBar, FormField, Select },
        setup: () => ({
            statuses: [
                { value: '', label: 'All statuses' },
                { value: 'published', label: 'Published' },
                { value: 'draft', label: 'Draft' },
            ],
        }),
        template: `
            <div>
                <h1>Forms</h1>
                <FilterBar>
                    <FormField label="Status" input-id="fb-status">
                        <Select id="fb-status" :options="statuses" />
                    </FormField>
                </FilterBar>
            </div>
        `,
    }),
} satisfies Meta<typeof FilterBar>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};

/** The shape five of the six list pages ship: a keyword box beside one or more one-shot selects. */
export const WithSearchField: Story = {
    render: () => ({
        components: { FilterBar, FormField, Select, SearchField },
        setup: () => ({
            statuses: [
                { value: '', label: 'All statuses' },
                { value: 'new', label: 'New' },
            ],
            sources: [
                { value: '', label: 'All sources' },
                { value: 'web', label: 'Web' },
            ],
        }),
        template: `
            <div>
                <h1>Submissions</h1>
                <FilterBar>
                    <SearchField label="Search submissions" placeholder="Remarks, form title or reference" />
                    <FormField label="Status" input-id="fb2-status">
                        <Select id="fb2-status" :options="statuses" />
                    </FormField>
                    <FormField label="Source" input-id="fb2-source">
                        <Select id="fb2-source" :options="sources" />
                    </FormField>
                </FilterBar>
            </div>
        `,
    }),
};

/** One control, which is what the three pages that had no filter surface at all before J1e render. */
export const SearchOnly: Story = {
    render: () => ({
        components: { FilterBar, SearchField },
        template: `
            <div>
                <h1>Members</h1>
                <FilterBar>
                    <SearchField label="Search members" placeholder="Name or email" />
                </FilterBar>
            </div>
        `,
    }),
};

export const Dark: Story = { decorators: [dark] };
