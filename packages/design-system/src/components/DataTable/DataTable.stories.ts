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
/* ⛔ THE ONLY FIXTURE IN WHICH THE SORT CHIPS WRAP, ADDED IN M20 — AND ITS ABSENCE IS WHY THE 32px TOUCH
   TARGET SURVIVED. Every stacked story above declares exactly ONE sortable column, so the sortbar has
   rendered a single chip for as long as it has existed: it has never wrapped, never had a second row to
   be 8px away from, and never had a short header to overhang. A fixture that cannot reach the defect is
   a gate reporting zero and telling you nothing.

   20em (320px) with five sortable columns is the phone shape rather than a contrivance — the stacked
   layout applies from 56em all the way down, and `docs/ACCESS-MATRIX.md`'s list pages carry four and
   five sortable columns, and the five chips here measure 282px against a 320px box, so they WRAP.
   `ID` is deliberately two characters: it is the header that would render a
   ~38px chip and push a 44px hit area past the frame's left edge on every wrapped row. */
const wrappingColumns: DataTableColumn[] = [
    { key: 'id', header: 'ID', sortable: true },
    { key: 'name', header: 'Name', sortable: true },
    { key: 'email', header: 'Email', sortable: true },
    { key: 'role', header: 'Role', sortable: true },
    { key: 'status', header: 'Status', sortable: true },
];

const phone: Decorator = (story) => ({
    components: { story },
    template: '<div style="max-width:20em"><story /></div>',
});

export const StackedSortWrap: Story = {
    args: { columns: wrappingColumns },
    decorators: [phone],
};

export const StackedSortWrapDark: Story = {
    args: { columns: wrappingColumns },
    decorators: [phone, dark],
};

export const StackedEmpty: Story = {
    ...Empty,
    decorators: [narrow],
};
