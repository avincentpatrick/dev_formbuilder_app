<script setup lang="ts">
/**
 * One workspace in depth, for the platform operator (Increment I7b, RBAC §9 console scope).
 *
 * Until this page existed the console could suspend a workspace but could not see its plan, its usage or
 * its domains — and `POST /admin/tenants/{tenant}/plan` had existed since H5a with no UI at all, reachable
 * only by hand. This is that route's first caller.
 *
 * Everything here is READ except the plan form and the suspend/reactivate pair. In particular the domains
 * card offers no verify / make-primary / remove, and that is a decision rather than an omission:
 *   1. CustomDomainService::auditDomain() returns SILENTLY unless the ambient tenant context matches the
 *      domain's tenant. From the console there is none, so every domain write would succeed and emit NO
 *      audit row — a silent compliance gap on the one surface RBAC §9 requires to be transparent to the
 *      affected workspace.
 *   2. verify() performs a live DNS lookup with no timeout, deliberately off the request path.
 *   3. activate is CLI-only by design (ADR-0012 §D6) and presupposes a hand-installed certificate.
 *   4. release destroys a live respondent-facing hostname, and the tenant's own page already offers it.
 *
 * Usage renders as MdsStatTile + text rather than a progress bar: DSR §3.9 defers the determinate bar, and
 * an internal operator console is the wrong place to un-defer it (the tenant-facing Plan & Usage panel is
 * the right first consumer). The tile's own `caption` and `unavailable` states already carry the honesty
 * cases a bar cannot express.
 */
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsCard,
    MdsDataTable,
    MdsEmptyState,
    MdsFormField,
    MdsModal,
    MdsSelect,
    MdsStatTile,
    statusVariant,
    type DataTableColumn,
    type IconName,
} from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import type { ConsoleDomainRow, TenantDetailPageProps, UsageRow } from '@/components/admin/types';

const props = defineProps<TenantDetailPageProps>();

const page = usePage();
const adminError = computed(() => page.props.errors?.admin);

/* ── Plan assignment ─────────────────────────────────────────────────────────────────────────────────── */

const form = useForm({
    plan_id: props.plan.current?.plan_id ?? '',
    billing_interval: props.plan.current?.interval ?? 'monthly',
});

/**
 * Held-from-sale tiers stay in the list and are LABELLED, never hidden — ADR-0008 §D6 seeds Business and
 * Enterprise inactive precisely because they remain admin-assignable, and hiding them would make this form
 * unable to do the one thing the service tests prove it can.
 */
const planOptions = computed(() =>
    props.plan.catalog.map((entry) => ({
        value: entry.id,
        label: entry.is_active ? entry.name : `${entry.name} — held from sale`,
    })),
);

/**
 * Intervals track the SELECTED plan, not a static list: `billing_interval_options` is what a plan offers,
 * and although every seeded tier offers both today, deriving it here is right the day one stops.
 */
const intervalOptions = computed(() => {
    const selected = props.plan.catalog.find((entry) => entry.id === form.plan_id);
    return selected?.interval_options ?? props.plan.intervals;
});

function assignPlan(): void {
    form.post(`/admin/tenants/${props.tenant.id}/plan`, { preserveScroll: true });
}

/* ── Suspend / reactivate ────────────────────────────────────────────────────────────────────────────── */

const pendingAction = ref<'suspend' | 'reactivate' | null>(null);
const busy = ref(false);

function confirmAction(): void {
    if (!pendingAction.value) return;
    busy.value = true;
    router.post(
        `/admin/tenants/${props.tenant.id}/${pendingAction.value}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (busy.value = false),
            onSuccess: () => (pendingAction.value = null),
        },
    );
}

/* ── Usage + features + domains ──────────────────────────────────────────────────────────────────────── */

const METRIC_ICONS: Record<string, IconName> = {
    forms_count: 'forms',
    storage_bytes: 'upload',
    active_seats: 'users',
    webhook_endpoints_count: 'plug',
};

function metricIcon(metric: string): IconName {
    return METRIC_ICONS[metric] ?? 'activity';
}

/**
 * `unlimited`, never `!limit` — the Free tier seeds a literal `0` for three metrics, and treating those as
 * unrestricted would report three hard-blocked quotas as infinite.
 */
function metricCaption(row: UsageRow): string | undefined {
    if (row.unlimited) return 'Unlimited on this plan';
    return row.at_limit ? 'At the plan limit' : undefined;
}

const flowColumns: DataTableColumn[] = [
    { key: 'label', header: 'Metric' },
    { key: 'display', header: 'Used / limit' },
];

const featureColumns: DataTableColumn[] = [
    { key: 'label', header: 'Capability' },
    { key: 'plan_grants', header: 'Plan grants' },
    { key: 'effective', header: 'In effect' },
    { key: 'reason', header: 'Note' },
];

const REASON_COPY: Record<string, string> = {
    tenant_disabled: 'Switched off by the workspace in Settings → Modules',
    legacy_override: 'Grandfathered (ADR-0008 §D5)',
    unavailable: 'Granted by the plan but not in effect',
};

const domainColumns: DataTableColumn[] = [
    { key: 'domain', header: 'Hostname' },
    { key: 'status', header: 'Status' },
    { key: 'last_checked_at', header: 'Last checked' },
];

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

function formatDay(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>

<template>
    <AdminLayout :title="tenant.name" icon="building">
        <p v-if="adminError" class="admin-td__alert" role="alert">{{ adminError }}</p>

        <div class="admin-td__grid">
            <!-- ── Identity ──────────────────────────────────────────────────────────────────────── -->
            <MdsCard>
                <template #header>
                    <h2 class="admin-td__card-title">Workspace</h2>
                </template>

                <dl class="admin-td__meta">
                    <div>
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(tenant.status)" /></dd>
                    </div>
                    <div>
                        <dt>Slug</dt>
                        <dd class="admin-td__mono">{{ tenant.slug }}</dd>
                    </div>
                    <div>
                        <dt>Owner</dt>
                        <dd v-if="tenant.owner">{{ tenant.owner.name }} · {{ tenant.owner.email }}</dd>
                        <!-- Null is honest, not missing: the owner may no longer be an active member. -->
                        <dd v-else>Owner is not an active member</dd>
                    </div>
                    <div>
                        <dt>Created</dt>
                        <dd>{{ formatDay(tenant.created_at) }}</dd>
                    </div>
                    <div>
                        <dt>App host</dt>
                        <dd class="admin-td__mono">{{ tenant.app_host }}</dd>
                    </div>
                    <div>
                        <dt>Respondent host</dt>
                        <dd class="admin-td__mono">{{ tenant.public_host }}</dd>
                    </div>
                    <div>
                        <dt>Locale</dt>
                        <dd>{{ tenant.default_locale }}</dd>
                    </div>
                    <div v-if="tenant.maintenance_mode">
                        <dt>Maintenance</dt>
                        <dd>{{ tenant.maintenance_message ?? 'On' }}</dd>
                    </div>
                </dl>

                <template #footer>
                    <MdsButton
                        v-if="tenant.is_active"
                        variant="tertiary"
                        size="sm"
                        @click="pendingAction = 'suspend'"
                    >
                        Suspend workspace
                    </MdsButton>
                    <MdsButton v-else variant="primary" size="sm" @click="pendingAction = 'reactivate'">
                        Reactivate workspace
                    </MdsButton>
                </template>
            </MdsCard>

            <!-- ── Plan ──────────────────────────────────────────────────────────────────────────── -->
            <MdsCard>
                <template #header>
                    <h2 class="admin-td__card-title">Plan</h2>
                </template>

                <p v-if="plan.current" class="admin-td__current">
                    <strong>{{ plan.current.name ?? plan.current.code }}</strong>
                    · {{ plan.current.interval_label }}
                    · {{ plan.current.stripe_status }}
                    <span v-if="plan.current.assigned_at"> · assigned {{ formatDay(plan.current.assigned_at) }}</span>
                </p>
                <!-- Three distinct states, each with its own copy. An em dash for any of them would hide a
                     real difference between "on the free default" and "no catalog seeded at all". -->
                <p v-else-if="plan.effective" class="admin-td__current">
                    <strong>{{ plan.effective.name }}</strong> — default. No subscription on record.
                </p>
                <p v-else class="admin-td__current">No plan catalog seeded.</p>

                <p
                    v-if="plan.current && plan.current.subscription_name !== 'default'"
                    class="admin-td__note"
                >
                    Governing subscription is <code>{{ plan.current.subscription_name }}</code
                    >, not <code>default</code> — assigning below writes the <code>default</code> row.
                </p>

                <form class="admin-td__form" @submit.prevent="assignPlan">
                    <MdsFormField label="Assign plan" input-id="tenant-plan" :error="form.errors.plan_id">
                        <MdsSelect id="tenant-plan" v-model="form.plan_id" :options="planOptions" />
                    </MdsFormField>
                    <MdsFormField
                        label="Billing interval"
                        input-id="tenant-interval"
                        :error="form.errors.billing_interval"
                    >
                        <MdsSelect
                            id="tenant-interval"
                            v-model="form.billing_interval"
                            :options="intervalOptions"
                        />
                    </MdsFormField>
                    <MdsButton type="submit" :loading="form.processing" :disabled="!form.plan_id">
                        Assign
                    </MdsButton>
                    <p v-if="form.recentlySuccessful" class="admin-td__saved" role="status">Plan assigned.</p>
                </form>
            </MdsCard>
        </div>

        <!-- ── Usage ─────────────────────────────────────────────────────────────────────────────── -->
        <MdsCard class="admin-td__section">
            <template #header>
                <h2 class="admin-td__card-title">Usage</h2>
            </template>

            <p v-if="!usage.available" class="admin-td__note">
                No plan resolved for this workspace, so there are no limits to report against.
            </p>

            <template v-else>
                <h3 class="admin-td__subhead">Current levels</h3>
                <div class="admin-td__tiles">
                    <MdsStatTile
                        v-for="row in usage.gauges"
                        :key="row.metric"
                        :label="row.label"
                        :value="row.display"
                        :icon="metricIcon(row.metric)"
                        :caption="metricCaption(row)"
                    />
                </div>

                <h3 class="admin-td__subhead">This period</h3>
                <!-- "recorded", not "used": an unmetered flow reads 0, which is indistinguishable from a
                     genuine zero, and the header is the honest place to say so. -->
                <p class="admin-td__note">Counters recorded for the current billing period.</p>
                <MdsDataTable
                    :columns="flowColumns"
                    :rows="usage.flows"
                    caption="Per-period usage counters"
                    row-key="metric"
                />
            </template>
        </MdsCard>

        <!-- ── Capabilities ──────────────────────────────────────────────────────────────────────── -->
        <MdsCard v-if="features.length > 0" class="admin-td__section">
            <template #header>
                <h2 class="admin-td__card-title">Capabilities</h2>
            </template>

            <p class="admin-td__note">
                What the plan grants, beside what the workspace effectively has. A difference is either a
                grandfathered override or the workspace's own module switch.
            </p>

            <MdsDataTable
                :columns="featureColumns"
                :rows="features"
                caption="Plan capabilities versus effective capabilities"
                row-key="key"
            >
                <template #cell-plan_grants="{ value }">{{ value ? 'Yes' : 'No' }}</template>
                <template #cell-effective="{ value }">{{ value ? 'Yes' : 'No' }}</template>
                <template #cell-reason="{ value }">
                    {{ value ? REASON_COPY[String(value)] : '—' }}
                </template>
            </MdsDataTable>
        </MdsCard>

        <!-- ── Domains ───────────────────────────────────────────────────────────────────────────── -->
        <MdsCard class="admin-td__section">
            <template #header>
                <h2 class="admin-td__card-title">Custom domains</h2>
            </template>

            <p class="admin-td__note">
                Read-only here. Claiming, verifying and removing a hostname happen in the workspace's own
                settings, so the action is recorded in its audit log.
            </p>

            <MdsDataTable
                :columns="domainColumns"
                :rows="domains.rows"
                caption="Custom domains"
                row-key="domain"
            >
                <template #cell-domain="{ row }">
                    <span class="admin-td__mono">{{ (row as ConsoleDomainRow).domain }}</span>
                    <MdsBadge
                        v-if="(row as ConsoleDomainRow).is_public_host"
                        variant="info"
                        label="Respondent host"
                    />
                </template>
                <template #cell-status="{ row }">
                    <MdsBadge v-bind="statusVariant((row as ConsoleDomainRow).status)" />
                </template>
                <template #cell-last_checked_at="{ row }">
                    {{ formatDate((row as ConsoleDomainRow).last_checked_at) }}
                </template>
                <template #empty>
                    <MdsEmptyState
                        illustration="default"
                        headline="No custom domains"
                        :description="`Respondent links use ${domains.public_host}.`"
                    />
                </template>
            </MdsDataTable>
        </MdsCard>

        <MdsModal
            :open="pendingAction !== null"
            :title="pendingAction === 'suspend' ? 'Suspend workspace' : 'Reactivate workspace'"
            @close="pendingAction = null"
        >
            <p>
                {{
                    pendingAction === 'suspend'
                        ? `Members of ${tenant.name} will lose access until it is reactivated.`
                        : `Members of ${tenant.name} will regain access immediately.`
                }}
            </p>
            <template #footer>
                <MdsButton variant="secondary" @click="pendingAction = null">Cancel</MdsButton>
                <MdsButton
                    :variant="pendingAction === 'suspend' ? 'destructive' : 'primary'"
                    :loading="busy"
                    @click="confirmAction"
                >
                    {{ pendingAction === 'suspend' ? 'Suspend' : 'Reactivate' }}
                </MdsButton>
            </template>
        </MdsModal>
    </AdminLayout>
</template>

<style scoped>
/* `--mds-color-status-danger-fg`, never `--mds-color-danger-text` — the latter resolves to danger-300 in
   dark and fails contrast on bg-surface. /admin/* is outside the axe sweep, so nothing would catch it. */
.admin-td__alert {
    margin: 0 0 var(--mds-space-4);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-action-danger-bg);
    border-radius: var(--mds-radius-md);
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-md-font-size);
}

.admin-td__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));
    gap: var(--mds-space-4);
}

.admin-td__section {
    margin-top: var(--mds-space-4);
}

.admin-td__card-title {
    margin: 0;
    font-size: var(--mds-type-heading-3-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.admin-td__subhead {
    margin: var(--mds-space-4) 0 var(--mds-space-2);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--mds-color-text-secondary);
}

.admin-td__subhead:first-child {
    margin-top: 0;
}

.admin-td__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
    gap: var(--mds-space-3);
    margin: 0;
}

.admin-td__meta dt {
    margin-bottom: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.admin-td__meta dd {
    margin: 0;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
}

.admin-td__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.admin-td__current {
    margin: 0 0 var(--mds-space-3);
    color: var(--mds-color-text-body);
}

.admin-td__note {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.admin-td__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    align-items: flex-start;
}

.admin-td__saved {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-status-success-fg);
}

.admin-td__tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: var(--mds-space-3);
}
</style>
