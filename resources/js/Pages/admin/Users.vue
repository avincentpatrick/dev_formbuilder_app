<script setup lang="ts">
/**
 * Super-admin cross-tenant user list (RBAC §9). The rows are visible only because SuperAdminService
 * read them through the elevated `superadmin_bypass` RLS policy (B2c). Assembled from shared components.
 *
 * ✅ M107 — THIS PAGE'S DOCBLOCK SAID "read-only for now (per-user platform actions are a later
 * increment)", AND THIS IS THAT INCREMENT. It carries exactly one action: clearing a two-factor
 * enrolment, answering `D37`. The sentence is replaced rather than left standing beside a contradiction,
 * which is the failure mode `routes/admin.php`'s own ⚠️ block records for a comment nobody re-reads.
 *
 * ⚠️ WHY THIS SURFACE EXISTS WHEN `/members` ALREADY HAS ONE. The workspace roster only ever sees ACTIVE
 * MEMBERS OF ONE WORKSPACE, so it cannot reach an account that belongs to no workspace at all — the shape
 * `E2eSeeder`'s own two-factor fixture has — nor rescue the sole Owner of a workspace, who has nobody
 * above them to ask. This page is the answer to both.
 */
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { MdsButton, MdsDataTable, MdsModal, type DataTableColumn } from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';

type ConsoleUser = { id: string; name: string; email: string; two_factor_enrolled: boolean };

defineProps<{ users: ConsoleUser[] }>();

const columns: DataTableColumn[] = [
    { key: 'name', header: 'Name', sortable: true },
    { key: 'email', header: 'Email', sortable: true },
];

const twoFactorTarget = ref<ConsoleUser | null>(null);
const resetting = reactive({ busy: false });
const resetError = ref<string | null>(null);

function submitTwoFactorReset(): void {
    if (!twoFactorTarget.value) return;
    resetting.busy = true;
    resetError.value = null;
    // The target is a raw uuid in the BODY, never a path segment — the decision
    // `TwoFactorResetController` and `ImpersonationController` both record: route-model binding resolves
    // on the app connection, which has no tenant context on the central host, so RLS would 404 every
    // valid id. `TenantDetail.vue`'s impersonation post is the same shape.
    router.post(
        '/admin/users/two-factor-reset',
        { user_id: twoFactorTarget.value.id },
        {
            preserveScroll: true,
            onError: (errors) => {
                resetError.value = errors.user_id ?? 'The reset could not be applied.';
            },
            onFinish: () => {
                resetting.busy = false;
            },
            onSuccess: () => {
                twoFactorTarget.value = null;
            },
        },
    );
}

function openReset(row: ConsoleUser): void {
    resetError.value = null;
    twoFactorTarget.value = row;
}
</script>

<template>
    <AdminLayout title="All users" icon="users">
        <MdsDataTable :columns="columns" :rows="users" caption="All users across tenants" row-key="id">
            <template #row-actions="{ row }">
                <!-- Conditional wrapper, for the reason `members/Index.vue` records: every button inside
                     is individually gated, and a row that passes none of them otherwise renders an empty
                     strip at the foot of its card below the table breakpoint. -->
                <div v-if="(row as ConsoleUser).two_factor_enrolled">
                    <MdsButton
                        variant="tertiary"
                        size="sm"
                        icon-left="undo"
                        @click="openReset(row as ConsoleUser)"
                    >
                        Reset two-step
                    </MdsButton>
                </div>
            </template>
        </MdsDataTable>

        <MdsModal
            :open="twoFactorTarget !== null"
            title="Reset two-step sign-in"
            @close="twoFactorTarget = null"
        >
            <p>
                Turn off two-step sign-in for <strong>{{ twoFactorTarget?.name }}</strong>
                ({{ twoFactorTarget?.email }})? They will sign in with their password alone until they set
                it up again, and their existing recovery codes stop working.
            </p>
            <p>This is recorded in the platform audit log against your account.</p>
            <p v-if="resetError" role="alert">{{ resetError }}</p>
            <template #actions>
                <MdsButton variant="tertiary" @click="twoFactorTarget = null">Cancel</MdsButton>
                <MdsButton
                    variant="destructive"
                    icon-left="undo"
                    :loading="resetting.busy"
                    @click="submitTwoFactorReset"
                >
                    Reset two-step sign-in
                </MdsButton>
            </template>
        </MdsModal>
    </AdminLayout>
</template>
