<script setup lang="ts">
/**
 * Integrations — connected workspaces and their delivery rules (H15b). The Owner/Admin surface over the H15a
 * connector framework (Starter+; the nav item and every route are plan-gated). Shows what can be connected,
 * what is connected, and each grant's rules; selecting a rule opens its detail + delivery log.
 *
 * The OAuth connect flow leaves from here as a plain link, not an Inertia visit: it hands the browser to
 * Slack's consent screen on another origin, and the return lands on the CENTRAL domain before bouncing back
 * (ADR-0009 §D2). The outcome toast is raised server-side by the controller — there is no flash across that
 * host boundary, so it arrives as a query parameter and is consumed into a real flash before this page renders.
 *
 * Assembled entirely from shared design-system components.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsCard,
    MdsDataTable,
    MdsEmptyState,
    MdsIcon,
    MdsIconButton,
    MdsModal,
    MdsStatTile,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import RuleFormModal from '@/components/integrations/RuleFormModal.vue';
import { channelDisplay } from '@/components/integrations/channel-options';
import type { ConnectionWithRules, Option, ProviderCard, RuleRow } from '@/components/integrations/types';

type Quota = { used: number; limit: number | null };

const props = defineProps<{
    providers: ProviderCard[];
    connections: ConnectionWithRules[];
    summary: { rules: { active: number; total: number }; deliveries: Quota };
    forms: Option[];
    eventTypes: Option[];
    can: { create: boolean };
}>();

const columns: DataTableColumn[] = [
    { key: 'name', header: 'Rule' },
    { key: 'channel_name', header: 'Channel' },
    { key: 'event_types', header: 'Events' },
    { key: 'form_title', header: 'Scope' },
    { key: 'status', header: 'Status' },
];

const createOpen = ref(false);
const createConnectionId = ref<string | null>(null);
const disconnectTarget = ref<ConnectionWithRules | null>(null);

const hasConnections = computed(() => props.connections.length > 0);

function formatQuota(q: Quota): string {
    const used = q.used.toLocaleString();
    return q.limit === null ? used : `${used} / ${q.limit.toLocaleString()}`;
}

function openRule(id: string): void {
    router.visit(`/integrations/rules/${id}`);
}

function addRule(connectionId: string): void {
    createConnectionId.value = connectionId;
    createOpen.value = true;
}

function confirmDisconnect(): void {
    const target = disconnectTarget.value;
    if (!target) return;
    router.delete(`/integrations/connections/${target.id}`, {
        onSuccess: () => (disconnectTarget.value = null),
    });
}

function channelLabel(rule: RuleRow): string {
    return channelDisplay(rule.channel_name, rule.channel_id);
}
</script>

<template>
    <div>
        <Head title="Integrations" />

        <PageHeader title="Integrations" icon="plug" />

        <div class="integrations__stats">
            <MdsStatTile label="Active delivery rules" :value="`${summary.rules.active} / ${summary.rules.total}`" icon="plug" />
            <MdsStatTile
                label="Deliveries this month (shared with webhooks)"
                :value="formatQuota(summary.deliveries)"
                icon="trend-up"
            />
        </div>

        <section class="integrations__providers" aria-label="Available integrations">
            <MdsCard v-for="provider in providers" :key="provider.key" class="provider">
                <template #header>
                    <div class="provider__head">
                        <MdsIcon name="plug" size="sm" />
                        <h2 class="provider__title">{{ provider.label }}</h2>
                        <MdsBadge v-if="provider.connected" variant="success" label="Connected" />
                    </div>
                </template>
                <p class="provider__description">{{ provider.description }}</p>
                <p class="provider__scopes">Permissions requested: {{ provider.scopes.join(', ') }}</p>
                <p v-if="!provider.configured" class="provider__notice">
                    {{ provider.label }} isn’t configured on this deployment yet. Ask your administrator to add the
                    app credentials before connecting.
                </p>
                <div class="provider__actions">
                    <!-- A real anchor, not router.visit: the next hop is Slack's origin, and Inertia would
                         try to parse a consent page as a JSON page response. -->
                    <MdsButton
                        v-if="can.create"
                        as="a"
                        variant="primary"
                        icon-left="external-link"
                        :disabled="!provider.configured"
                        :href="provider.connect_url"
                    >
                        {{ provider.connected ? `Reconnect ${provider.label}` : `Connect ${provider.label}` }}
                    </MdsButton>
                </div>
            </MdsCard>
        </section>

        <MdsEmptyState
            v-if="!hasConnections"
            class="integrations__empty"
            illustration="default"
            headline="No workspaces connected"
            description="Connect a workspace above, then add a delivery rule to post submissions and form updates into a channel."
        />

        <section v-for="connection in connections" :key="connection.id" class="connection" aria-label="Connected workspace">
            <MdsCard>
                <template #header>
                    <div class="connection__head">
                        <div class="connection__identity">
                            <h2 class="connection__title">{{ connection.external_account_label }}</h2>
                            <MdsBadge v-bind="statusVariant(connection.status)" />
                        </div>
                        <div class="connection__actions">
                            <MdsButton
                                v-if="connection.can.update"
                                variant="secondary"
                                icon-left="plus"
                                @click="addRule(connection.id)"
                            >
                                Add rule
                            </MdsButton>
                            <MdsButton
                                v-if="connection.can.delete"
                                variant="destructive"
                                icon-left="trash"
                                @click="disconnectTarget = connection"
                            >
                                Disconnect
                            </MdsButton>
                        </div>
                    </div>
                </template>

                <dl class="connection__meta">
                    <div class="connection__meta-row">
                        <dt>Provider</dt>
                        <dd>{{ connection.provider_label }}</dd>
                    </div>
                    <div class="connection__meta-row">
                        <dt>Permissions</dt>
                        <dd>{{ connection.scopes.join(', ') || '—' }}</dd>
                    </div>
                    <div class="connection__meta-row">
                        <dt>Connected by</dt>
                        <dd>{{ connection.connected_by_name ?? '—' }}</dd>
                    </div>
                </dl>

                <p v-if="connection.status !== 'active'" class="connection__notice">
                    This workspace isn’t delivering. Reconnect it above to resume — your rules are kept.
                </p>

                <MdsDataTable
                    :columns="columns"
                    :rows="connection.rules"
                    caption="Delivery rules"
                    row-key="id"
                >
                    <template #cell-channel_name="{ row }">
                        <span class="connection__channel">{{ channelLabel(row as RuleRow) }}</span>
                    </template>
                    <template #cell-event_types="{ row }">
                        {{ (row as RuleRow).event_types.length }}
                    </template>
                    <template #cell-form_title="{ row }">
                        {{ (row as RuleRow).form_title ?? 'All forms' }}
                    </template>
                    <template #cell-status="{ row }">
                        <MdsBadge v-bind="statusVariant((row as RuleRow).status)" />
                    </template>
                    <template #row-actions="{ row }">
                        <MdsIconButton
                            icon="external-link"
                            label="View rule"
                            size="sm"
                            @click="openRule((row as RuleRow).id)"
                        />
                    </template>
                    <template #empty>
                        <MdsEmptyState
                            illustration="default"
                            headline="No delivery rules"
                            description="Add a rule to choose which events go to which channel."
                        >
                            <template v-if="connection.can.update" #action>
                                <MdsButton variant="primary" icon-left="plus" @click="addRule(connection.id)">
                                    Add rule
                                </MdsButton>
                            </template>
                        </MdsEmptyState>
                    </template>
                </MdsDataTable>
            </MdsCard>
        </section>

        <RuleFormModal
            v-model:open="createOpen"
            :connection-id="createConnectionId"
            :forms="forms"
            :event-types="eventTypes"
            :rule="null"
        />

        <MdsModal
            :open="disconnectTarget !== null"
            title="Disconnect workspace"
            @close="disconnectTarget = null"
        >
            <p class="integrations__prose">
                We’ll delete our access token for
                <strong>{{ disconnectTarget?.external_account_label }}</strong> and stop delivering to it. Your
                delivery rules are kept and paused — reconnecting the same workspace restores them.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="disconnectTarget = null">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" @click="confirmDisconnect">Disconnect</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.integrations__stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-5);
}

.integrations__providers {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(18rem, 100%), 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-5);
}

.provider__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.provider__title {
    margin: 0;
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.provider__description,
.provider__scopes,
.provider__notice {
    margin: 0 0 var(--mds-space-3);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.provider__notice {
    color: var(--mds-color-text-body);
}

/* Wrap rather than overflow — the standing 375px rule for any row of actions. */
.provider__actions,
.connection__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.integrations__empty {
    margin-bottom: var(--mds-space-5);
}

.connection {
    margin-bottom: var(--mds-space-5);
}

.connection__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-3);
}

.connection__identity {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    min-width: 0;
}

.connection__title {
    margin: 0;
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
    overflow-wrap: anywhere;
}

.connection__meta {
    display: grid;
    grid-template-columns: 10rem 1fr;
    gap: var(--mds-space-2) var(--mds-space-4);
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
}

.connection__meta-row {
    display: contents;
}

.connection__meta dt {
    color: var(--mds-color-text-secondary);
}

.connection__meta dd {
    margin: 0;
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.connection__notice {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.connection__channel {
    display: inline-block;
    /* `min()` so the ellipsis budget never exceeds the cell: at 375px the DataTable's card-per-row mode gives
       this cell well under 18rem, and a fixed max-width would push the page into horizontal scroll. */
    max-width: min(18rem, 100%);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.integrations__prose {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

@media (max-width: 480px) {
    .connection__meta {
        grid-template-columns: 1fr;
        gap: var(--mds-space-1) 0;
    }

    .connection__meta dt {
        margin-top: var(--mds-space-2);
    }
}
</style>
