<script setup lang="ts">
/**
 * Super-admin tenant console (RBAC §9). Lists every tenant with a status Badge and suspend/reactivate
 * actions driven through confirm Modals. Rows come from SuperAdminService::listTenants (the RLS-exempt
 * central table). Business-rule failures surface as the shared `errors.admin` alert; success as a Toast.
 */
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsModal,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';

type Tenant = {
    id: string;
    name: string;
    slug: string;
    status: string;
};

defineProps<{ tenants: Tenant[] }>();

const page = usePage();
const adminError = computed(() => page.props.errors?.admin);

const columns: DataTableColumn[] = [
    { key: 'name', header: 'Name', sortable: true },
    { key: 'slug', header: 'Slug', sortable: true },
    { key: 'status', header: 'Status' },
];

// { tenant, action } pending confirmation.
const pending = ref<{ tenant: Tenant; action: 'suspend' | 'reactivate' } | null>(null);
const busy = ref(false);

function confirmAction(): void {
    if (!pending.value) return;
    const { tenant, action } = pending.value;
    busy.value = true;
    router.post(
        `/admin/tenants/${tenant.id}/${action}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
            onSuccess: () => {
                pending.value = null;
            },
        },
    );
}
</script>

<template>
    <AdminLayout title="Tenants">
        <p v-if="adminError" class="admin-tenants__alert" role="alert">{{ adminError }}</p>

        <MdsDataTable :columns="columns" :rows="tenants" caption="All tenants" row-key="id">
            <template #cell-status="{ value }">
                <MdsBadge v-bind="statusVariant(String(value))" />
            </template>
            <template #row-actions="{ row }">
                <MdsButton
                    v-if="row.status !== 'suspended'"
                    variant="tertiary"
                    size="sm"
                    @click="pending = { tenant: row, action: 'suspend' }"
                >
                    Suspend
                </MdsButton>
                <MdsButton
                    v-else
                    variant="tertiary"
                    size="sm"
                    @click="pending = { tenant: row, action: 'reactivate' }"
                >
                    Reactivate
                </MdsButton>
            </template>
        </MdsDataTable>

        <MdsModal
            :open="pending !== null"
            :title="pending?.action === 'suspend' ? 'Suspend tenant' : 'Reactivate tenant'"
            @close="pending = null"
        >
            <p class="admin-tenants__prose">
                <template v-if="pending?.action === 'suspend'">
                    Suspend <strong>{{ pending?.tenant.name }}</strong>? Members lose access until it is
                    reactivated. Their data is retained.
                </template>
                <template v-else>
                    Reactivate <strong>{{ pending?.tenant.name }}</strong>? Members regain access
                    immediately.
                </template>
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="pending = null">Cancel</MdsButton>
                <MdsButton
                    :variant="pending?.action === 'suspend' ? 'destructive' : 'primary'"
                    :loading="busy"
                    @click="confirmAction"
                >
                    {{ pending?.action === 'suspend' ? 'Suspend' : 'Reactivate' }}
                </MdsButton>
            </template>
        </MdsModal>
    </AdminLayout>
</template>

<style scoped>
.admin-tenants__alert {
    margin: 0 0 var(--mds-space-4);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-action-danger-bg);
    border-radius: var(--mds-radius-md);
    color: var(--mds-color-danger-text);
    font-size: var(--mds-type-body-md-font-size);
}

.admin-tenants__prose {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}
</style>
