<script setup lang="ts">
/**
 * The platform support console for in-app feedback (Increment I7a) — PRD Feature #11's "dedicated internal
 * view … visible in aggregate to the platform support team", and the first surface anywhere in the product
 * that READS `feedback_reports`. Until this page existed, feedback was write-only: two writers, zero
 * readers.
 *
 * Rows come from SuperAdminService::listFeedback (the elevated SELECT-only carve-out); a transition PATCHes
 * back through the adopt-tenant-context path, so it lands in the reporting workspace's OWN audit log.
 *
 * ── EACH ROW'S BUTTONS COME FROM THE SERVER ─────────────────────────────────────────────────────────────
 * `row.transitions` is FeedbackStatus::allowedTransitions() — literally the same source the server guards
 * with. The page renders whatever it is handed rather than re-deriving the lifecycle in TypeScript, which
 * is how the offered verbs and the accepted verbs stay identical. (submissions/Show.vue computes its map
 * client-side because it shows ONE record; a console shows twenty-five in different states at once.)
 *
 * No column is `sortable`: MdsDataTable sorts the current page client-side, which over a server-paginated,
 * cross-tenant queue would reorder 25 of N rows and call it sorted.
 */
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
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
    MdsSelect,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import type { ConsoleFeedbackPageProps, ConsoleFeedbackRow } from '@/components/feedback/types';

const props = defineProps<ConsoleFeedbackPageProps>();

const page = usePage();
const adminError = computed(() => page.props.errors?.admin);

const columns: DataTableColumn[] = [
    { key: 'submitted_at', header: 'Sent' },
    { key: 'tenant_name', header: 'Workspace' },
    { key: 'reporter', header: 'From' },
    { key: 'remarks', header: 'Remarks' },
    { key: 'status', header: 'Status' },
];

const selected = reactive({
    status: props.filters.applied.status ?? '',
    tenant_id: props.filters.applied.tenant_id ?? '',
});

const statusOptions = [{ value: '', label: 'All statuses' }, ...props.filters.statuses];
const tenantOptions = [{ value: '', label: 'All workspaces' }, ...props.filters.tenants];

const detailRow = ref<ConsoleFeedbackRow | null>(null);
const busy = ref(false);

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (selected.status) params.status = selected.status;
    if (selected.tenant_id) params.tenant_id = selected.tenant_id;
    return { ...params, ...extra };
}

function visit(params: Record<string, string | number>, replace: boolean): void {
    router.get('/admin/feedback', params, {
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

function goToPage(target: number): void {
    visit(queryParams({ page: target }), false);
}

function clearFilters(): void {
    selected.status = '';
    selected.tenant_id = '';
    visit({}, true);
}

function transition(row: ConsoleFeedbackRow, status: string): void {
    busy.value = true;
    router.patch(
        `/admin/feedback/${row.id}`,
        { status },
        {
            preserveScroll: true,
            onFinish: () => (busy.value = false),
            onSuccess: () => (detailRow.value = null),
        },
    );
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

/** Three states: a named reporter, a removed member, or (never) the system — feedback always has an author. */
function reporterLabel(row: ConsoleFeedbackRow): string {
    return row.reporter?.name ?? 'Former member';
}

function excerpt(text: string): string {
    return text.length > 80 ? `${text.slice(0, 80)}…` : text;
}

/** `browser_info` is free-form jsonb; render whatever keys are actually there rather than assuming two. */
function browserPairs(row: ConsoleFeedbackRow): Array<[string, string]> {
    return Object.entries(row.browser_info ?? {}).map(([key, value]) => [key, String(value)]);
}
</script>

<template>
    <AdminLayout title="Feedback" icon="feedback">
        <p v-if="adminError" class="admin-fb__alert" role="alert">{{ adminError }}</p>

        <!--
            Increment J2e — the section, its <h2> and the grid moved into MdsFilterBar, the last of the three
            pages DSR §3.2 note 5 named as still hand-rolling this markup.

            Geometry unchanged, and MEASURED: the three rules deleted below were byte-identical to
            FilterBar's on every property, and its one extra declaration (`font-family` on the heading) is a
            no-op because app.css already sets that family on `body` with no h1..h6 override anywhere.

            ⚠️ The <h2> was load-bearing here too — AdminLayout renders the <h1> and MdsEmptyState an <h3>, so
            without it the EMPTY state skips a level — and unlike its sibling this page had NO Vitest case
            pinning it, only the /admin/feedback axe scan. J2e adds one.
        -->
        <MdsFilterBar>
                <MdsFormField label="Status" input-id="admin-feedback-status">
                    <MdsSelect
                        id="admin-feedback-status"
                        v-model="selected.status"
                        :options="statusOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
                <MdsFormField label="Workspace" input-id="admin-feedback-tenant">
                    <MdsSelect
                        id="admin-feedback-tenant"
                        v-model="selected.tenant_id"
                        :options="tenantOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
        </MdsFilterBar>

        <p class="admin-fb__hint">
            Newest first, across every workspace. Changing a status is recorded in that workspace's own audit
            log — they can see how their report was handled.
        </p>

        <MdsDataTable :columns="columns" :rows="data" :loading="busy" caption="Feedback reports" row-key="id">
            <template #cell-submitted_at="{ row }">
                {{ formatDate((row as ConsoleFeedbackRow).submitted_at) }}
            </template>

            <template #cell-reporter="{ row }">
                {{ reporterLabel(row as ConsoleFeedbackRow) }}
            </template>

            <template #cell-remarks="{ row }">
                <div class="admin-fb__remarks">
                    <span>{{ excerpt((row as ConsoleFeedbackRow).remarks) }}</span>
                    <MdsBadge
                        v-if="(row as ConsoleFeedbackRow).has_screenshot"
                        variant="neutral"
                        label="Screenshot"
                    />
                </div>
            </template>

            <template #cell-status="{ row }">
                <MdsBadge v-bind="statusVariant((row as ConsoleFeedbackRow).status)" />
            </template>

            <template #row-actions="{ row }">
                <MdsIconButton
                    icon="search"
                    label="Open report"
                    size="sm"
                    @click="detailRow = row as ConsoleFeedbackRow"
                />
            </template>

            <template #empty>
                <MdsEmptyState
                    v-if="empty_reason === 'no_matches'"
                    illustration="search"
                    headline="No matching reports"
                    description="Try a different status or workspace, or clear the filters."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="clearFilters">Clear filters</MdsButton>
                    </template>
                </MdsEmptyState>
                <MdsEmptyState
                    v-else
                    illustration="default"
                    headline="No feedback yet"
                    description="Reports sent from the Feedback button in any workspace arrive here."
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
            :title="detailRow ? `${detailRow.tenant_name} · ${reporterLabel(detailRow)}` : 'Feedback'"
            @close="detailRow = null"
        >
            <div v-if="detailRow" class="admin-fb__detail">
                <dl class="admin-fb__meta">
                    <div>
                        <dt>Sent</dt>
                        <dd>{{ formatDate(detailRow.submitted_at) }}</dd>
                    </div>
                    <div>
                        <dt>Page</dt>
                        <dd class="admin-fb__mono">{{ detailRow.route }}</dd>
                    </div>
                    <div>
                        <dt>Reporter</dt>
                        <dd>{{ detailRow.reporter?.email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(detailRow.status)" /></dd>
                    </div>
                    <div v-if="detailRow.resolved_at">
                        <dt>Closed</dt>
                        <dd>{{ formatDate(detailRow.resolved_at) }} · {{ detailRow.resolver?.name ?? '—' }}</dd>
                    </div>
                </dl>

                <p class="admin-fb__body">{{ detailRow.remarks }}</p>

                <img
                    v-if="detailRow.has_screenshot"
                    class="admin-fb__shot"
                    :src="`/admin/feedback/${detailRow.id}/screenshot`"
                    alt="Screenshot attached to this report"
                />

                <dl v-if="browserPairs(detailRow).length" class="admin-fb__browser">
                    <template v-for="[key, value] in browserPairs(detailRow)" :key="key">
                        <dt>{{ key }}</dt>
                        <dd>{{ value }}</dd>
                    </template>
                </dl>
            </div>

            <template #actions>
                <MdsButton variant="tertiary" @click="detailRow = null">Close</MdsButton>
                <MdsButton
                    v-for="option in detailRow?.transitions ?? []"
                    :key="option.value"
                    variant="primary"
                    :loading="busy"
                    @click="detailRow && transition(detailRow, option.value)"
                >
                    Mark {{ option.label }}
                </MdsButton>
            </template>
        </MdsModal>
    </AdminLayout>
</template>

<style scoped>
.admin-fb__alert {
    margin: 0 0 var(--mds-space-4);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-action-danger-bg);
    border-radius: var(--mds-radius-md);
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-md-font-size);
}

/* The three `.admin-fb__filters*` rules moved into MdsFilterBar verbatim (J2e) — geometry unchanged. */

.admin-fb__hint {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.admin-fb__remarks {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
}

.admin-fb__detail {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.admin-fb__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    gap: var(--mds-space-3);
    margin: 0;
}

.admin-fb__meta dt {
    margin-bottom: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.admin-fb__meta dd {
    margin: 0;
    color: var(--mds-color-text-body);
}

.admin-fb__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.admin-fb__body {
    margin: 0;
    white-space: pre-wrap;
    color: var(--mds-color-text-body);
}

.admin-fb__shot {
    width: 100%;
    max-height: 420px;
    object-fit: contain;
    object-position: top left;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.admin-fb__browser {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--mds-space-1) var(--mds-space-3);
    margin: 0;
    padding-top: var(--mds-space-3);
    border-top: 1px solid var(--mds-color-border-default);
    font-size: var(--mds-type-body-sm-font-size);
}

.admin-fb__browser dt {
    color: var(--mds-color-text-secondary);
}

.admin-fb__browser dd {
    margin: 0;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
}
</style>
