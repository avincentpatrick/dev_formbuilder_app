<script setup lang="ts">
/**
 * The PLATFORM audit ledger (Increment I7b) — PRD Feature #12's platform half, and the first surface that
 * can read the `tenant_id IS NULL` rows I5 has been writing since 2026-08-06.
 *
 * Rows come from SuperAdminService::listPlatformAudits through `audits_platform_select`, a SELECT policy
 * narrowed with `AND tenant_id IS NULL`. The operator cannot read a tenant's history here, by construction
 * rather than by filtering — see the migration.
 *
 * ── WHAT IS REUSED, AND WHAT DELIBERATELY IS NOT ────────────────────────────────────────────────────────
 * `AuditChangeModal` and `event-variant.ts` are shared VERBATIM with the tenant viewer: the modal imports
 * only MdsBadge/MdsModal/./types — no Inertia, no layout, no usePage — and carries a11y work (the
 * visually-hidden caption, the mobile data-label technique, the redaction copy) nobody should re-derive.
 * The filter/paging mechanics are copied from Pages/audit/Index.vue with the SAME asymmetry: changing a
 * filter REPLACES the history entry, paging does not.
 *
 * Not reused: the export buttons (there is no platform export — see the controller), PageHeader
 * (AdminLayout renders the h1), and `shortId()`. That last one matters: the tenant page falls back to a
 * truncated uuid when a target has no label, but here that fallback would fire on EVERY row, and
 * `auditable_id` is the acting super-admin's own user uuid — a bare `0192e2e0…` in a "Target" column reads
 * as an object the operator could go and find. The type label alone is the honest rendering; the full uuid
 * is in the modal, labelled.
 *
 * No column is `sortable`: MdsDataTable sorts the current page client-side, which over a server-paginated
 * ledger would reorder 25 of N rows and call it sorted.
 */
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFormField,
    MdsIconButton,
    MdsPagination,
    MdsSelect,
    MdsTextInput,
    type DataTableColumn,
} from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AuditChangeModal from '@/components/audit/AuditChangeModal.vue';
import { auditEventVariant } from '@/components/audit/event-variant';
import type { AuditRow, ConsoleAuditPageProps } from '@/components/audit/types';

const props = defineProps<ConsoleAuditPageProps>();

const page = usePage();
const adminError = computed(() => page.props.errors?.admin);

const columns: DataTableColumn[] = [
    { key: 'created_at', header: 'When' },
    { key: 'event', header: 'Event' },
    { key: 'target', header: 'Target' },
    { key: 'actor', header: 'Operator' },
    { key: 'summary', header: 'Changes' },
];

const selected = reactive({
    user_id: props.filters.applied.user_id ?? '',
    from: props.filters.applied.from ?? '',
    to: props.filters.applied.to ?? '',
});

const actorOptions = [{ value: '', label: 'All operators' }, ...props.filters.actors];

/** The open row's detail. ONE source of truth — a separate boolean would let a stale row render. */
const detailRow = ref<AuditRow | null>(null);
const busy = ref(false);

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (selected.user_id) params.user_id = selected.user_id;
    if (selected.from) params.from = selected.from;
    if (selected.to) params.to = selected.to;
    return { ...params, ...extra };
}

function visit(params: Record<string, string | number>, replace: boolean): void {
    router.get('/admin/audit-log', params, {
        preserveState: true,
        preserveScroll: true,
        ...(replace ? { replace: true } : {}),
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}

/** Changing a filter REPLACES the history entry; paging does not. That asymmetry is the Inbox contract. */
function applyFilters(): void {
    visit(queryParams(), true);
}

function goToPage(target: number): void {
    visit(queryParams({ page: target }), false);
}

function clearFilters(): void {
    selected.user_id = '';
    selected.from = '';
    selected.to = '';
    visit({}, true);
}

/** An MdsButton + router.get, never a raw anchor — see the dark-mode note at the foot of this file. */
function goToSettings(): void {
    router.get('/admin/settings');
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

function summarize(row: AuditRow): string {
    const count = row.changes.length;
    if (count === 0) return 'No field changes';
    return count === 1 ? `${row.changes[0].key}` : `${count} fields`;
}
</script>

<template>
    <AdminLayout title="Audit log" icon="shield">
        <p v-if="adminError" class="admin-audit__alert" role="alert">{{ adminError }}</p>

        <!--
            ⚠️ This <h2> is load-bearing. AdminLayout renders the <h1> and MdsEmptyState renders an <h3>, so
            removing it fails axe's heading-order — but ONLY in the empty state, which is exactly the state a
            fresh install ships in. /admin/* is excluded from the Playwright axe sweep (superadmin.mfa needs a
            TOTP in CI), so the Vitest case asserting this element is its only automated guard.
        -->
        <section class="admin-audit__filters" aria-labelledby="platform-audit-filters-heading">
            <h2 id="platform-audit-filters-heading" class="admin-audit__filters-heading">Filters</h2>

            <div class="admin-audit__filters-grid">
                <MdsFormField label="Operator" input-id="platform-audit-actor">
                    <MdsSelect
                        id="platform-audit-actor"
                        v-model="selected.user_id"
                        :options="actorOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
                <MdsFormField label="From" input-id="platform-audit-from">
                    <MdsTextInput
                        id="platform-audit-from"
                        v-model="selected.from"
                        type="date"
                        :disabled="busy"
                        @change="applyFilters"
                    />
                </MdsFormField>
                <MdsFormField label="To" input-id="platform-audit-to">
                    <MdsTextInput
                        id="platform-audit-to"
                        v-model="selected.to"
                        type="date"
                        :disabled="busy"
                        @change="applyFilters"
                    />
                </MdsFormField>
            </div>
        </section>

        <!--
            Load-bearing copy, not decoration. Without it an operator looks here for the workspace they
            suspended five minutes ago, does not find it, and files a bug. It is also the user-facing
            statement of RBAC §9's transparency posture.
        -->
        <p class="admin-audit__hint">
            Newest first, and <strong>platform actions only</strong>. A change you made to a specific
            workspace — suspending it, assigning a plan, handling a feedback report — is recorded in
            <strong>that workspace's own audit log</strong>, not here. The ledger is append-only; entries are
            never edited or removed.
        </p>

        <MdsDataTable
            :columns="columns"
            :rows="data"
            :loading="busy"
            caption="Platform audit log"
            row-key="id"
        >
            <template #cell-created_at="{ row }">
                {{ formatDate((row as AuditRow).created_at) }}
            </template>

            <template #cell-event="{ row }">
                <MdsBadge
                    :variant="auditEventVariant((row as AuditRow).event)"
                    :label="(row as AuditRow).event_label"
                />
            </template>

            <template #cell-target="{ row }">
                {{ (row as AuditRow).target.type_label }}
            </template>

            <!--
                NO impersonation marker here, and its absence is deliberate (I11a). An impersonated action is
                written under the impersonated TENANT's context, so it carries a `tenant_id` — while this page
                reads only the `tenant_id IS NULL` slice, twice over (the query's `whereNull` and
                `audits_platform_select`'s own `AND tenant_id IS NULL`). Such a row therefore cannot reach
                this table, and `PlatformAuditPresenter` emits `acting_as: null` unconditionally. A `v-if`
                marker was written here first and was dead code; see that presenter for why making it
                reachable would mean undoing I7b's deliberate narrowing.
            -->
            <template #cell-actor="{ row }">
                {{ (row as AuditRow).actor }}
            </template>

            <template #cell-summary="{ row }">
                {{ summarize(row as AuditRow) }}
            </template>

            <template #row-actions="{ row }">
                <MdsIconButton
                    icon="search"
                    label="View changes"
                    size="sm"
                    @click="detailRow = row as AuditRow"
                />
            </template>

            <template #empty>
                <MdsEmptyState
                    v-if="empty_reason === 'no_matches'"
                    illustration="search"
                    headline="No matching activity"
                    description="Try widening the date range, or choosing a different operator."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="clearFilters">Clear filters</MdsButton>
                    </template>
                </MdsEmptyState>
                <!--
                    Deliberately NOT the tenant page's copy: every event that one names (publishing a form,
                    changing a member's role, exporting data) is structurally impossible in this slice, so
                    reusing it would train the operator to expect rows that can never arrive.
                -->
                <MdsEmptyState
                    v-else
                    illustration="default"
                    headline="No platform activity yet"
                    description="This log records changes to platform-wide settings — the signup toggle and platform maintenance. It is empty because nobody has saved a change on the Platform page yet, not because anything is wrong."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="goToSettings">Open platform settings</MdsButton>
                    </template>
                </MdsEmptyState>
            </template>
        </MdsDataTable>

        <MdsPagination
            :current-page="meta.current_page"
            :last-page="meta.last_page"
            :total="meta.total"
            :per-page="meta.per_page"
            @update:page="goToPage"
        />

        <AuditChangeModal :row="detailRow" @close="detailRow = null" />
    </AdminLayout>
</template>

<style scoped>
/*
 * ⚠️ NO RAW ANCHOR ON THIS PAGE, and if one is ever added it needs `--mds-color-action-primary-fg`.
 * Pages/audit/Index.vue records the measurement: an anchor without that token sits at the UA default
 * #0000EE, which is 1.38:1 against a dark table row and failed CI at all three viewports in dark while
 * passing in light. This page has no linkable targets (target.url is always null) and its one navigation
 * affordance is an MdsButton, so the rule currently has no subject — it is written down because /admin/*
 * is never axe-scanned and the light theme cannot catch this class of defect.
 *
 * `--mds-color-status-danger-fg`, not `--mds-color-danger-text`: the latter resolves to danger-300 in dark
 * and fails contrast on bg-surface (recorded as a found defect on StatTile).
 */
.admin-audit__alert {
    margin: 0 0 var(--mds-space-4);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-action-danger-bg);
    border-radius: var(--mds-radius-md);
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-md-font-size);
}

.admin-audit__filters {
    margin-bottom: var(--mds-space-5);
}

.admin-audit__filters-heading {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--mds-color-text-secondary);
}

.admin-audit__filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: var(--mds-space-3);
}

.admin-audit__hint {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/*
 * The impersonation marker (I11a) — duplicated from the tenant viewer rather than shared, matching how
 * these two pages already treat every other cell. They are `scoped`, so the rules cannot reach each other;
 * extracting them would mean a component for two elements and one text style. The WCAG 1.4.1 note applies
 * identically: the word "via" is what distinguishes the lines, not the colour.
 */
.audit__actor {
    display: flex;
    flex-direction: column;
}

.audit__acting-as {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
