<script setup lang="ts">
/**
 * The workspace's own feedback (Increment I7a, PRD Feature #11) — gated on `feedback.view`, the permission
 * the role catalog has granted Owner and Admin since Phase 0 with no code behind it until now.
 *
 * ── READ-ONLY, AND THAT IS THE FEATURE ──────────────────────────────────────────────────────────────────
 * Feature #11 gives the status lifecycle to the platform support team's "dedicated internal view". A tenant
 * marking its own report Resolved would be resolving it in the OPERATOR's queue — so no verb is offered
 * here and none is routed. What this page is for: an Owner seeing that a colleague already reported the
 * thing they were about to report, and what became of it.
 *
 * No column is `sortable` — `MdsDataTable` sorts the page it was handed, client-side, and over a
 * server-paginated list that is a lie. Same rule the audit viewer states.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFilterBar,
    MdsFormField,
    MdsIconButton,
    MdsModal,
    MdsPagination,
    MdsSearchField,
    MdsSelect,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import FeedbackThread from '@/components/feedback/FeedbackThread.vue';
import FeedbackVerdict from '@/components/feedback/FeedbackVerdict.vue';
import PageHeader from '@/components/shell/PageHeader.vue';
import type { FeedbackPageProps, FeedbackRow } from '@/components/feedback/types';

const props = defineProps<FeedbackPageProps>();

const columns: DataTableColumn[] = [
    { key: 'submitted_at', header: 'Sent' },
    { key: 'reporter', header: 'From' },
    { key: 'type', header: 'Kind' },
    { key: 'route', header: 'Page' },
    { key: 'remarks', header: 'Remarks' },
    { key: 'status', header: 'Status' },
];

const selected = reactive({
    status: props.filters.applied.status ?? '',
    type: props.filters.applied.type ?? '',
    source: props.filters.applied.source ?? '',
    q: props.filters.applied.q ?? '',
});
const statusOptions = [{ value: '', label: 'All statuses' }, ...props.filters.statuses];
const typeOptions = [{ value: '', label: 'All kinds' }, ...props.filters.types];
const sourceOptions = [{ value: '', label: 'From anywhere' }, ...props.filters.sources];

/**
 * The open report is looked up from the page's rows by id, never held as a copy (M179): It works, Still a problem
 * and a comment reload the props, and the modal must show the thread as it now is.
 */
const detailId = ref<string | null>(null);
const detailRow = computed<FeedbackRow | null>(() => props.data.find((row) => row.id === detailId.value) ?? null);
const busy = ref(false);

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (selected.status) params.status = selected.status;
    if (selected.type) params.type = selected.type;
    if (selected.source) params.source = selected.source;
    if (selected.q) params.q = selected.q;
    return { ...params, ...extra };
}

function visit(params: Record<string, string | number>, replace: boolean): void {
    router.get('/feedback', params, {
        preserveState: true,
        preserveScroll: true,
        ...(replace ? { replace: true } : {}),
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}

/** Filtering REPLACES the history entry; paging pushes. The Inbox contract, kept. */
function applyFilters(): void {
    visit(queryParams(), true);
}

function goToPage(page: number): void {
    visit(queryParams({ page }), false);
}

function clearFilters(): void {
    selected.status = '';
    selected.type = '';
    selected.source = '';
    selected.q = '';
    visit({}, true);
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

/** Three states, not two: a null reporter is a removed member, never "the system". */
function reporterLabel(row: FeedbackRow): string {
    return row.reporter ?? 'Former member';
}

function excerpt(text: string): string {
    return text.length > 90 ? `${text.slice(0, 90)}…` : text;
}
</script>

<template>
    <div>
        <Head title="Feedback" />

        <PageHeader title="Feedback" icon="feedback" />

        <!--
            The <h2> this page used to hand-roll now lives inside MdsFilterBar (J1e), along with the reason
            it must stay unconditional. Same markup, one definition, six pages.
        -->
        <MdsFilterBar>
            <MdsSearchField
                v-model="selected.q"
                :applied="filters.applied.q ?? ''"
                label="Search feedback"
                placeholder="Remarks, page or address"
                @submit="applyFilters"
            />
            <MdsFormField label="Status" input-id="feedback-status">
                <MdsSelect
                    id="feedback-status"
                    v-model="selected.status"
                    :options="statusOptions"
                    :disabled="busy"
                    @update:model-value="applyFilters"
                />
            </MdsFormField>
            <MdsFormField label="Kind" input-id="feedback-type">
                <MdsSelect
                    id="feedback-type"
                    v-model="selected.type"
                    :options="typeOptions"
                    :disabled="busy"
                    @update:model-value="applyFilters"
                />
            </MdsFormField>
            <MdsFormField label="From" input-id="feedback-source">
                <MdsSelect
                    id="feedback-source"
                    v-model="selected.source"
                    :options="sourceOptions"
                    :disabled="busy"
                    @update:model-value="applyFilters"
                />
            </MdsFormField>
        </MdsFilterBar>

        <p class="fbl__hint">
            Newest first. The platform support team triages each report and marks it Resolved or Declined. When one
            is Resolved, you — or a tester — can say whether the fix works, and anyone can add a comment.
        </p>

        <MdsDataTable :columns="columns" :rows="data" :loading="busy" caption="Feedback reports" row-key="id">
            <template #cell-submitted_at="{ row }">
                {{ formatDate((row as FeedbackRow).submitted_at) }}
            </template>

            <template #cell-reporter="{ row }">
                {{ reporterLabel(row as FeedbackRow) }}
            </template>

            <template #cell-type="{ row }">
                <MdsBadge v-if="(row as FeedbackRow).type" v-bind="statusVariant((row as FeedbackRow).type as string)" />
                <span v-else class="fbl__none">—</span>
            </template>

            <template #cell-route="{ row }">
                <!-- A relayed report has no page (M179): its reference and title stand in. -->
                <span v-if="(row as FeedbackRow).source === 'relayed'" class="fbl__relayed">
                    <MdsBadge variant="neutral" :label="(row as FeedbackRow).reference ?? 'Relayed'" />
                    {{ (row as FeedbackRow).title }}
                </span>
                <!-- The title, linked to the full address (M175); a report sent before either existed shows its path. -->
                <a v-else-if="(row as FeedbackRow).page_url" :href="(row as FeedbackRow).page_url as string" class="fbl__page">{{
                    (row as FeedbackRow).page_title || (row as FeedbackRow).route
                }}</a>
                <span v-else class="fbl__mono">{{ (row as FeedbackRow).route }}</span>
            </template>

            <template #cell-remarks="{ row }">
                <div class="fbl__remarks">
                    <span>{{ excerpt((row as FeedbackRow).remarks) }}</span>
                    <MdsBadge v-if="(row as FeedbackRow).has_screenshot" variant="neutral" label="Screenshot" />
                </div>
            </template>

            <template #cell-status="{ row }">
                <MdsBadge v-bind="statusVariant((row as FeedbackRow).status)" dot />
            </template>

            <template #row-actions="{ row }">
                <MdsIconButton icon="search" label="View report" size="sm" @click="detailId = (row as FeedbackRow).id" />
            </template>

            <template #empty>
                <MdsEmptyState
                    v-if="empty_reason === 'no_matches'"
                    illustration="search"
                    headline="No matching reports"
                    description="Try a different status, or clear the filter."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="clearFilters">Clear filters</MdsButton>
                    </template>
                </MdsEmptyState>
                <MdsEmptyState
                    v-else
                    illustration="default"
                    headline="No feedback yet"
                    description="Anything your team sends with the Feedback button in the top bar appears here."
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

        <MdsModal
            :open="detailRow !== null"
            :title="detailRow ? `Feedback from ${reporterLabel(detailRow)}` : 'Feedback'"
            @close="detailId = null"
        >
            <div v-if="detailRow" class="fbl__detail">
                <dl class="fbl__meta">
                    <div>
                        <dt>Sent</dt>
                        <dd>{{ formatDate(detailRow.submitted_at) }}</dd>
                    </div>
                    <div>
                        <dt>Kind</dt>
                        <dd>
                            <MdsBadge v-if="detailRow.type" v-bind="statusVariant(detailRow.type)" />
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div v-if="detailRow.source === 'relayed'">
                        <dt>Comment</dt>
                        <dd>
                            <MdsBadge variant="neutral" :label="detailRow.reference ?? 'Relayed'" />
                            {{ detailRow.title }}
                            <span v-if="detailRow.source_label" class="fbl__from">— {{ detailRow.source_label }}</span>
                        </dd>
                    </div>
                    <div v-else>
                        <dt>Page</dt>
                        <dd>
                            <a v-if="detailRow.page_url" :href="detailRow.page_url" class="fbl__page">{{
                                detailRow.page_title || detailRow.route
                            }}</a>
                            <span v-else class="fbl__mono">{{ detailRow.route }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(detailRow.status)" dot /></dd>
                    </div>
                </dl>

                <p class="fbl__body">{{ detailRow.remarks }}</p>

                <img
                    v-if="detailRow.has_screenshot"
                    class="fbl__shot"
                    :src="`/feedback/${detailRow.id}/screenshot`"
                    alt="Screenshot attached to this report"
                />

                <!-- M179: the support team's note, shown here too — until M179 only the reporter ever read it. -->
                <div v-if="detailRow.resolution_note" class="fbl__note" data-feedback-reply>
                    <p class="fbl__note-label">Our note</p>
                    <p class="fbl__body">{{ detailRow.resolution_note }}</p>
                </div>

                <p v-if="detailRow.status === 'confirmed'" class="fbl__confirmed">
                    Confirmed working{{ detailRow.confirmer ? ` by ${detailRow.confirmer}` : '' }}
                </p>

                <FeedbackVerdict :report-id="detailRow.id" :actions="detailRow.actions" />
                <FeedbackThread :report-id="detailRow.id" :comments="detailRow.comments" :can-comment="detailRow.can_comment" />
            </div>
        </MdsModal>
    </div>
</template>

<style scoped>
/* The three `.fbl__filters*` rules moved into MdsFilterBar verbatim (J1e) — geometry unchanged. */

.fbl__hint {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.fbl__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

/* Always underlined: the theme-aware primary text token keeps it legible in dark (audit/Index.vue's note). */
.fbl__page {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
    overflow-wrap: anywhere;
}

.fbl__page:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.fbl__none {
    color: var(--mds-color-text-secondary);
}

.fbl__remarks {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
}

.fbl__detail {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.fbl__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    gap: var(--mds-space-3);
    margin: 0;
}

.fbl__meta dt {
    margin-bottom: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.fbl__meta dd {
    margin: 0;
    color: var(--mds-color-text-body);
}

.fbl__body {
    margin: 0;
    white-space: pre-wrap;
    color: var(--mds-color-text-body);
}

.fbl__shot {
    width: 100%;
    max-height: 420px;
    object-fit: contain;
    object-position: top left;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.fbl__relayed {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.fbl__from {
    color: var(--mds-color-text-secondary);
}

/* A sunken panel needs its own border to read as one in dark, where sunken resolves to the canvas (M174). */
.fbl__note {
    margin-top: var(--mds-space-3);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.fbl__note-label {
    margin: 0 0 var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.fbl__confirmed {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}
</style>
