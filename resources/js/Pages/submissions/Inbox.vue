<script setup lang="ts">
/**
 * Submissions inbox (Increment F7) — the authenticated list over every Submission Pipeline channel. Rows are
 * RLS + role-scoped server-side (SubmissionInboxPresenter::visibleTo); this page renders them in a DataTable
 * with status Badges, filters (form / status / source), server pagination, and a per-form streamed export.
 * Export is enabled only once a single form is chosen (its columns are that form's fields). Assembled entirely
 * from shared design-system components (no page-local styling beyond layout).
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsIconButton,
    MdsPagination,
    MdsSelect,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

type Option = { value: string; label: string };

type SubmissionRow = {
    id: string;
    form_title: string;
    status: string;
    source: string;
    source_label: string;
    respondent: string;
    submitted_at: string | null;
    // Increment H10 — draft progress; non-null only on `status=draft` rows (hidden by default; visible under
    // the Draft status filter).
    completeness_percent: number | null;
    last_saved_at: string | null;
    draft_expires_at: string | null;
    // Increment I9b — whether THIS viewer may pick the draft up. False on every non-draft row by
    // construction, so the button is not merely hidden by CSS on rows it would 404 on.
    can: { resume: boolean };
};

type Meta = { current_page: number; last_page: number; total: number; per_page: number };

const props = defineProps<{
    data: SubmissionRow[];
    meta: Meta;
    filters: {
        forms: Option[];
        statuses: Option[];
        sources: Option[];
        applied: { form_id: string | null; status: string | null; source: string | null };
    };
    can: { export: boolean };
}>();

const columns: DataTableColumn[] = [
    { key: 'form_title', header: 'Form', sortable: true },
    { key: 'status', header: 'Status' },
    { key: 'source_label', header: 'Source' },
    { key: 'respondent', header: 'Respondent' },
    { key: 'submitted_at', header: 'Submitted', sortable: true },
];

// Local filter state, seeded from what the server applied. Each change re-queries (page resets to 1).
const selected = reactive({
    form_id: props.filters.applied.form_id ?? '',
    status: props.filters.applied.status ?? '',
    source: props.filters.applied.source ?? '',
});

const formOptions = [{ value: '', label: 'All forms' }, ...props.filters.forms];
const statusOptions = [{ value: '', label: 'All statuses' }, ...props.filters.statuses];
const sourceOptions = [{ value: '', label: 'All sources' }, ...props.filters.sources];

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (selected.form_id) params.form_id = selected.form_id;
    if (selected.status) params.status = selected.status;
    if (selected.source) params.source = selected.source;
    return { ...params, ...extra };
}

function applyFilters(): void {
    router.get('/submissions', queryParams(), { preserveState: true, preserveScroll: true, replace: true });
}

function goToPage(page: number): void {
    router.get('/submissions', queryParams({ page }), { preserveState: true, preserveScroll: true });
}

function download(format: 'csv' | 'xlsx'): void {
    const params = new URLSearchParams({ format });
    if (selected.status) params.set('status', selected.status);
    if (selected.source) params.set('source', selected.source);
    window.location.href = `/forms/${selected.form_id}/submissions/export?${params.toString()}`;
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <div>
        <Head title="Submissions" />

        <PageHeader title="Submissions" icon="submissions">
            <template #actions>
                <template v-if="can.export && selected.form_id">
                    <MdsButton variant="secondary" icon-left="download" @click="download('csv')">Export CSV</MdsButton>
                    <MdsButton variant="secondary" icon-left="download" @click="download('xlsx')">Export XLSX</MdsButton>
                </template>
            </template>
        </PageHeader>

        <div class="inbox__filters">
            <MdsSelect
                v-model="selected.form_id"
                :options="formOptions"
                aria-label="Filter by form"
                @update:model-value="applyFilters"
            />
            <MdsSelect
                v-model="selected.status"
                :options="statusOptions"
                aria-label="Filter by status"
                @update:model-value="applyFilters"
            />
            <MdsSelect
                v-model="selected.source"
                :options="sourceOptions"
                aria-label="Filter by source"
                @update:model-value="applyFilters"
            />
        </div>

        <p v-if="!selected.status" class="inbox__hint">
            In-progress drafts are hidden. Choose the <strong>Draft</strong> status to see them.
        </p>

        <MdsDataTable :columns="columns" :rows="data" caption="Submissions" row-key="id">
            <template #cell-status="{ row }">
                <div class="inbox__status">
                    <MdsBadge v-bind="statusVariant((row as SubmissionRow).status)" />
                    <span
                        v-if="(row as SubmissionRow).status === 'draft' && (row as SubmissionRow).completeness_percent !== null"
                        class="inbox__progress"
                    >
                        {{ (row as SubmissionRow).completeness_percent }}%
                    </span>
                </div>
            </template>
            <template #cell-submitted_at="{ row }">
                <template v-if="(row as SubmissionRow).status === 'draft'">
                    <span class="inbox__saved">Saved {{ formatDate((row as SubmissionRow).last_saved_at) }}</span>
                </template>
                <template v-else>
                    {{ formatDate((row as SubmissionRow).submitted_at) }}
                </template>
            </template>
            <template #row-actions="{ row }">
                <!-- Increment I9b. Leftmost and primary on a draft row, because on the Draft-filtered view
                     "continue this" is the only thing a keyer came here to do — the detail page for a draft
                     shows an answer document nobody has finished writing. -->
                <MdsIconButton
                    v-if="(row as SubmissionRow).can?.resume"
                    icon="edit"
                    label="Continue this draft"
                    size="sm"
                    @click="router.visit(`/submissions/${(row as SubmissionRow).id}/resume`)"
                />
                <MdsIconButton
                    icon="external-link"
                    label="View submission"
                    size="sm"
                    @click="router.visit(`/submissions/${(row as SubmissionRow).id}`)"
                />
            </template>
            <template #empty>
                <MdsEmptyState
                    :illustration="selected.form_id || selected.status || selected.source ? 'search' : 'default'"
                    headline="No submissions"
                    description="Responses appear here as forms are filled out through any channel."
                />
            </template>
        </MdsDataTable>

        <MdsPagination
            :current-page="meta.current_page"
            :last-page="meta.last_page"
            :total="meta.total"
            :per-page="meta.per_page"
            @update:page="goToPage"
        />
    </div>
</template>

<style scoped>
.inbox__filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-3);
    margin-bottom: var(--mds-space-5);
}

.inbox__filters :deep(.mds-select) {
    min-width: 12rem;
}

.inbox__hint {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.inbox__status {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
}

.inbox__progress {
    font-size: var(--mds-type-body-sm-font-size);
    font-variant-numeric: tabular-nums;
    color: var(--mds-color-text-secondary);
}

.inbox__saved {
    color: var(--mds-color-text-secondary);
}
</style>
