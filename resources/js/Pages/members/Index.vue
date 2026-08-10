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
    MdsFilterBar,
    MdsFormField,
    MdsModal,
    MdsSearchField,
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
    filters: { applied: { q: string | null } };
    empty_reason: 'no_matches' | 'no_rows' | null;
}>();

const page = usePage();
const can = page.props.auth.can;

const columns: DataTableColumn[] = [
    { key: 'name', header: 'Name', sortable: true },
    { key: 'email', header: 'Email', sortable: true },
    { key: 'role', header: 'Role' },
    { key: 'status', header: 'Status' },
];

// ── Keyword filter (J1e) ────────────────────────────────────────────────
//
// ⚠️ IT IS A SERVER ROUND-TRIP, NOT A `computed()` OVER `members`, EVEN THOUGH THE WHOLE ROSTER IS ALREADY
// ON THIS PAGE. Filtering here in JavaScript would be cheaper and would look identical — and it would put
// the one list whose identities come off the pre-auth connection outside the server's reach, so the next
// person to add pagination or a directory sync inherits a filter nobody can see from PHP.
// `TenantMembershipService::listMembers()` owns the predicate, in PHP, over rows it already bounded.
const selected = reactive({ q: props.filters.applied.q ?? '' });
const busy = ref(false);

function applyFilters(): void {
    router.get('/members', selected.q ? { q: selected.q } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}

function clearFilters(): void {
    selected.q = '';
    applyFilters();
}

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

// ── Change role (I8a, PRD Feature #14) ──────────────────────────────────
// A MODAL rather than an inline select, unlike the autosave switches in Settings. Two reasons: the
// route carries `step-up`, so a change may bounce the whole page to the confirm-password screen — that
// is a jarring answer to an idle click on a dropdown; and re-grading what a colleague may do deserves
// the same deliberateness as removing them, which is already a modal beside it.
const roleTarget = ref<Member | null>(null);
const roleForm = useForm({ role: 'viewer' });

function openRoleChange(row: Member): void {
    roleForm.clearErrors();
    roleForm.role = row.role;
    roleTarget.value = row;
}

function submitRoleChange(): void {
    if (!roleTarget.value) return;
    roleForm.patch(`/members/${roleTarget.value.user_id}/role`, {
        preserveScroll: true,
        onSuccess: () => {
            roleTarget.value = null;
        },
    });
}

// A row can be removed if it's an active, non-owner member (pending invites + the owner can't be).
function canRemove(row: Member): boolean {
    return row.status === 'active' && !row.is_owner;
}
function canTransfer(row: Member): boolean {
    return can.transferOwnership && row.status === 'active' && !row.is_owner;
}
// Mirrors TenantMembershipService::changeRole()'s refusals so the control is absent rather than
// present-and-rejected: the Owner's role moves only by transfer, a pending invite has no role to change
// yet (it holds a RESERVED one until acceptance), and nobody may re-grade themselves.
function canChangeRole(row: Member): boolean {
    return (
        can.assignRoles &&
        row.status === 'active' &&
        !row.is_owner &&
        row.user_id !== page.props.auth.user?.id
    );
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

        <MdsFilterBar>
            <MdsSearchField
                v-model="selected.q"
                :applied="filters.applied.q ?? ''"
                label="Search members"
                placeholder="Name or email"
                @submit="applyFilters"
            />
        </MdsFilterBar>

        <MdsDataTable :columns="columns" :rows="members" :loading="busy" caption="Workspace members" row-key="user_id">
            <template #cell-status="{ value }">
                <MdsBadge v-bind="statusVariant(String(value))" />
            </template>
            <template #row-actions="{ row }">
                <div class="members__actions">
                    <MdsButton
                        v-if="canChangeRole(row)"
                        variant="tertiary"
                        size="sm"
                        icon-left="user-cog"
                        @click="openRoleChange(row)"
                    >
                        Change role
                    </MdsButton>
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
                    v-if="empty_reason === 'no_matches'"
                    illustration="search"
                    headline="No matching members"
                    description="Nobody on this roster matches that name or email. Pending invites are searched too."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="clearFilters">Clear search</MdsButton>
                    </template>
                </MdsEmptyState>
                <MdsEmptyState
                    v-else
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

        <!-- Change role -->
        <MdsModal
            :open="roleTarget !== null"
            title="Change role"
            @close="roleTarget = null"
        >
            <form class="members__form" @submit.prevent="submitRoleChange">
                <p class="members__prose">
                    Choose a new role for <strong>{{ roleTarget?.name }}</strong> ({{ roleTarget?.email }}).
                    It takes effect on their next request.
                </p>
                <MdsFormField label="Role" :error="roleForm.errors.role">
                    <MdsSegmentedControl
                        v-model="roleForm.role"
                        :options="assignableRoles"
                        ariaLabel="Role"
                    />
                </MdsFormField>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="roleTarget = null">Cancel</MdsButton>
                <MdsButton
                    variant="primary"
                    icon-left="user-cog"
                    :loading="roleForm.processing"
                    @click="submitRoleChange"
                >
                    Change role
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
