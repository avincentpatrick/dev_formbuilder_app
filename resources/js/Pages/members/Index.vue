<script setup lang="ts">
/**
 * Members management (multi-tenancy-rbac-design.md §7). The Owner/Admin roster page — lists active
 * members + pending invites in a DataTable, with status Badges and per-row actions (remove, transfer
 * ownership) driven through confirm Modals. All writes hit the existing B2b endpoints; success
 * surfaces as a Toast via the controller's flash. Assembled entirely from shared design-system
 * components (no page-local styling beyond layout).
 */
import { reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFormField,
    MdsModal,
    MdsSegmentedControl,
    MdsTextInput,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

type Member = {
    user_id: string;
    name: string;
    email: string;
    status: string;
    role: string;
    is_owner: boolean;
    joined_at: string | null;
    invited_at: string | null;
};

const props = defineProps<{
    members: Member[];
    assignableRoles: { value: string; label: string }[];
}>();

const page = usePage();
const can = page.props.auth.can;

const columns: DataTableColumn[] = [
    { key: 'name', header: 'Name', sortable: true },
    { key: 'email', header: 'Email', sortable: true },
    { key: 'role', header: 'Role' },
    { key: 'status', header: 'Status' },
];

// ── Invite ──────────────────────────────────────────────────────────────
const inviteOpen = ref(false);
const invite = useForm({ email: '', role: props.assignableRoles[0]?.value ?? 'viewer' });

function openInvite(): void {
    invite.reset();
    invite.clearErrors();
    inviteOpen.value = true;
}

function submitInvite(): void {
    invite.post('/members/invitations', {
        preserveScroll: true,
        onSuccess: () => {
            inviteOpen.value = false;
        },
    });
}

// ── Remove ──────────────────────────────────────────────────────────────
const removeTarget = ref<Member | null>(null);
const removing = reactive({ busy: false });

function submitRemove(): void {
    if (!removeTarget.value) return;
    removing.busy = true;
    router.delete(`/members/${removeTarget.value.user_id}`, {
        preserveScroll: true,
        onFinish: () => {
            removing.busy = false;
        },
        onSuccess: () => {
            removeTarget.value = null;
        },
    });
}

// ── Transfer ownership ──────────────────────────────────────────────────
const transferTarget = ref<Member | null>(null);
const transferring = reactive({ busy: false });

function submitTransfer(): void {
    if (!transferTarget.value) return;
    transferring.busy = true;
    router.post(
        '/members/ownership',
        { user: transferTarget.value.user_id },
        {
            preserveScroll: true,
            onFinish: () => {
                transferring.busy = false;
            },
            onSuccess: () => {
                transferTarget.value = null;
            },
        },
    );
}

// A row can be removed if it's an active, non-owner member (pending invites + the owner can't be).
function canRemove(row: Member): boolean {
    return row.status === 'active' && !row.is_owner;
}
function canTransfer(row: Member): boolean {
    return can.transferOwnership && row.status === 'active' && !row.is_owner;
}
</script>

<template>
    <div>
        <Head title="Members" />

        <PageHeader title="Members" icon="users">
            <template #actions>
                <MdsButton variant="primary" icon-left="user-plus" @click="openInvite">Invite member</MdsButton>
            </template>
        </PageHeader>

        <MdsDataTable :columns="columns" :rows="members" caption="Workspace members" row-key="user_id">
            <template #cell-status="{ value }">
                <MdsBadge v-bind="statusVariant(String(value))" />
            </template>
            <template #row-actions="{ row }">
                <div class="members__actions">
                    <MdsButton
                        v-if="canTransfer(row)"
                        variant="tertiary"
                        size="sm"
                        icon-left="shield"
                        @click="transferTarget = row"
                    >
                        Make owner
                    </MdsButton>
                    <MdsButton
                        v-if="canRemove(row)"
                        variant="tertiary"
                        size="sm"
                        icon-left="trash"
                        @click="removeTarget = row"
                    >
                        Remove
                    </MdsButton>
                </div>
            </template>
            <template #empty>
                <MdsEmptyState
                    headline="No members yet"
                    description="Invite a teammate to collaborate in this workspace."
                >
                    <template #action>
                        <MdsButton variant="primary" icon-left="user-plus" @click="openInvite">Invite member</MdsButton>
                    </template>
                </MdsEmptyState>
            </template>
        </MdsDataTable>

        <!-- Invite -->
        <MdsModal v-model:open="inviteOpen" title="Invite a member">
            <form class="members__form" @submit.prevent="submitInvite">
                <MdsFormField
                    label="Email address"
                    required
                    :error="invite.errors.email"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsTextInput
                        :id="id"
                        v-model="invite.email"
                        type="email"
                        autocomplete="off"
                        :describedby="describedby"
                        :invalid="invalid"
                        placeholder="teammate@example.com"
                    />
                </MdsFormField>

                <MdsFormField label="Role" :error="invite.errors.role">
                    <MdsSegmentedControl v-model="invite.role" :options="assignableRoles" ariaLabel="Role" />
                </MdsFormField>
            </form>

            <template #actions>
                <MdsButton variant="tertiary" @click="inviteOpen = false">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="mail" :loading="invite.processing" @click="submitInvite">
                    Send invitation
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Remove -->
        <MdsModal
            :open="removeTarget !== null"
            title="Remove member"
            @close="removeTarget = null"
        >
            <p class="members__prose">
                Remove <strong>{{ removeTarget?.name }}</strong> ({{ removeTarget?.email }}) from this
                workspace? They lose access immediately. Submissions they created are retained.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="removeTarget = null">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" :loading="removing.busy" @click="submitRemove">
                    Remove member
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Transfer ownership -->
        <MdsModal
            :open="transferTarget !== null"
            title="Transfer ownership"
            @close="transferTarget = null"
        >
            <p class="members__prose">
                Make <strong>{{ transferTarget?.name }}</strong> the new Owner? You will be demoted to
                Admin. Only the Owner can transfer ownership or manage billing.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="transferTarget = null">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="shield" :loading="transferring.busy" @click="submitTransfer">
                    Transfer ownership
                </MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.members__actions {
    display: inline-flex;
    gap: var(--mds-space-1);
    justify-content: flex-end;
}

.members__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.members__prose {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}
</style>
