import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import DataTable, { type DataTableColumn } from './DataTable.vue';
import Badge from '../Badge/Badge.vue';
import Button from '../Button/Button.vue';
import EmptyState from '../EmptyState/EmptyState.vue';
import { statusVariant } from '../Badge/status-variant';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const columns: DataTableColumn[] = [
    { key: 'name', header: 'Name', sortable: true },
    { key: 'email', header: 'Email' },
    { key: 'role', header: 'Role' },
    { key: 'status', header: 'Status' },
];

const rows = [
    { id: '1', name: 'Ada Okafor', email: 'ada@acme.test', role: 'Owner', status: 'active' },
    { id: '2', name: 'Jordan Vega', email: 'jordan@acme.test', role: 'Admin', status: 'active' },
    { id: '3', name: 'Priya Raman', email: 'priya@acme.test', role: 'Reviewer', status: 'invited' },
    { id: '4', name: 'Sam Lee', email: 'sam@acme.test', role: 'Viewer', status: 'suspended' },
];

const meta = {
    title: 'Components/DataTable',
    component: DataTable,
    tags: ['autodocs'],
    args: { columns, rows, caption: 'Workspace members', loading: false },
    render: (args) => ({
        components: { DataTable, Badge, Button },
        setup: () => ({ args, statusVariant }),
        template: `
            <DataTable v-bind="args">
                <template #cell-status="{ value }">
                    <Badge v-bind="statusVariant(value)" />
                </template>
                <template #row-actions>
                    <Button variant="tertiary" size="sm">Manage</Button>
                </template>
            </DataTable>
        `,
    }),
} satisfies Meta<typeof DataTable>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const Loading: Story = { args: { loading: true } };
export const DefaultDark: Story = { decorators: [dark] };

export const Empty: Story = {
    args: { rows: [] },
    render: (args) => ({
        components: { DataTable, EmptyState },
        setup: () => ({ args }),
        template: `
            <DataTable v-bind="args">
                <template #empty>
                    <EmptyState headline="No members yet" description="Invite a teammate to get started." />
                </template>
            </DataTable>
        `,
    }),
};

/* JR2: both of these gained something dark-specific and neither had a dark story. `Empty` now renders
   an `EmptyState` whose new medallion uses the one accent fill that is redeclared for dark, and it is
   the composition — a tinted field inside a table inside the page — that decides whether the two
   surfaces separate. `Loading` renders skeletons under the retyped uppercase header. */
export const EmptyDark: Story = {
    ...Empty,
    decorators: [dark],
};
export const LoadingDark: Story = { args: { loading: true }, decorators: [dark] };

/* JR4 — the collapsed layout, which until now existed only below a 480px VIEWPORT and was therefore
   rendered by exactly one Playwright project and no story at all. Keying the collapse on the container
   is what makes it storyable: the decorator below hands the table a narrow box and the cards appear at
   whatever width the runner happens to use.
   46em (736px) is chosen rather than rounded — under the 56em threshold, and over the 2 × 20em + gap the
   row grid needs for two tracks, so this renders the 834px TABLET shape (2-up) rather than the phone one.
   These three are also the only machine-checked a11y coverage the stacked layout will ever have: the
   axe run is per-story, and no app page is in Storybook's glob. */
const narrow: Decorator = (story) => ({
    components: { story },
    template: '<div style="max-width:46em"><story /></div>',
});

export const Stacked: Story = { decorators: [narrow] };
export const StackedDark: Story = { decorators: [narrow, dark] };

/* The `colspan` cell is the part of the stacked grid most likely to ship wrong — a colspan means nothing
   to a grid, so without `grid-column: 1 / -1` the empty state is squeezed into one 20em track. axe
   cannot see a layout; a person looking at this story is the gate. */
export const StackedEmpty: Story = {
    ...Empty,
    decorators: [narrow],
};
