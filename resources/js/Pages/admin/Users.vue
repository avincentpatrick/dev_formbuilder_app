<script setup lang="ts">
/**
 * Super-admin cross-tenant user list (RBAC §9). The rows are visible only because SuperAdminService
 * read them through the elevated `superadmin_bypass` RLS policy (B2c). Read-only for now (per-user
 * platform actions are a later increment). Assembled from shared components.
 */
import { MdsDataTable, type DataTableColumn } from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps<{ users: Array<{ id: string; name: string; email: string }> }>();

const columns: DataTableColumn[] = [
    { key: 'name', header: 'Name', sortable: true },
    { key: 'email', header: 'Email', sortable: true },
];
</script>

<template>
    <AdminLayout title="All users" icon="users">
        <MdsDataTable :columns="columns" :rows="users" caption="All users across tenants" row-key="id" />
    </AdminLayout>
</template>
