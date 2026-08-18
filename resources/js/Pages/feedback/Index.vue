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
    MdsModal,
    MdsPagination,
    MdsSearchField,
    MdsSelect,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import type { FeedbackPageProps, FeedbackRow } from '@/components/feedback/types';

const props = defineProps<FeedbackPageProps>();

const columns: DataTableColumn[] = [
    { key: 'submitted_at', header: 'Sent' },
    { key: 'reporter', header: 'From' },
    { key: 'route', header: 'Page' },
    { key: 'remarks', header: 'Remarks' },
    { key: 'status', header: 'Status' },
];

const selected = reactive({
    status: props.filters.applied.status ?? '',
    q: props.filters.applied.q ?? '',
});
const statusOptions = [{ value: '', label: 'All statuses' }, ...props.filters.statuses];

const detailRow = ref<FeedbackRow | null>(null);
const busy = ref(false);

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (selected.status) params.status = selected.status;
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
                placeholder="Remarks or page"
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
        </MdsFilterBar>

        <p class="fbl__hint">
            Newest first. Reports are handled by the platform support team — the status here is theirs to
            change, not yours.
        </p>

        <MdsDataTable :columns="columns" :rows="data" :loading="busy" caption="Feedback reports" row-key="id">
            <template #cell-submitted_at="{ row }">
                {{ formatDate((row as FeedbackRow).submitted_at) }}
            </template>

            <template #cell-reporter="{ row }">
                {{ reporterLabel(row as FeedbackRow) }}
            </template>

            <template #cell-route="{ row }">
                <span class="fbl__mono">{{ (row as FeedbackRow).route }}</span>
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
                <MdsIconButton icon="search" label="View report" size="sm" @click="detailRow = row as FeedbackRow" />
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
            @close="detailRow = null"
        >
            <div v-if="detailRow" class="fbl__detail">
                <dl class="fbl__meta">
                    <div>
                        <dt>Sent</dt>
                        <dd>{{ formatDate(detailRow.submitted_at) }}</dd>
                    </div>
                    <div>
                        <dt>Page</dt>
                        <dd class="fbl__mono">{{ detailRow.route }}</dd>
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
</style>
