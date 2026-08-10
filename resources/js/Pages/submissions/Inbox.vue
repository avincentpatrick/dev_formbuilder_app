<script setup lang="ts">
/**
 * Submissions inbox (Increment F7) — the authenticated list over every Submission Pipeline channel. Rows are
 * RLS + role-scoped server-side (SubmissionInboxPresenter::visibleTo); this page renders them in a DataTable
 * with status Badges, filters (form / status / source), server pagination, and a per-form streamed export.
 * Export is enabled only once a single form is chosen (its columns are that form's fields). Assembled entirely
 * from shared design-system components (no page-local styling beyond layout).
 *
 * ── J1e NORMALISED THIS PAGE'S FILTER BAR, AND IT WAS THE ODD ONE OUT ────────────────────────────────────
 * It used to be three bare `aria-label` selects in a plain `<div>` — no visible labels, no `<h2>`, and an
 * empty state whose "did you filter?" branch was inferred on the CLIENT. That inference was already wrong:
 * it could not see `countable()`, the server's own display default that hides in-progress drafts, so an
 * inbox holding nothing but drafts told a reviewer that responses "appear here as forms are filled out".
 * All six list pages now share `MdsFilterBar` + a server-computed `empty_reason`.
 */
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFilterBar,
    MdsFormField,
    MdsIconButton,
    MdsPagination,
    MdsSearchField,
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
        applied: { form_id: string | null; status: string | null; source: string | null; q: string | null };
    };
    can: { export: boolean };
    empty_reason: 'no_matches' | 'no_rows' | null;
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
    q: props.filters.applied.q ?? '',
});

const formOptions = [{ value: '', label: 'All forms' }, ...props.filters.forms];
const statusOptions = [{ value: '', label: 'All statuses' }, ...props.filters.statuses];
const sourceOptions = [{ value: '', label: 'All sources' }, ...props.filters.sources];

/** Marks the table busy while a filter round-trip is in flight — the audit viewer's contract. */
const busy = ref(false);

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (selected.form_id) params.form_id = selected.form_id;
    if (selected.status) params.status = selected.status;
    if (selected.source) params.source = selected.source;
    if (selected.q) params.q = selected.q;
    return { ...params, ...extra };
}

function visit(params: Record<string, string | number>, replace: boolean): void {
    router.get('/submissions', params, {
        preserveState: true,
        preserveScroll: true,
        ...(replace ? { replace: true } : {}),
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}

function applyFilters(): void {
    visit(queryParams(), true);
}

function goToPage(page: number): void {
    visit(queryParams({ page }), false);
}

function clearFilters(): void {
    selected.form_id = '';
    selected.status = '';
    selected.source = '';
    selected.q = '';
    visit({}, true);
}

/**
 * ⚠️ THE KEYWORD GOES INTO THE EXPORT URL, AND OMITTING IT WOULD BE THE DEFECT RATHER THAN THE SAFE CHOICE.
 * This button reads "export what I am looking at"; a `?q` the page applied and the download ignored would
 * stream rows the reviewer cannot see on screen. `ExportSubmissionsRequest` carries `q` for this reason.
 */
function download(format: 'csv' | 'xlsx'): void {
    const params = new URLSearchParams({ format });
    if (selected.status) params.set('status', selected.status);
    if (selected.source) params.set('source', selected.source);
    if (selected.q) params.set('q', selected.q);
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

        <MdsFilterBar>
            <!--
                The keyword box is FIRST and carries no `:disabled` — see MdsSearchField for why disabling a
                focused text input mid-round-trip eats the caret. The three selects keep `:disabled="busy"`,
                which is right for a one-shot control.
            -->
            <MdsSearchField
                v-model="selected.q"
                :applied="filters.applied.q ?? ''"
                label="Search submissions"
                placeholder="Remarks, form title or reference"
                @submit="applyFilters"
            />
            <MdsFormField label="Form" input-id="inbox-form">
                <MdsSelect
                    id="inbox-form"
                    v-model="selected.form_id"
                    :options="formOptions"
                    :disabled="busy"
                    @update:model-value="applyFilters"
                />
            </MdsFormField>
            <MdsFormField label="Status" input-id="inbox-status">
                <MdsSelect
                    id="inbox-status"
                    v-model="selected.status"
                    :options="statusOptions"
                    :disabled="busy"
                    @update:model-value="applyFilters"
                />
            </MdsFormField>
            <MdsFormField label="Source" input-id="inbox-source">
                <MdsSelect
                    id="inbox-source"
                    v-model="selected.source"
                    :options="sourceOptions"
                    :disabled="busy"
                    @update:model-value="applyFilters"
                />
            </MdsFormField>
        </MdsFilterBar>

        <p v-if="!selected.status" class="inbox__hint">
            In-progress drafts are hidden. Choose the <strong>Draft</strong> status to see them.
        </p>

        <MdsDataTable :columns="columns" :rows="data" :loading="busy" caption="Submissions" row-key="id">
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
                    v-if="empty_reason === 'no_matches'"
                    illustration="search"
                    headline="No matching submissions"
                    description="Try a different keyword, or clear the filters to see everything."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="clearFilters">Clear filters</MdsButton>
                    </template>
                </MdsEmptyState>
                <MdsEmptyState
                    v-else
                    illustration="default"
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
