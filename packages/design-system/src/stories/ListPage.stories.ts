import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import DataTable, { type DataTableColumn } from '../components/DataTable/DataTable.vue';
import Badge from '../components/Badge/Badge.vue';
import Button from '../components/Button/Button.vue';
import { statusVariant } from '../components/Badge/status-variant';

// A composed "list page" (heading + toolbar + data table with status badges) — the shape the Members
// roster and the admin console both use. Scanned by the merge-blocking axe runner so composed-level
// issues (heading order, landmark structure, real text-on-surface contrast) are caught in light + dark,
// complementing the live-page Playwright gate (which covers the tenant routes end-to-end).

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
    { id: '2', name: 'Priya Raman', email: 'priya@acme.test', role: 'Reviewer', status: 'invited' },
    { id: '3', name: 'Sam Lee', email: 'sam@acme.test', role: 'Viewer', status: 'suspended' },
];

const render = () => ({
    components: { DataTable, Badge, Button },
    setup: () => ({ columns, rows, statusVariant }),
    template: `
        <main style="max-width:1100px;margin:0 auto;padding:var(--mds-space-8)">
            <header style="display:flex;align-items:center;justify-content:space-between;
                           gap:var(--mds-space-4);margin-bottom:var(--mds-space-6)">
                <h1 style="margin:0;font-family:var(--mds-font-family-display);
                           font-size:var(--mds-type-heading-1-font-size);
                           line-height:var(--mds-type-heading-1-line-height);
                           text-transform:uppercase;letter-spacing:.02em;
                           color:var(--mds-color-text-heading)">Members</h1>
                <Button variant="primary">Invite member</Button>
            </header>
            <DataTable :columns="columns" :rows="rows" caption="Workspace members" row-key="id">
                <template #cell-status="{ value }">
                    <Badge v-bind="statusVariant(String(value))" />
                </template>
                <template #row-actions>
                    <Button variant="tertiary" size="sm">Remove</Button>
                </template>
            </DataTable>
        </main>
    `,
});

const meta: Meta = { title: 'Composed/List Page', render };

export default meta;

type Story = StoryObj<typeof meta>;

export const Default: Story = {};
export const Dark: Story = { decorators: [dark] };
