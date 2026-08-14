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
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MdsAlert,
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
import type {
    ConnectionWithRules,
    DestinationKind,
    Option,
    ProviderCard,
    RuleRow,
} from '@/components/integrations/types';

type Quota = { used: number; limit: number | null };

const props = defineProps<{
    providers: ProviderCard[];
    connections: ConnectionWithRules[];
    summary: { rules: { active: number; total: number }; deliveries: Quota };
    forms: Option[];
    eventTypes: Option[];
    can: { create: boolean };
}>();

// "Destination" rather than "Channel" (H16b): the column now carries a Slack channel OR a spreadsheet + tab,
// and the server resolves which — see `destination_label` on the presenter. A per-provider heading would mean
// a table whose columns differ between the two cards on this very page.
const columns: DataTableColumn[] = [
    { key: 'name', header: 'Rule' },
    { key: 'destination_label', header: 'Destination' },
    { key: 'event_types', header: 'Events' },
    { key: 'form_title', header: 'Scope' },
    { key: 'status', header: 'Status' },
];

const createOpen = ref(false);
const createConnectionId = ref<string | null>(null);
const createProvider = ref<string | null>(null);
const createDestinationKind = ref<DestinationKind | null>(null);
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
    // The modal renders a whole different destination editor per provider (H16b), so it needs the key, not
    // just the id. Resolved here rather than fetched: the page already carries every connection.
    const connection = props.connections.find((c) => c.id === connectionId);
    createProvider.value = connection?.provider ?? null;
    // H16c — the KIND decides which behaviours the modal enables; the provider only decides which of the two
    // tabular editors it mounts. Both come from the connection the page already carries.
    createDestinationKind.value = connection?.destination_kind ?? null;
    createOpen.value = true;
}

function confirmDisconnect(): void {
    const target = disconnectTarget.value;
    if (!target) return;
    router.delete(`/integrations/connections/${target.id}`, {
        onSuccess: () => (disconnectTarget.value = null),
    });
}

/**
 * The destination cell (H16b).
 *
 * `destination_label` is server-resolved so this cell never branches on the provider — the presenter's own
 * `providerDescription()` is a `default`-less match for the same reason: resolving a provider is the server's
 * job. `channelDisplay` remains the fallback for a rule stored before that field existed, which is also what
 * keeps its `##general` normalisation reachable.
 */
function destinationLabel(rule: RuleRow): string {
    return rule.destination_label ?? channelDisplay(rule.channel_name, rule.channel_id);
}

/** The standing caveat for a connected provider, looked up from the catalog the page already carries. */
function providerNotice(providerKey: string): string | null {
    return props.providers.find((p) => p.key === providerKey)?.notice ?? null;
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
                <!-- J4a: a bare paragraph with no `role` becomes an info `MdsAlert` — a standing fact about
                     the deployment that assistive tech was never told about. -->
                <MdsAlert
                    v-if="!provider.configured"
                    class="provider__notice"
                    tone="info"
                    :message="`${provider.label} isn’t configured on this deployment yet. Ask your administrator to add the app credentials before connecting.`"
                />
                <!-- H16b — a standing condition of the DEPLOYMENT, stated up front rather than left for the
                     weekly "Reconnect needed" badge to imply. Icon AND words carry the meaning (WCAG 1.4.1);
                     the tinted surface follows DomainCard's `--wait` recipe, whose bg/fg pair is one of the
                     few in the token set with a measured contrast guarantee.
                     ⚠️ J4a — THE REFUSAL THAT USED TO BE HERE IS CLOSED, NOT OVERTURNED. It read
                     "deliberately not MdsBanner: that primitive takes a single-line `message` string, and
                     this is three sentences whose whole job is to name a cause". That objection was to
                     MdsBanner SPECIFICALLY and it was right; `MdsAlert` exists because of it. Note what this
                     block already was: `status-warning-{bg,fg}` painted by hand, with an `alert` glyph and
                     words beside it — an MdsAlert built at the call site. The only thing that changes is
                     that the tokens and the 1.4.1 discipline now live in one place. -->
                <MdsAlert
                    v-if="provider.notice"
                    class="provider__caution"
                    tone="warning"
                    :message="provider.notice"
                />
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
                            <MdsBadge v-bind="statusVariant(connection.status)" dot />
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

                <!-- J4a: warning, because a connection that is configured and not delivering is a
                     degradation rather than a neutral fact. Not `assertive` — it was already true on load. -->
                <MdsAlert v-if="connection.status !== 'active'" class="connection__notice" tone="warning">
                    <p>
                        This workspace isn’t delivering. Reconnect it above to resume — your rules are kept.
                        <!-- H16b — the sentence PROGRESS:846 asks for. Repeated here rather than only on the
                             provider card above because THIS is where the tenant arrives when it happens: the
                             status badge already says "Reconnect needed", and a red badge with no cause reads
                             as our bug rather than as Google's published policy. -->
                        <template v-if="providerNotice(connection.provider)">
                            {{ providerNotice(connection.provider) }}
                        </template>
                    </p>
                </MdsAlert>

                <!-- Caption names the workspace: a tenant can connect several, and "Delivery rules" three
                     times over tells a screen-reader user nothing about which one they are in. -->
                <MdsDataTable
                    :columns="columns"
                    :rows="connection.rules"
                    :caption="`Delivery rules — ${connection.external_account_label}`"
                    row-key="id"
                >
                    <template #cell-destination_label="{ row }">
                        <span class="connection__channel">{{ destinationLabel(row as RuleRow) }}</span>
                    </template>
                    <template #cell-event_types="{ row }">
                        {{ (row as RuleRow).event_types.length }}
                    </template>
                    <!-- J2d — see `webhooks/Index.vue`: `form_url` is server-resolved, absent when the
                         reader cannot open the hub or the form is gone. -->
                    <template #cell-form_title="{ row }">
                        <Link v-if="(row as RuleRow).form_url" :href="(row as RuleRow).form_url!" class="scope-link">
                            {{ (row as RuleRow).form_title }}
                        </Link>
                        <template v-else>{{ (row as RuleRow).form_title ?? 'All forms' }}</template>
                    </template>
                    <template #cell-status="{ row }">
                        <MdsBadge v-bind="statusVariant((row as RuleRow).status)" dot />
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
            :provider="createProvider"
            :destination-kind="createDestinationKind"
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


/* H16b — the standing 7-day caveat. A surface of its own rather than the same grey as every other hint,
   for the reason DomainCard's `--wait` note gives: this is the state a tenant is most likely to misread,
   and the misreading here is "this product's token handling is broken". `status-warning-{bg,fg}` is one of
   the few pairs in the token set with a measured contrast guarantee, and the icon carries the meaning
   alongside the colour (WCAG 1.4.1). */


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
/* J4a — the alerts own tint, padding, radius and type now; only the spacing they contributed to the
   card's vertical rhythm survives. ⚠️ `.provider__notice` was declared TWICE in this stylesheet, the
   second block existing only to override the first's colour — a pre-existing smell that disappears with
   the hand-rolled markup rather than being carried forward. */
.provider__notice {
    margin-bottom: var(--mds-space-3);
}

.connection__notice {
    margin-bottom: var(--mds-space-4);
}

.connection__notice p {
    margin: 0;
}
</style>
