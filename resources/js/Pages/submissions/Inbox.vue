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
 *
 * ── ONE COMPONENT, TWO ROUTES (Increment J2c) ────────────────────────────────────────────────────────────
 * This page serves BOTH `/submissions` (every form) and `/forms/{form}/submissions` (the form hub's
 * Responses tab). The per-form mode is keyed off the PRESENCE of the `form` prop, which the server omits
 * entirely on the global route — the same absent-not-empty rule the hub's `share` block follows.
 *
 * A second component was the obvious alternative and is the wrong one: the filter bar, the URL builder, the
 * export link and the empty-state branching would all have been spelled twice, and J1e's audit-export defect
 * is precisely what two copies of a filter surface do to each other. `SubmissionInboxPresenter::list()` is
 * shared for the same reason one layer down.
 *
 * ⚠️ EVERY INERTIA VISIT THIS PAGE MAKES GOES THROUGH `baseUrl` — and note the scope of that claim, because
 * an earlier version of this sentence overstated it. `visit()` is the single call site (the filter round-trip
 * and "Clear filters" both route through it), and on the per-form page a hard-coded `/submissions` there
 * would have thrown the reader back onto the global inbox, which is the dead end this row exists to remove.
 * The other outbound URLs are deliberately NOT `baseUrl`-derived and must not be "tidied" into it: the export
 * href is a different endpoint (`/forms/{id}/submissions/export`, which never contained `/submissions`
 * alone), the row form-link targets `/forms/{id}`, and the two row actions target `/submissions/{id}` in
 * BOTH modes — a submission's detail page is not nested under the form.
 */
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsBreadcrumb,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFilterBar,
    MdsFormField,
    MdsIconButton,
    MdsPagination,
    MdsSearchField,
    MdsSelect,
    MdsTabNav,
    statusVariant,
    type BreadcrumbItem,
    type DataTableColumn,
    type TabNavItem,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import { formsCrumb } from '@/composables/useFormsCrumb';

type Option = { value: string; label: string };

type SubmissionRow = {
    id: string;
    // Increment J2c — the id as well as the title, so the global inbox can LINK a row to its form. Present
    // in both modes (the per-form page simply has no Form column to put it in).
    form_id: string;
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
    // Increment J2c — `open_form` is whether they may open the row's FORM, which is NOT implied by the row
    // being listed: the visibility scope has a respondent arm the form policy has no counterpart for, and a
    // soft-deleted form binds to nothing.
    can: { resume: boolean; open_form: boolean };
};

type Meta = { current_page: number; last_page: number; total: number; per_page: number };

const props = defineProps<{
    data: SubmissionRow[];
    meta: Meta;
    filters: {
        /** ABSENT on the per-form route — the dropdown is meaningless there. See the docblock. */
        forms?: Option[];
        statuses: Option[];
        sources: Option[];
        applied: { form_id: string | null; status: string | null; source: string | null; q: string | null };
    };
    can: { export: boolean };
    empty_reason: 'no_matches' | 'no_rows' | null;
    /** Present only on `/forms/{form}/submissions` — its presence IS the per-form mode switch (J2c). */
    form?: { id: string; title: string };
    /** The form's tab strip, resolved server-side by `FormTabSet`. Present exactly when `form` is. */
    tabs?: TabNavItem[];
}>();

/**
 * Every route this page builds. On the per-form page the reader must stay on the form: a "Clear filters"
 * that navigated to `/submissions` would silently widen the list to every form in the tenant while the
 * breadcrumb still named one.
 */
const baseUrl = computed(() => (props.form ? `/forms/${props.form.id}/submissions` : '/submissions'));

/**
 * Three crumbs on the per-form page, never two: `MdsBreadcrumb` renders the LAST item as text whatever it
 * carries, so a trail ending at the form's title would print the hub's name with no way to reach it — the
 * dead end intact, with a separator. Same rule as `forms/Analytics.vue`.
 */
const crumbs = computed<BreadcrumbItem[]>(() =>
    props.form
        ? [
              formsCrumb(),
              { label: props.form.title, href: `/forms/${props.form.id}` },
              { label: 'Responses' },
          ]
        : [],
);

/**
 * The Form column is dropped on the per-form page — every row would carry the same value, and it is already
 * the page's heading, its breadcrumb and its tab strip's accessible name.
 */
const columns = computed<DataTableColumn[]>(() => [
    ...(props.form ? [] : [{ key: 'form_title', header: 'Form', sortable: true }]),
    { key: 'status', header: 'Status' },
    { key: 'source_label', header: 'Source' },
    { key: 'respondent', header: 'Respondent' },
    { key: 'submitted_at', header: 'Submitted', sortable: true },
]);

// Local filter state, seeded from what the server applied. Each change re-queries (page resets to 1).
const selected = reactive({
    form_id: props.filters.applied.form_id ?? '',
    status: props.filters.applied.status ?? '',
    source: props.filters.applied.source ?? '',
    q: props.filters.applied.q ?? '',
});

const formOptions = [{ value: '', label: 'All forms' }, ...(props.filters.forms ?? [])];
const statusOptions = [{ value: '', label: 'All statuses' }, ...props.filters.statuses];
const sourceOptions = [{ value: '', label: 'All sources' }, ...props.filters.sources];

/** Marks the table busy while a filter round-trip is in flight — the audit viewer's contract. */
const busy = ref(false);

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    // Never as a query param on the per-form page: the route already carries the form, and echoing it would
    // let a hand-edited URL disagree with the breadcrumb (the server ignores it, but the address bar lies).
    if (selected.form_id && !props.form) params.form_id = selected.form_id;
    if (selected.status) params.status = selected.status;
    if (selected.source) params.source = selected.source;
    if (selected.q) params.q = selected.q;
    return { ...params, ...extra };
}

function visit(params: Record<string, string | number>, replace: boolean): void {
    router.get(baseUrl.value, params, {
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
    // `form_id` is cleared in BOTH modes and that is correct in both: on the per-form page it is seeded from
    // the route rather than chosen, `queryParams()` never emits it, and `visit({})` returns to this form's
    // own unfiltered list — not to the global inbox.
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
/**
 * The form whose responses Export would stream, or `null` when the reader has not narrowed to one.
 *
 * ⚠️ THE ROUTE-BOUND FORM FIRST, AND THIS IS LOAD-BEARING RATHER THAN TIDY. The export endpoint is
 * per-form (`/forms/{form}/submissions/export` — its columns are that form's fields), and this used to read
 * `selected.form_id` alone. On the per-form page `clearFilters()` blanks that ref, so an Export taken after
 * clearing would have built `/forms//submissions/export` — a 404 from a button that looked live.
 */
const exportFormId = computed(() => props.form?.id ?? (selected.form_id || null));

function download(format: 'csv' | 'xlsx'): void {
    const params = new URLSearchParams({ format });
    if (selected.status) params.set('status', selected.status);
    if (selected.source) params.set('source', selected.source);
    if (selected.q) params.set('q', selected.q);
    window.location.href = `/forms/${exportFormId.value}/submissions/export?${params.toString()}`;
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
        <Head :title="form ? `Responses · ${form.title}` : 'Submissions'" />

        <PageHeader :title="form ? 'Responses' : 'Submissions'" icon="submissions">
            <!-- The form's own name is the middle crumb and the strip's accessible name, so the h1 stays the
                 PAGE's name — the `forms/Analytics.vue` split, not the hub's (where the title IS the form). -->
            <template v-if="form" #breadcrumbs>
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
            <template #actions>
                <!-- On the per-form page Export needs no form to be chosen: the route already is one. -->
                <template v-if="can.export && exportFormId">
                    <MdsButton variant="secondary" icon-left="download" @click="download('csv')">Export CSV</MdsButton>
                    <MdsButton variant="secondary" icon-left="download" @click="download('xlsx')">Export XLSX</MdsButton>
                </template>
            </template>
        </PageHeader>

        <!-- `:ariaLabel` in camelCase deliberately — `vue-tsc` treats the kebab spelling as a real HTML
             attribute and then reports the required prop as missing, a gate failure with no obvious cause.
             Named for the RESOURCE because axe's `landmark-unique` distinguishes navigation landmarks by
             their accessible name, and this page now carries two of them. -->
        <MdsTabNav
            v-if="tabs && form"
            :items="tabs"
            current="submissions"
            :ariaLabel="form.title"
            :link-component="Link"
        />

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
            <!-- Absent on the per-form page: the form is the route, not a choice. Absent rather than
                 disabled — ADR-0011 §D9's absent-not-locked doctrine; a disabled control is a claim that
                 there is something here you may not have. -->
            <MdsFormField v-if="!form" label="Form" input-id="inbox-form">
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

        <MdsDataTable
            :columns="columns"
            :rows="data"
            :loading="busy"
            :caption="form ? `Responses to ${form.title}` : 'Submissions'"
            row-key="id"
        >
            <!-- Increment J2c — the submission→form link. Before this, the inbox printed a form's name on
                 every row and linked none of them, which `FormHubController`'s docblock names as one of the
                 three dead ends the hub was built to end. Rendered only in global mode, because the per-form
                 page drops the column entirely. -->
            <template #cell-form_title="{ row }">
                <!-- ⚠️ GATED ON `can.open_form`, NEVER ON THE PRESENCE OF `form_id`. The inbox lists rows a
                     reader may see; it does not follow that they may open the FORM behind one. An
                     unconditional link here 403s for a keyer whose grant was revoked (the visibility
                     scope's respondent arm) and 404s on a soft-deleted form — whose title renders as an em
                     dash, so it would have shipped "—" as a live hyperlink. -->
                <Link
                    v-if="(row as SubmissionRow).can?.open_form"
                    :href="`/forms/${(row as SubmissionRow).form_id}`"
                    class="inbox__form-link"
                >
                    {{ (row as SubmissionRow).form_title }}
                </Link>
                <span v-else>{{ (row as SubmissionRow).form_title }}</span>
            </template>
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
                    v-else-if="form"
                    illustration="default"
                    headline="No responses yet"
                    description="Responses appear here as this form is filled out through any channel. Share the link or add one by hand to get started."
                />
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

.inbox__form-link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: none;
}

.inbox__form-link:hover {
    text-decoration: underline;
}
</style>
