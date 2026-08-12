<script setup lang="ts">
/**
 * One connector delivery rule + its delivery log (H15b). Reached from the Integrations list; this is where a
 * rule is edited, paused, tested and deleted, and where the shared `webhook_deliveries` ledger is read back
 * for just this rule.
 *
 * TWO THINGS DIFFER DELIBERATELY FROM THE WEBHOOK DETAIL PAGE THEY OTHERWISE MIRROR:
 *
 *  1. The log's fourth column is the RESULT excerpt, not the HTTP status code. Slack answers `{"ok": false}`
 *     with HTTP 200, so a status column beside a red "Failed" badge would read "200" on almost every failure
 *     and teach people to distrust the page. The excerpt is the diagnostic.
 *  2. There is no per-row redeliver. H15a ships no connector redeliver endpoint, and a permanently-disabled
 *     button is a worse answer than no button.
 *
 * The page also renders for a rule whose workspace was DISCONNECTED — the grant is soft-deleted but its rules
 * survive (paused), so `connection` may describe a trashed grant or, if it was hard-deleted, be null. Both say
 * so rather than throwing.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsCard,
    MdsDataTable,
    MdsEmptyState,
    MdsIcon,
    MdsModal,
    MdsPagination,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import RuleFormModal from '@/components/integrations/RuleFormModal.vue';
import TestResultModal from '@/components/webhooks/TestResultModal.vue';
import { channelDisplay } from '@/components/integrations/channel-options';
import type { ConnectionCard, DeliveryRow, Meta, Option, RuleDetail } from '@/components/integrations/types';
import type { FlashTestResult } from '@/types/inertia';

const props = defineProps<{
    connection: ConnectionCard | null;
    rule: RuleDetail;
    deliveries: { data: DeliveryRow[]; meta: Meta };
    forms: Option[];
    eventTypes: Option[];
    can: { update: boolean; delete: boolean };
}>();

const page = usePage();

const columns: DataTableColumn[] = [
    { key: 'event_type', header: 'Event' },
    { key: 'status', header: 'Status' },
    { key: 'attempt_count', header: 'Attempts' },
    { key: 'response_body_excerpt', header: 'Result' },
    { key: 'created_at', header: 'Created' },
];

const editOpen = ref(false);
const deleteOpen = ref(false);
const testOpen = ref(false);
const testPayload = ref<FlashTestResult | null>(null);

const isActive = computed(() => props.rule.status === 'active');
const grantLive = computed(() => props.connection !== null && !props.connection.disconnected && props.connection.status === 'active');
const canSend = computed(() => props.can.update && grantLive.value);

const eventLabels = computed(() =>
    props.rule.event_types.map((value) => props.eventTypes.find((o) => o.value === value)?.label ?? value),
);

const channelLabel = computed(() => channelDisplay(props.rule.channel_name, props.rule.channel_id));

// One-shot flash → local state, so the modal survives the flash being cleared on the next visit.
watch(
    () => page.props.flash?.testResult as FlashTestResult | null | undefined,
    (result) => {
        if (result) {
            testPayload.value = result;
            testOpen.value = true;
        }
    },
    { immediate: true },
);

const base = computed(() => `/integrations/rules/${props.rule.id}`);

function sendTest(): void {
    router.post(`${base.value}/test`, {}, { preserveScroll: true });
}

function setStatus(status: 'active' | 'paused'): void {
    router.patch(base.value, { status }, { preserveScroll: true });
}

function destroy(): void {
    router.delete(base.value, { onSuccess: () => (deleteOpen.value = false) });
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
        <Head :title="`Integration rule · ${rule.name}`" />

        <Link href="/integrations" class="detail__back">← Back to integrations</Link>

        <PageHeader :title="rule.name" icon="plug">
            <template #actions>
                <template v-if="can.update">
                    <!-- No `title` explaining the disabled state: a natively disabled button is not focusable,
                         so a tooltip on it is unreachable by keyboard and unannounced by a screen reader. The
                         reason is on the page instead, in the notice card below. -->
                    <MdsButton variant="tertiary" icon-left="activity" :disabled="!canSend" @click="sendTest">
                        Send test message
                    </MdsButton>
                    <MdsButton variant="secondary" icon-left="edit" @click="editOpen = true">Edit</MdsButton>
                    <MdsButton v-if="isActive" variant="secondary" @click="setStatus('paused')">Pause</MdsButton>
                    <MdsButton v-else variant="primary" icon-left="check" @click="setStatus('active')">Resume</MdsButton>
                </template>
                <MdsButton v-if="can.delete" variant="destructive" icon-left="trash" @click="deleteOpen = true">
                    Delete
                </MdsButton>
            </template>
        </PageHeader>

        <div class="detail__grid">
            <MdsCard>
                <template #header><h2 class="detail__card-title">Routing</h2></template>
                <dl class="detail__meta">
                    <div class="detail__meta-row">
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(rule.status)" dot /></dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Workspace</dt>
                        <dd>{{ connection?.external_account_label ?? 'Removed' }}</dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Channel</dt>
                        <dd class="detail__mono">{{ channelLabel }}</dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Events</dt>
                        <dd>{{ eventLabels.length ? eventLabels.join(', ') : '—' }}</dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Scope</dt>
                        <dd>
                            <Link v-if="rule.form_url" :href="rule.form_url" class="scope-link">{{ rule.form_title }}</Link>
                            <template v-else>{{ rule.form_title ?? 'All forms' }}</template>
                        </dd>
                    </div>
                </dl>
            </MdsCard>

            <MdsCard>
                <template #header><h2 class="detail__card-title">Delivery health</h2></template>
                <dl class="detail__meta">
                    <div class="detail__meta-row">
                        <dt>Consecutive failures</dt>
                        <dd>{{ rule.consecutive_failure_count }}</dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Last success</dt>
                        <dd>{{ formatDate(rule.last_success_at) }}</dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Last failure</dt>
                        <dd>{{ formatDate(rule.last_failure_at) }}</dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Created</dt>
                        <dd>{{ formatDate(rule.created_at) }}</dd>
                    </div>
                </dl>
            </MdsCard>
        </div>

        <MdsCard v-if="!grantLive" class="detail__notice">
            <p class="detail__prose">
                <template v-if="connection === null || connection.disconnected">
                    The workspace this rule delivered to was <strong>disconnected</strong>. Reconnect it from
                    Integrations to resume — this rule is kept and paused until you do.
                </template>
                <template v-else>
                    This workspace needs to be reconnected before anything is delivered.
                </template>
            </p>
        </MdsCard>

        <MdsCard v-else-if="!isActive" class="detail__notice">
            <p class="detail__prose">
                This rule is <strong>{{ rule.status }}</strong> and isn’t delivering. Resume it to start again —
                that also clears the failure counter.
            </p>
            <!-- H16b — WHY it stopped, not merely that it did. A paused Sheets rule is almost always a
                 drifted header row or a destination we can no longer reach, and both are things the tenant
                 fixes in a minute IF they are told which. `paused_reason` is the `[code] sentence` the
                 adapter itself wrote, so nothing here is the provider's own unreviewed text. -->
            <p v-if="rule.paused_reason" class="detail__reason">
                <MdsIcon name="alert" size="sm" class="detail__reason-icon" aria-hidden="true" />
                <span>{{ rule.paused_reason }}</span>
            </p>
            <div v-if="rule.spreadsheet_id && can.update" class="detail__reason-actions">
                <!-- Opening the edit modal RE-INSPECTS the sheet, so the tenant sees its CURRENT headings
                     rather than the ones stored when the rule was written — which, on a drifted rule, is
                     precisely the difference they need to see. -->
                <MdsButton variant="secondary" icon-left="edit" @click="editOpen = true">
                    Review columns
                </MdsButton>
                <MdsButton
                    v-if="rule.spreadsheet_url"
                    as="a"
                    variant="tertiary"
                    icon-left="external-link"
                    :href="rule.spreadsheet_url"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Open the spreadsheet
                </MdsButton>
            </div>
        </MdsCard>

        <section class="detail__log">
            <h2 class="detail__card-title detail__log-title">Delivery log</h2>
            <MdsDataTable :columns="columns" :rows="deliveries.data" caption="Connector deliveries" row-key="id">
                <template #cell-event_type="{ row }">
                    <span class="detail__mono">{{ (row as DeliveryRow).event_type }}</span>
                </template>
                <template #cell-status="{ row }">
                    <MdsBadge v-bind="statusVariant((row as DeliveryRow).status)" dot />
                </template>
                <template #cell-attempt_count="{ row }">
                    {{ (row as DeliveryRow).attempt_count }} / {{ (row as DeliveryRow).max_attempts }}
                </template>
                <template #cell-response_body_excerpt="{ row }">
                    <span class="detail__result">{{ (row as DeliveryRow).response_body_excerpt ?? '—' }}</span>
                </template>
                <template #cell-created_at="{ row }">
                    {{ formatDate((row as DeliveryRow).created_at) }}
                </template>
                <template #empty>
                    <MdsEmptyState
                        illustration="default"
                        headline="No deliveries yet"
                        description="Deliveries appear here as subscribed events fire. Send a test message to try it out."
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

        <RuleFormModal
            v-model:open="editOpen"
            :connection-id="rule.connection_id"
            :provider="connection?.provider ?? null"
            :forms="forms"
            :event-types="eventTypes"
            :rule="rule"
        />

        <TestResultModal
            v-model:open="testOpen"
            :result="testPayload"
            title="Test message"
            success-lead="The message was posted to the channel. Check it appeared where you expected before relying on this rule."
        />

        <MdsModal :open="deleteOpen" title="Delete delivery rule" @close="deleteOpen = false">
            <p class="detail__prose">
                This rule stops delivering immediately and disappears from Integrations. Its past deliveries stay
                in the log. This can’t be undone.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="deleteOpen = false">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" @click="destroy">Delete rule</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.detail__back {
    display: inline-block;
    margin-bottom: var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.detail__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(18rem, 100%), 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-5);
}

.detail__card-title {
    margin: 0;
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.detail__meta {
    display: grid;
    grid-template-columns: 12rem 1fr;
    gap: var(--mds-space-2) var(--mds-space-4);
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
}

.detail__meta-row {
    display: contents;
}

.detail__meta dt {
    color: var(--mds-color-text-secondary);
}

.detail__meta dd {
    margin: 0;
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.detail__mono {
    font-family: var(--mds-font-family-mono);
}

.detail__notice {
    margin-bottom: var(--mds-space-5);
}

/* H16b — the adapter's own explanation for a paused rule, on the warning surface. Same recipe as the
   provider caution on the Integrations page, and the same reason: the icon carries the meaning alongside
   the colour, and `status-warning-{bg,fg}` is a measured pair. */
.detail__reason {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    margin: var(--mds-space-3) 0 0;
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.detail__reason-icon {
    flex-shrink: 0;
    margin-top: 1px;
}

/* Wrap rather than overflow — the standing 375px rule for any row of actions. */
.detail__reason-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-3);
}

.detail__prose {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.detail__log-title {
    margin-bottom: var(--mds-space-3);
}

/* `min()` so the clamp never exceeds the cell: at 375px the DataTable's card-per-row mode gives this cell
   well under 20rem, and a fixed max-width would push the page into horizontal scroll. */
.detail__result {
    display: inline-block;
    max-width: min(20rem, 100%);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

@media (max-width: 480px) {
    .detail__meta {
        grid-template-columns: 1fr;
        gap: var(--mds-space-1) 0;
    }

    .detail__meta dt {
        margin-top: var(--mds-space-2);
    }
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
