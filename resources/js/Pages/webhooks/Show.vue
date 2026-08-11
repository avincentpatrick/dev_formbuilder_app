<script setup lang="ts">
/**
 * Webhook endpoint detail + delivery log (Increment H14). The per-endpoint page: metadata (masked secret,
 * scope, breaker state, rotation grace), an action bar (test / rotate / edit / pause‖re-enable / delete), and
 * the offset-paginated delivery-observability log with a per-row redeliver. Every action is an Inertia visit
 * → the controller's toast; the one-shot `flash.newSecret` (create/rotate) opens the secret reveal, and
 * `flash.testResult` opens the test-ping result. Assembled from shared design-system components.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsCard,
    MdsDataTable,
    MdsEmptyState,
    MdsIconButton,
    MdsModal,
    MdsPagination,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import type { FlashNewSecret, FlashTestResult } from '@/types/inertia';
import PageHeader from '@/components/shell/PageHeader.vue';
import WebhookFormModal from '@/components/webhooks/WebhookFormModal.vue';
import SecretRevealModal from '@/components/webhooks/SecretRevealModal.vue';
import TestResultModal from '@/components/webhooks/TestResultModal.vue';

type Option = { value: string; label: string };
type Meta = { current_page: number; last_page: number; total: number; per_page: number };

type EndpointDetail = {
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
    signing_algorithm: string;
    secret_previous_expires_at: string | null;
    updated_at: string;
};

type DeliveryRow = {
    id: string;
    webhook_endpoint_id: string;
    event_id: string;
    event_type: string;
    status: string;
    attempt_count: number;
    max_attempts: number;
    next_retry_at: string | null;
    last_attempted_at: string | null;
    response_status_code: number | null;
    response_body_excerpt: string | null;
    response_time_ms: number | null;
    created_at: string;
    updated_at: string;
};

const props = defineProps<{
    endpoint: EndpointDetail;
    deliveries: { data: DeliveryRow[]; meta: Meta };
    forms: Option[];
    eventTypes: Option[];
    can: { update: boolean; delete: boolean };
}>();

const page = usePage();

const isActive = computed(() => props.endpoint.status === 'active');

const eventLabels = computed(() => {
    const lookup = new Map(props.eventTypes.map((o) => [o.value, o.label]));
    return props.endpoint.event_types.map((v) => lookup.get(v) ?? v);
});

const columns: DataTableColumn[] = [
    { key: 'event_type', header: 'Event' },
    { key: 'status', header: 'Status' },
    { key: 'attempt_count', header: 'Attempts' },
    { key: 'response_status_code', header: 'Code' },
    { key: 'response_time_ms', header: 'Latency' },
    { key: 'created_at', header: 'Created', sortable: true },
];

// ── Modals ───────────────────────────────────────────────────────────────────
const editOpen = ref(false);
const deleteOpen = ref(false);
const rotateConfirmOpen = ref(false);

const editable = computed(() => ({
    id: props.endpoint.id,
    name: props.endpoint.name,
    url: props.endpoint.url,
    event_types: props.endpoint.event_types,
    form_id: props.endpoint.form_id,
}));

// One-shot flashes: capture into local state so the modal keeps its value after the flash clears.
const secretOpen = ref(false);
const secretPayload = ref<FlashNewSecret | null>(null);
watch(
    () => page.props.flash?.newSecret as FlashNewSecret | null | undefined,
    (ns) => {
        if (ns) {
            secretPayload.value = ns;
            secretOpen.value = true;
        }
    },
    { immediate: true },
);

const testOpen = ref(false);
const testPayload = ref<FlashTestResult | null>(null);
watch(
    () => page.props.flash?.testResult as FlashTestResult | null | undefined,
    (tr) => {
        if (tr) {
            testPayload.value = tr;
            testOpen.value = true;
        }
    },
    { immediate: true },
);

// ── Actions (all Inertia visits → controller toast) ──────────────────────────
const base = computed(() => `/webhooks/${props.endpoint.id}`);

function sendTest(): void {
    router.post(`${base.value}/test`, {}, { preserveScroll: true });
}

function rotateSecret(): void {
    router.post(`${base.value}/rotate-secret`, {}, { preserveScroll: true, onSuccess: () => (rotateConfirmOpen.value = false) });
}

function setStatus(status: 'active' | 'paused'): void {
    router.patch(base.value, { status }, { preserveScroll: true });
}

function destroy(): void {
    router.delete(base.value, { onSuccess: () => (deleteOpen.value = false) });
}

function redeliver(deliveryId: string): void {
    router.post(`${base.value}/deliveries/${deliveryId}/redeliver`, {}, { preserveScroll: true });
}

function goToPage(pageNumber: number): void {
    router.get(base.value, { page: pageNumber }, { only: ['deliveries'], preserveState: true, preserveScroll: true });
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
        <Head :title="`Webhook · ${endpoint.name}`" />

        <Link href="/webhooks" class="detail__back">← Back to webhooks</Link>

        <PageHeader :title="endpoint.name" icon="activity">
            <template #actions>
                <template v-if="can.update">
                    <MdsButton variant="tertiary" icon-left="activity" @click="sendTest">Send test ping</MdsButton>
                    <MdsButton variant="tertiary" icon-left="redo" @click="rotateConfirmOpen = true">Rotate secret</MdsButton>
                    <MdsButton variant="secondary" icon-left="edit" @click="editOpen = true">Edit</MdsButton>
                    <MdsButton v-if="isActive" variant="secondary" @click="setStatus('paused')">Pause</MdsButton>
                    <MdsButton v-else variant="primary" icon-left="check" @click="setStatus('active')">Re-enable</MdsButton>
                </template>
                <MdsButton v-if="can.delete" variant="destructive" icon-left="trash" @click="deleteOpen = true">Delete</MdsButton>
            </template>
        </PageHeader>

        <div class="detail__grid">
            <MdsCard>
                <dl class="detail__meta">
                    <div class="detail__meta-row">
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(endpoint.status)" /></dd>
                    </div>
                    <div class="detail__meta-row"><dt>URL</dt><dd class="detail__mono">{{ endpoint.url }}</dd></div>
                    <div class="detail__meta-row">
                        <dt>Events</dt>
                        <dd>{{ eventLabels.length ? eventLabels.join(', ') : '—' }}</dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Scope</dt>
                        <dd>
                            <Link v-if="endpoint.form_url" :href="endpoint.form_url">{{ endpoint.form_title }}</Link>
                            <template v-else>{{ endpoint.form_title ?? 'All forms' }}</template>
                        </dd>
                    </div>
                    <div class="detail__meta-row"><dt>Signing</dt><dd>{{ endpoint.signing_algorithm }}</dd></div>
                    <div class="detail__meta-row"><dt>Secret</dt><dd class="detail__mono">{{ endpoint.secret_masked }}</dd></div>
                    <div v-if="endpoint.secret_previous_expires_at" class="detail__meta-row">
                        <dt>Old secret valid until</dt><dd>{{ formatDate(endpoint.secret_previous_expires_at) }}</dd>
                    </div>
                </dl>
            </MdsCard>

            <MdsCard>
                <template #header><h2 class="detail__card-title">Delivery health</h2></template>
                <dl class="detail__meta">
                    <div class="detail__meta-row">
                        <dt>Consecutive failures</dt>
                        <dd>{{ endpoint.consecutive_failure_count }}</dd>
                    </div>
                    <div v-if="endpoint.disabled_reason" class="detail__meta-row">
                        <dt>Paused because</dt>
                        <dd>{{ endpoint.disabled_reason === 'too_many_failures' ? 'Too many consecutive failures' : 'Manually disabled' }}</dd>
                    </div>
                    <div class="detail__meta-row"><dt>Last success</dt><dd>{{ formatDate(endpoint.last_success_at) }}</dd></div>
                    <div class="detail__meta-row"><dt>Last failure</dt><dd>{{ formatDate(endpoint.last_failure_at) }}</dd></div>
                    <div class="detail__meta-row"><dt>Created</dt><dd>{{ formatDate(endpoint.created_at) }}</dd></div>
                </dl>
            </MdsCard>
        </div>

        <MdsCard v-if="!isActive" class="detail__notice">
            <p class="detail__prose">
                This endpoint is <strong>{{ endpoint.status }}</strong> and isn’t delivering. Re-enable it to resume
                deliveries — that also clears the failure counter. Existing deliveries can be re-queued once it’s active.
            </p>
        </MdsCard>

        <section class="detail__log">
            <h2 class="detail__card-title detail__log-title">Delivery log</h2>
            <MdsDataTable :columns="columns" :rows="deliveries.data" caption="Webhook deliveries" row-key="id">
                <template #cell-event_type="{ row }">
                    <span class="detail__mono">{{ (row as DeliveryRow).event_type }}</span>
                </template>
                <template #cell-status="{ row }">
                    <MdsBadge v-bind="statusVariant((row as DeliveryRow).status)" />
                </template>
                <template #cell-attempt_count="{ row }">
                    {{ (row as DeliveryRow).attempt_count }} / {{ (row as DeliveryRow).max_attempts }}
                </template>
                <template #cell-response_status_code="{ row }">
                    {{ (row as DeliveryRow).response_status_code ?? '—' }}
                </template>
                <template #cell-response_time_ms="{ row }">
                    <template v-if="(row as DeliveryRow).response_time_ms !== null">{{ (row as DeliveryRow).response_time_ms }} ms</template>
                    <template v-else>—</template>
                </template>
                <template #cell-created_at="{ row }">
                    {{ formatDate((row as DeliveryRow).created_at) }}
                </template>
                <template #row-actions="{ row }">
                    <MdsIconButton
                        v-if="can.update"
                        icon="redo"
                        label="Redeliver"
                        size="sm"
                        :disabled="!isActive"
                        :title="isActive ? 'Redeliver' : 'Re-enable the endpoint to redeliver'"
                        @click="redeliver((row as DeliveryRow).id)"
                    />
                </template>
                <template #empty>
                    <MdsEmptyState
                        illustration="default"
                        headline="No deliveries yet"
                        description="Deliveries appear here as subscribed events fire. Send a test ping to try it out."
                    />
                </template>
            </MdsDataTable>

            <MdsPagination
                :current-page="deliveries.meta.current_page"
                :last-page="deliveries.meta.last_page"
                :total="deliveries.meta.total"
                :per-page="deliveries.meta.per_page"
                @update:page="goToPage"
            />
        </section>

        <!-- Edit -->
        <WebhookFormModal v-model:open="editOpen" :forms="forms" :event-types="eventTypes" :endpoint="editable" />

        <!-- One-time secret reveal (create redirect / rotate) -->
        <SecretRevealModal
            v-if="secretPayload"
            v-model:open="secretOpen"
            :secret="secretPayload.secret"
            :name="secretPayload.name"
        />

        <!-- Test-ping result -->
        <TestResultModal v-model:open="testOpen" :result="testPayload" />

        <!-- Rotate confirm -->
        <MdsModal :open="rotateConfirmOpen" title="Rotate signing secret" @close="rotateConfirmOpen = false">
            <p class="detail__prose">
                A new signing secret is generated and shown once. The current secret keeps working for a 24-hour
                grace window so your receiver can switch over without downtime. Continue?
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="rotateConfirmOpen = false">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="redo" @click="rotateSecret">Rotate secret</MdsButton>
            </template>
        </MdsModal>

        <!-- Delete confirm -->
        <MdsModal :open="deleteOpen" title="Delete webhook endpoint" @close="deleteOpen = false">
            <p class="detail__prose">
                Delete <strong>{{ endpoint.name }}</strong>? It will stop receiving events immediately. This can’t be undone.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="deleteOpen = false">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" @click="destroy">Delete endpoint</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.detail__back {
    display: inline-block;
    margin-bottom: var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-action-primary-fg);
    text-decoration: none;
}

.detail__back:hover {
    text-decoration: underline;
}

.detail__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-5);
}

.detail__notice {
    margin-bottom: var(--mds-space-5);
}

.detail__card-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.detail__log-title {
    margin-bottom: var(--mds-space-4);
}

.detail__log {
    margin-top: var(--mds-space-2);
}

.detail__meta {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.detail__meta-row {
    display: grid;
    grid-template-columns: 12rem 1fr;
    gap: var(--mds-space-3);
    align-items: baseline;
}

.detail__meta-row dt {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
}

.detail__meta-row dd {
    margin: 0;
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.detail__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.detail__prose {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

@media (max-width: 480px) {
    .detail__meta-row {
        grid-template-columns: 1fr;
        gap: var(--mds-space-1);
    }
}
</style>
