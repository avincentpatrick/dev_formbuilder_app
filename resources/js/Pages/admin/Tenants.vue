<script setup lang="ts">
/**
 * Super-admin tenant console (RBAC §9). Lists every tenant with a status Badge and suspend/reactivate
 * actions driven through confirm Modals. Rows come from SuperAdminService::listTenants (the RLS-exempt
 * central table). Business-rule failures surface as the shared `errors.admin` alert; success as a Toast.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
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
    <AdminLayout title="Tenants" icon="building">
        <p v-if="adminError" class="admin-tenants__alert" role="alert">{{ adminError }}</p>

        <MdsDataTable :columns="columns" :rows="tenants" caption="All tenants" row-key="id">
            <!--
                An anchor, not an icon-button in #row-actions: that cell already holds a DESTRUCTIVE action,
                and putting a navigation target beside it is the adjacency defect the DSR names. A row that
                navigates should also support middle-click, copy-link-address, and read as "link" to a
                screen reader — none of which a <button> gives. Sorting is unaffected: MdsDataTable sorts on
                `row[key]`, the raw string, not on what the slot renders.
            -->
            <template #cell-name="{ row }">
                <Link class="admin-tenants__name" :href="`/admin/tenants/${row.id}`">{{ row.name }}</Link>
            </template>
            <template #cell-status="{ value }">
                <MdsBadge v-bind="statusVariant(String(value))" dot />
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
/*
 * `status-danger-fg`, not `danger-text`: the latter resolves to danger-300 in dark and fails contrast on
 * bg-surface — recorded as a found defect on StatTile, and already fixed on admin/Feedback.vue. This was
 * the last admin page still disagreeing. /admin/* is excluded from the axe sweep, so nothing would catch it.
 */
.admin-tenants__alert {
    margin: 0 0 var(--mds-space-4);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-action-danger-bg);
    border-radius: var(--mds-radius-md);
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-md-font-size);
}

/* Always underlined, not hover-only: /admin/* is never axe-scanned, so a colour-only link would be
   invisible to every gate in the build. The token is what keeps it legible in dark. */
.admin-tenants__name {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-font-weight-medium);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.admin-tenants__name:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.admin-tenants__prose {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}
</style>
