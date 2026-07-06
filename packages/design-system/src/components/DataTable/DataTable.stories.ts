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
