<script setup lang="ts">
/**
 * The audit log (Increment I2, PRD Feature #12) — the Owner/Admin compliance ledger, and the first surface
 * in the product that lets a human READ what `AuditLogger` has been writing since H4.
 *
 * Assembled from shared design-system components; the only page-local markup is the filter grid's layout
 * and the before/after table inside {@see AuditChangeModal}, which no primitive covers.
 *
 * ── The URL is `/audit-log`; this folder is `audit`. The mismatch is deliberate ────────────────────────
 * The URL matches the permission gating it (`audit_log.view`) and the name the PRD and sidebar use; the
 * folder keeps the one-lowercase-word convention every sibling page folder follows. "Fixing" either half
 * to match the other breaks every `router.get('/audit-log', …)` string below.
 *
 * ── No column is `sortable` ────────────────────────────────────────────────────────────────────────────
 * `MdsDataTable` sorts the rows it was handed — the CURRENT PAGE, client-side. Over a server-paginated
 * ledger a "Sort by When" header that reorders 25 of 4,000 rows is a lie, and this is the page where a lie
 * is least acceptable. The server orders newest-first, fixed, and the page says so in one line of prose.
 * (`webhooks/Show.vue` and `submissions/Inbox.vue` both carry this defect; fixing them is not this
 * increment's business, but do not copy it here.)
 */
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
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
import PageHeader from '@/components/shell/PageHeader.vue';
import AuditChangeModal from '@/components/audit/AuditChangeModal.vue';
import { auditEventVariant } from '@/components/audit/event-variant';
import type { AuditPageProps, AuditRow } from '@/components/audit/types';

const props = defineProps<AuditPageProps>();

const columns: DataTableColumn[] = [
    { key: 'created_at', header: 'When' },
    { key: 'event', header: 'Event' },
    { key: 'target', header: 'Target' },
    { key: 'actor', header: 'Actor' },
    { key: 'summary', header: 'Changes' },
];

const selected = reactive({
    event: props.filters.applied.event ?? '',
    auditable_type: props.filters.applied.auditable_type ?? '',
    user_id: props.filters.applied.user_id ?? '',
    from: props.filters.applied.from ?? '',
    to: props.filters.applied.to ?? '',
});

const eventOptions = [{ value: '', label: 'All events' }, ...props.filters.events];
const typeOptions = [{ value: '', label: 'All types' }, ...props.filters.auditable_types];
const actorOptions = [{ value: '', label: 'All actors' }, ...props.filters.actors];

/** The open row's detail. ONE source of truth — a separate boolean would let a stale row render. */
const detailRow = ref<AuditRow | null>(null);

/**
 * Marks the table busy while a filter round-trip is in flight. `MdsDataTable` ships a structure-preserving
 * skeleton and `aria-busy` behind this prop that no page in the repo had wired; with five controls each
 * costing a request, without it the table shows stale rows under changed filters.
 */
const busy = ref(false);

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (selected.event) params.event = selected.event;
    if (selected.auditable_type) params.auditable_type = selected.auditable_type;
    if (selected.user_id) params.user_id = selected.user_id;
    if (selected.from) params.from = selected.from;
    if (selected.to) params.to = selected.to;
    return { ...params, ...extra };
}

function visit(params: Record<string, string | number>, replace: boolean): void {
    router.get('/audit-log', params, {
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

function goToPage(page: number): void {
    visit(queryParams({ page }), false);
}

function clearFilters(): void {
    selected.event = '';
    selected.auditable_type = '';
    selected.user_id = '';
    selected.from = '';
    selected.to = '';
    visit({}, true);
}

/**
 * A real download, not an Inertia visit — and it carries EXACTLY the filters on screen. "I exported what I
 * was looking at" is a compliance guarantee, so the export query is built from the same `queryParams()`
 * the page navigates with rather than assembled separately (which is where that guarantee dies).
 */
function download(format: 'csv' | 'xlsx'): void {
    const params = new URLSearchParams({ format });
    for (const [key, value] of Object.entries(queryParams())) {
        params.set(key, String(value));
    }
    window.location.href = `/audit-log/export?${params.toString()}`;
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

/** The full uuid belongs in the modal; a row shows enough to recognise and no more. */
function shortId(id: string): string {
    return `${id.slice(0, 8)}…`;
}

/** The first few changed field names, so a reader can scan without opening anything. */
function summarize(row: AuditRow): string {
    if (row.changes.length === 0) return '—';
    const names = row.changes.slice(0, 3).map((c) => c.key);
    const extra = row.changes.length - names.length;
    return extra > 0 ? `${names.join(', ')} +${extra} more` : names.join(', ');
}
</script>

<template>
    <div>
        <Head title="Audit log" />

        <PageHeader title="Audit log" icon="shield">
            <template #actions>
                <template v-if="can.export">
                    <MdsButton variant="secondary" icon-left="download" @click="download('csv')">Export CSV</MdsButton>
                    <MdsButton variant="secondary" icon-left="download" @click="download('xlsx')">Export XLSX</MdsButton>
                </template>
            </template>
        </PageHeader>

        <!--
            The <h2> is load-bearing, not decorative: PageHeader renders the <h1> and MdsEmptyState renders
            an <h3>, so dropping it makes axe's heading-order fail ONLY in the empty state — i.e. only on a
            database the seeder did not populate.
        -->
        <section class="audit__filters" aria-labelledby="audit-filters-heading">
            <h2 id="audit-filters-heading" class="audit__filters-heading">Filters</h2>

            <div class="audit__filters-grid">
                <MdsFormField label="Event" input-id="audit-event">
                    <MdsSelect
                        id="audit-event"
                        v-model="selected.event"
                        :options="eventOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
                <MdsFormField label="Type" input-id="audit-type">
                    <MdsSelect
                        id="audit-type"
                        v-model="selected.auditable_type"
                        :options="typeOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
                <MdsFormField label="Actor" input-id="audit-actor">
                    <MdsSelect
                        id="audit-actor"
                        v-model="selected.user_id"
                        :options="actorOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
                <!--
                    Labelled MdsFormFields rather than Inbox.vue's bare aria-label selects: a date input's
                    only visible affordance is browser chrome (mm/dd/yyyy), which says nothing about WHICH
                    end of the range it is.
                -->
                <MdsFormField label="From" input-id="audit-from">
                    <MdsTextInput
                        id="audit-from"
                        v-model="selected.from"
                        type="date"
                        :disabled="busy"
                        @change="applyFilters"
                    />
                </MdsFormField>
                <MdsFormField label="To" input-id="audit-to">
                    <MdsTextInput
                        id="audit-to"
                        v-model="selected.to"
                        type="date"
                        :disabled="busy"
                        @change="applyFilters"
                    />
                </MdsFormField>
            </div>
        </section>

        <p class="audit__hint">Newest first. The ledger is append-only — entries are never edited or removed.</p>

        <MdsDataTable :columns="columns" :rows="data" :loading="busy" caption="Audit log" row-key="id">
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
                <div class="audit__target">
                    <span class="audit__target-type">{{ (row as AuditRow).target.type_label }}</span>
                    <Link v-if="(row as AuditRow).target.url" :href="(row as AuditRow).target.url!">
                        {{ (row as AuditRow).target.label }}
                    </Link>
                    <span v-else-if="(row as AuditRow).target.label">{{ (row as AuditRow).target.label }}</span>
                    <span v-else class="audit__mono">{{ shortId((row as AuditRow).target.id) }}</span>
                </div>
            </template>

            <template #cell-actor="{ row }">
                <span>{{ (row as AuditRow).actor }}</span>
            </template>

            <template #cell-summary="{ row }">
                <div class="audit__summary">
                    <span>{{ summarize(row as AuditRow) }}</span>
                    <MdsBadge
                        v-if="(row as AuditRow).redacted_fields.length"
                        variant="neutral"
                        label="Redacted"
                    />
                </div>
            </template>

            <template #row-actions="{ row }">
                <!--
                    `search` rather than `external-link`: this opens a dialog, it does not navigate away.
                    One `label` prop, so the accessible name and the tooltip cannot disagree.
                -->
                <MdsIconButton
                    icon="search"
                    :label="(row as AuditRow).changes.length ? 'View changes' : 'No field changes recorded'"
                    :disabled="!(row as AuditRow).changes.length"
                    size="sm"
                    @click="detailRow = row as AuditRow"
                />
            </template>

            <template #empty>
                <MdsEmptyState
                    v-if="empty_reason === 'no_matches'"
                    illustration="search"
                    headline="No matching activity"
                    description="Try widening the date range or removing a filter."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="clearFilters">Clear filters</MdsButton>
                    </template>
                </MdsEmptyState>
                <MdsEmptyState
                    v-else
                    illustration="default"
                    headline="Nothing recorded yet"
                    description="Publishing a form, changing a member's role, or exporting data all appear here as they happen."
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

        <AuditChangeModal :row="detailRow" @close="detailRow = null" />
    </div>
</template>

<style scoped>
.audit__filters {
    margin-bottom: var(--mds-space-5);
}

.audit__filters-heading {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--mds-color-text-secondary);
}

.audit__filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: var(--mds-space-3);
}

.audit__hint {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.audit__target {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.audit__target-type {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/*
 * ⚠️ A LINK WITHOUT A COLOUR TOKEN IS NOT AN UNSTYLED LINK — IT IS A DARK-MODE CONTRAST FAILURE.
 * Omitting this rule leaves the anchor at the user agent's default `#0000EE`, which is fine on the light
 * surface and measures 1.38:1 against the dark table row (`--mds-primary-700`, #123350) — axe reported
 * exactly that, and the CI E2E gate failed on it at all three viewports in dark while passing in light.
 * `--mds-color-action-primary-fg` is the theme-aware token for primary-coloured text ON a surface (the
 * same one `webhooks/Show.vue`'s back link uses): it re-points to `--mds-primary-300` in dark, giving
 * 5.17:1, and the design system documents 7.48:1 for its light value.
 *
 * The lesson generalises past this rule: the light theme cannot catch this class of defect, so any new
 * anchor outside a design-system component needs a token, not a default.
 */
.audit__target a {
    color: var(--mds-color-action-primary-fg);
    text-decoration: none;
}

.audit__target a:hover {
    text-decoration: underline;
}

.audit__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.audit__summary {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    overflow-wrap: anywhere;
}
</style>
