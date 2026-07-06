<script setup lang="ts">
/**
 * Forms list + draft/publish lifecycle (Increment D3). Lists the tenant's forms in a DataTable with
 * status Badges + per-row actions (rename, publish, archive, version history/restore) driven through
 * confirm Modals; all writes hit the D3 endpoints and surface outcome as a Toast via the controller
 * flash. The interactive section/field builder is Increment D4 — "Rename" edits metadata for now.
 * Assembled entirely from shared design-system components (no page-local styling beyond layout).
 */
import { reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFormField,
    MdsIconButton,
    MdsModal,
    MdsTextInput,
    MdsTextarea,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

type FormVersionRow = {
    id: string;
    version_number: number;
    status: string;
    published_at: string | null;
};

type FormRow = {
    id: string;
    title: string;
    description: string | null;
    status: string;
    current_version: number | null;
    draft_version: number | null;
    updated_at: string | null;
    versions: FormVersionRow[];
    can: { edit: boolean; publish: boolean; delete: boolean };
};

defineProps<{ forms: FormRow[] }>();

const columns: DataTableColumn[] = [
    { key: 'title', header: 'Form', sortable: true },
    { key: 'status', header: 'Status' },
    { key: 'version', header: 'Version' },
    { key: 'updated_at', header: 'Updated', sortable: true },
];

function versionLabel(row: FormRow): string {
    if (row.current_version !== null) return `v${row.current_version} live`;
    if (row.draft_version !== null) return `v${row.draft_version} draft`;
    return '—';
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

// ── Create ──────────────────────────────────────────────────────────────
const createOpen = ref(false);
const createForm = useForm({ title: '', description: '' });

function openCreate(): void {
    createForm.reset();
    createForm.clearErrors();
    createOpen.value = true;
}

function submitCreate(): void {
    createForm.post('/forms', {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
        },
    });
}

// ── Rename / edit metadata ───────────────────────────────────────────────
const editTarget = ref<FormRow | null>(null);
const editForm = useForm({ title: '', description: '' });

function openEdit(row: FormRow): void {
    editTarget.value = row;
    editForm.title = row.title;
    editForm.description = row.description ?? '';
    editForm.clearErrors();
}

function submitEdit(): void {
    if (!editTarget.value) return;
    editForm.patch(`/forms/${editTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editTarget.value = null;
        },
    });
}

// ── Publish ──────────────────────────────────────────────────────────────
const publishing = reactive<{ id: string | null }>({ id: null });

function publish(row: FormRow): void {
    publishing.id = row.id;
    router.post(
        `/forms/${row.id}/publish`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                publishing.id = null;
            },
        },
    );
}

// ── Archive ──────────────────────────────────────────────────────────────
const archiveTarget = ref<FormRow | null>(null);
const archiving = reactive({ busy: false });

function submitArchive(): void {
    if (!archiveTarget.value) return;
    archiving.busy = true;
    router.post(
        `/forms/${archiveTarget.value.id}/archive`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                archiving.busy = false;
            },
            onSuccess: () => {
                archiveTarget.value = null;
            },
        },
    );
}

// ── Version history + restore ─────────────────────────────────────────────
const historyTarget = ref<FormRow | null>(null);
const restoreTarget = ref<{ form: FormRow; version: FormVersionRow } | null>(null);
const restoring = reactive({ busy: false });

function submitRestore(): void {
    if (!restoreTarget.value) return;
    restoring.busy = true;
    const { form, version } = restoreTarget.value;
    router.post(
        `/forms/${form.id}/versions/${version.id}/restore`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                restoring.busy = false;
            },
            onSuccess: () => {
                restoreTarget.value = null;
                historyTarget.value = null;
            },
        },
    );
}
</script>

<template>
    <div>
        <Head title="Forms" />

        <PageHeader title="Forms" icon="forms">
            <template #actions>
                <MdsButton variant="primary" icon-left="plus" @click="openCreate">New form</MdsButton>
            </template>
        </PageHeader>

        <MdsDataTable :columns="columns" :rows="forms" caption="Forms" row-key="id">
            <template #cell-title="{ row }">
                <Link v-if="row.can.edit" :href="`/forms/${row.id}/builder`" class="forms__title-link">
                    {{ row.title }}
                </Link>
                <span v-else>{{ row.title }}</span>
            </template>
            <template #cell-status="{ value }">
                <MdsBadge v-bind="statusVariant(String(value))" />
            </template>
            <template #cell-version="{ row }">
                {{ versionLabel(row) }}
            </template>
            <template #cell-updated_at="{ row }">
                {{ formatDate(row.updated_at) }}
            </template>
            <template #row-actions="{ row }">
                <div class="forms__actions">
                    <MdsIconButton icon="clock" label="Version history" size="sm" @click="historyTarget = row" />
                    <MdsIconButton
                        v-if="row.can.edit"
                        icon="edit"
                        label="Rename form"
                        size="sm"
                        @click="openEdit(row)"
                    />
                    <MdsIconButton
                        v-if="row.can.publish"
                        icon="check"
                        label="Publish form"
                        size="sm"
                        :loading="publishing.id === row.id"
                        @click="publish(row)"
                    />
                    <MdsIconButton
                        v-if="row.can.delete"
                        icon="trash"
                        label="Archive form"
                        variant="danger"
                        size="sm"
                        @click="archiveTarget = row"
                    />
                </div>
            </template>
            <template #empty>
                <MdsEmptyState
                    headline="No forms yet"
                    description="Create your first form to start collecting responses."
                >
                    <template #action>
                        <MdsButton variant="primary" icon-left="plus" @click="openCreate">New form</MdsButton>
                    </template>
                </MdsEmptyState>
            </template>
        </MdsDataTable>

        <!-- Create -->
        <MdsModal v-model:open="createOpen" title="New form">
            <form class="forms__form" @submit.prevent="submitCreate">
                <MdsFormField label="Title" required :error="createForm.errors.title" v-slot="{ id, describedby, invalid }">
                    <MdsTextInput
                        :id="id"
                        v-model="createForm.title"
                        :describedby="describedby"
                        :invalid="invalid"
                        placeholder="Household survey"
                    />
                </MdsFormField>
                <MdsFormField label="Description" :error="createForm.errors.description" v-slot="{ id, describedby, invalid }">
                    <MdsTextarea
                        :id="id"
                        v-model="createForm.description"
                        :describedby="describedby"
                        :invalid="invalid"
                        placeholder="What is this form for?"
                    />
                </MdsFormField>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="createOpen = false">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="plus" :loading="createForm.processing" @click="submitCreate">
                    Create form
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Rename -->
        <MdsModal :open="editTarget !== null" title="Rename form" @close="editTarget = null">
            <form class="forms__form" @submit.prevent="submitEdit">
                <MdsFormField label="Title" required :error="editForm.errors.title" v-slot="{ id, describedby, invalid }">
                    <MdsTextInput :id="id" v-model="editForm.title" :describedby="describedby" :invalid="invalid" />
                </MdsFormField>
                <MdsFormField label="Description" :error="editForm.errors.description" v-slot="{ id, describedby, invalid }">
                    <MdsTextarea :id="id" v-model="editForm.description" :describedby="describedby" :invalid="invalid" />
                </MdsFormField>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="editTarget = null">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="check" :loading="editForm.processing" @click="submitEdit">
                    Save changes
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Archive -->
        <MdsModal :open="archiveTarget !== null" title="Archive form" @close="archiveTarget = null">
            <p class="forms__prose">
                Archive <strong>{{ archiveTarget?.title }}</strong>? Its current draft is discarded. Every
                published version and the responses collected against them are kept.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="archiveTarget = null">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" :loading="archiving.busy" @click="submitArchive">
                    Archive form
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Version history -->
        <MdsModal :open="historyTarget !== null" title="Version history" @close="historyTarget = null">
            <ul v-if="historyTarget" class="forms__versions">
                <li v-for="v in historyTarget.versions" :key="v.id" class="forms__version">
                    <span class="forms__version-label">
                        <strong>v{{ v.version_number }}</strong>
                        <MdsBadge v-bind="statusVariant(v.status)" />
                    </span>
                    <MdsButton
                        v-if="historyTarget.can.edit && v.status !== 'draft'"
                        variant="tertiary"
                        size="sm"
                        icon-left="clock"
                        @click="restoreTarget = { form: historyTarget, version: v }"
                    >
                        Restore
                    </MdsButton>
                </li>
            </ul>
            <template #actions>
                <MdsButton variant="tertiary" @click="historyTarget = null">Close</MdsButton>
            </template>
        </MdsModal>

        <!-- Restore confirm -->
        <MdsModal :open="restoreTarget !== null" title="Restore version" @close="restoreTarget = null">
            <p class="forms__prose">
                Restore <strong>v{{ restoreTarget?.version.version_number }}</strong> into the current draft?
                This overwrites the draft's current content. Published versions are untouched — review the
                restored draft, then publish it as a new version.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="restoreTarget = null">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="clock" :loading="restoring.busy" @click="submitRestore">
                    Restore into draft
                </MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.forms__actions {
    display: inline-flex;
    gap: var(--mds-space-1);
    justify-content: flex-end;
}

.forms__title-link {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-font-weight-medium);
    text-decoration: none;
}

.forms__title-link:hover {
    text-decoration: underline;
}

.forms__title-link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.forms__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.forms__prose {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.forms__versions {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.forms__version {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
    padding: var(--mds-space-2) 0;
    border-bottom: 1px solid var(--mds-color-border-default);
}

.forms__version-label {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-body);
}
</style>
