<script setup lang="ts">
/**
 * Webhook management — endpoints list (Increment H14). The Owner/Admin surface over the H13a/H13b engine
 * (Starter+; the nav item + route are plan-gated). Shows a plan cap/quota summary, the Zapier connect recipe,
 * and the tenant's endpoints in a DataTable with status Badges; "Add endpoint" opens the shared create modal
 * (disabled at the plan cap — the server also hard-blocks). Selecting an endpoint opens its detail + delivery
 * log. Assembled entirely from shared design-system components.
 */
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFilterBar,
    MdsIconButton,
    MdsSearchField,
    MdsStatTile,
    MdsBadge,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import WebhookFormModal from '@/components/webhooks/WebhookFormModal.vue';
import ZapierRecipe from '@/components/webhooks/ZapierRecipe.vue';

type Option = { value: string; label: string };
type Quota = { used: number; limit: number | null };

type EndpointRow = {
    id: string;
    name: string;
    url: string;
    status: string;
    event_types: string[];
    form_id: string | null;
    form_title: string | null;
    /** The form's hub path, server-resolved; null when the reader cannot open it or it no longer exists. */
    form_url: string | null;
    secret_masked: string;
    disabled_reason: string | null;
    consecutive_failure_count: number;
    last_success_at: string | null;
    last_failure_at: string | null;
    created_at: string;
};

const props = defineProps<{
    data: EndpointRow[];
    summary: { endpoints: Quota; deliveries: Quota };
    forms: Option[];
    eventTypes: Option[];
    can: { create: boolean };
    filters: { applied: { q: string | null } };
    empty_reason: 'no_matches' | 'no_rows' | null;
}>();

const columns: DataTableColumn[] = [
    { key: 'name', header: 'Name', sortable: true },
    { key: 'url', header: 'URL' },
    { key: 'status', header: 'Status' },
    { key: 'event_types', header: 'Events' },
    { key: 'form_title', header: 'Scope' },
];

const atCap = computed(
    () => props.summary.endpoints.limit !== null && props.summary.endpoints.used >= props.summary.endpoints.limit,
);

const createOpen = ref(false);

// ── Keyword filter (J1e) ────────────────────────────────────────────────
const selected = reactive({ q: props.filters.applied.q ?? '' });
const busy = ref(false);

function applyFilters(): void {
    router.get('/webhooks', selected.q ? { q: selected.q } : {}, {
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

function formatQuota(q: Quota): string {
    const used = q.used.toLocaleString();
    return q.limit === null ? used : `${used} / ${q.limit.toLocaleString()}`;
}

function openEndpoint(id: string): void {
    router.visit(`/webhooks/${id}`);
}
</script>

<template>
    <div>
        <Head title="Webhooks" />

        <PageHeader title="Webhooks" icon="activity">
            <template #actions>
                <MdsButton
                    v-if="can.create"
                    variant="primary"
                    icon-left="plus"
                    :disabled="atCap"
                    :title="atCap ? 'You’ve reached your plan’s endpoint limit.' : undefined"
                    @click="createOpen = true"
                >
                    Add endpoint
                </MdsButton>
            </template>
        </PageHeader>

        <div class="webhooks__stats">
            <MdsStatTile label="Endpoints" :value="formatQuota(summary.endpoints)" icon="activity" />
            <MdsStatTile label="Deliveries this month" :value="formatQuota(summary.deliveries)" icon="trend-up" />
        </div>

        <p v-if="atCap" class="webhooks__hint">
            You’ve reached your plan’s endpoint limit. Delete an endpoint or upgrade to add more.
        </p>

        <ZapierRecipe class="webhooks__recipe" />

        <MdsFilterBar>
            <MdsSearchField
                v-model="selected.q"
                :applied="filters.applied.q ?? ''"
                label="Search endpoints"
                placeholder="Name or URL"
                @submit="applyFilters"
            />
        </MdsFilterBar>

        <MdsDataTable :columns="columns" :rows="data" :loading="busy" caption="Webhook endpoints" row-key="id">
            <template #cell-url="{ row }">
                <span class="webhooks__url">{{ (row as EndpointRow).url }}</span>
            </template>
            <template #cell-status="{ row }">
                <MdsBadge v-bind="statusVariant((row as EndpointRow).status)" dot />
            </template>
            <template #cell-event_types="{ row }">
                {{ (row as EndpointRow).event_types.length }}
            </template>
            <!-- J2d — the Scope column names a form and now reaches it. `form_url` is server-resolved and
                 absent whenever the reader could not open the hub (or the form is gone), so this is a link
                 iff the destination exists; the text fallback is exactly what this cell rendered before. -->
            <template #cell-form_title="{ row }">
                <Link v-if="(row as EndpointRow).form_url" :href="(row as EndpointRow).form_url!" class="scope-link">
                    {{ (row as EndpointRow).form_title }}
                </Link>
                <template v-else>{{ (row as EndpointRow).form_title ?? 'All forms' }}</template>
            </template>
            <template #row-actions="{ row }">
                <MdsIconButton
                    icon="external-link"
                    label="View endpoint"
                    size="sm"
                    @click="openEndpoint((row as EndpointRow).id)"
                />
            </template>
            <template #empty>
                <MdsEmptyState
                    v-if="empty_reason === 'no_matches'"
                    illustration="search"
                    headline="No matching endpoints"
                    description="Endpoints are matched on their name and URL — the Scope column is not searched."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="clearFilters">Clear search</MdsButton>
                    </template>
                </MdsEmptyState>
                <MdsEmptyState
                    v-else
                    illustration="default"
                    headline="No webhook endpoints"
                    description="Add an endpoint to receive real-time events when submissions arrive or forms change."
                >
                    <template v-if="can.create" #action>
                        <MdsButton variant="primary" icon-left="plus" :disabled="atCap" @click="createOpen = true">
                            Add endpoint
                        </MdsButton>
                    </template>
                </MdsEmptyState>
            </template>
        </MdsDataTable>

        <WebhookFormModal
            v-model:open="createOpen"
            :forms="forms"
            :event-types="eventTypes"
            :endpoint="null"
        />
    </div>
</template>

<style scoped>
.webhooks__stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-5);
}

.webhooks__hint {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.webhooks__recipe {
    margin-bottom: var(--mds-space-5);
}

.webhooks__url {
    display: inline-block;
    /* `min()` so the ellipsis budget never exceeds the cell: at 375px the DataTable's card-per-row mode
       gives this cell less than 22rem, and a fixed max-width would push the page into horizontal scroll. */
    max-width: min(22rem, 100%);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/* J2d — the Scope column's form link. `-fg`, never `-bg`: the J2a WCAG 1.4.11 finding, and the same token
   `inbox__form-link` and `forms__title-link` already use. There is no global `a` reset in this app, so an
   unclassed link renders in browser-default #0000EE — the one-design-system rule caught by review. */
.scope-link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: none;
}

.scope-link:hover {
    text-decoration: underline;
}

.scope-link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}
</style>
