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
    MdsAlert,
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
import type {
    ConsoleDomainRow,
    ImpersonationTarget,
    TenantDetailPageProps,
    UsageRow,
} from '@/components/admin/types';

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
    // P2a / ADR-0017 §D5. Sold, not built: the plan may include it and no mechanism delivers it. Until
    // this row existed the table showed these keys as in effect, because the entitlement flag resolves
    // true and nothing downstream knew the difference between an entitlement and an arrangement.
    not_provisioned: 'Included in the plan; not provisioned — no mechanism exists yet (ADR-0017)',
};

const domainColumns: DataTableColumn[] = [
    { key: 'domain', header: 'Hostname' },
    { key: 'status', header: 'Status' },
    { key: 'last_checked_at', header: 'Last checked' },
];

/* ── Support access (I11b) ───────────────────────────────────────────────────────────────────────────── */

const targetColumns: DataTableColumn[] = [
    { key: 'name', header: 'Member' },
    { key: 'email', header: 'Email' },
    { key: 'role', header: 'Role' },
    { key: 'actions', header: '' },
];

const pendingTarget = ref<ImpersonationTarget | null>(null);
const impersonating = ref(false);
const impersonationError = ref<string | null>(null);

/**
 * ⚠️ NO `onSuccess`, AND ITS ABSENCE IS THE POINT. The server answers a 409 `Inertia::location()` pointing
 * at the TENANT'S OWN HOST, which the Inertia client turns into a full browser navigation — this page is
 * gone before any success callback could run. Only the failure arm is wired.
 *
 * `onError` is reachable and real: eligibility is re-checked server-side at mint, so a member removed
 * between this page rendering and the click comes back as a validation error on `user_id` rather than a
 * navigation.
 */
function startImpersonation(): void {
    const target = pendingTarget.value;

    if (!target) return;

    impersonating.value = true;
    impersonationError.value = null;

    router.post(
        `/admin/tenants/${props.tenant.id}/impersonate`,
        { user_id: target.id },
        {
            preserveScroll: true,
            onError: (errors) => {
                impersonationError.value = errors.user_id ?? 'That member could not be signed in as.';
                pendingTarget.value = null;
            },
            onFinish: () => (impersonating.value = false),
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

function formatDay(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>

<template>
    <AdminLayout :title="tenant.name" icon="building">
        <MdsAlert v-if="adminError" tone="danger" assertive :message="adminError" />

        <div class="admin-td__grid">
            <!-- ── Identity ──────────────────────────────────────────────────────────────────────── -->
            <MdsCard>
                <template #header>
                    <h2 class="admin-td__card-title">Workspace</h2>
                </template>

                <dl class="admin-td__meta">
                    <div>
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(tenant.status)" dot /></dd>
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
                    <MdsBadge v-bind="statusVariant((row as ConsoleDomainRow).status)" dot />
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

        <!--
            I11b — support access (RBAC §9 resolved decision 1).

            Deliberately the LAST card on the page. It is the highest-consequence control in the console —
            it signs the operator in to a customer's workspace with a real member's authority — and putting
            it under the read-only cards means an operator has scrolled past what the workspace actually
            looks like before reaching for it. Most support questions are answered by the four cards above.
        -->
        <MdsCard class="admin-td__section">
            <template #header>
                <h2 class="admin-td__card-title">Support access</h2>
            </template>

            <p class="admin-td__note">
                Signing in as a member records an entry in <strong>this workspace's own audit log</strong>,
                notifies its owner, and marks everything you do as taken by a platform operator. The session
                ends automatically after 30 minutes.
            </p>

            <MdsDataTable
                :columns="targetColumns"
                :rows="impersonation.targets"
                caption="Members you can sign in as"
                row-key="id"
            >
                <template #cell-name="{ row }">
                    {{ (row as ImpersonationTarget).name }}
                    <MdsBadge v-if="(row as ImpersonationTarget).is_owner" variant="info" label="Owner" />
                </template>
                <template #cell-actions="{ row }">
                    <MdsButton
                        variant="secondary"
                        size="sm"
                        :disabled="impersonating"
                        @click="pendingTarget = row as ImpersonationTarget"
                    >
                        Sign in as
                    </MdsButton>
                </template>
                <template #empty>
                    <MdsEmptyState
                        illustration="default"
                        headline="No one to sign in as"
                        description="Support access is limited to active members who are not platform staff, and never to your own account."
                    />
                </template>
            </MdsDataTable>

            <MdsAlert v-if="impersonationError" tone="danger" assertive :message="impersonationError" />
        </MdsCard>

        <!-- Confirmation is not ceremony here: the click leaves this origin entirely and lands the operator
             inside somebody else's workspace, so the modal is the last point at which "wrong row" is
             recoverable. It names the member, which the row already did, and states the two consequences
             the operator is accountable for. -->
        <MdsModal
            :open="pendingTarget !== null"
            title="Sign in to this workspace?"
            @close="pendingTarget = null"
        >
            <p>
                You will be signed in to <strong>{{ tenant.name }}</strong> as
                <strong>{{ pendingTarget?.name }}</strong> with that member's full permissions.
            </p>
            <p>
                The workspace owner is notified, and the access is recorded in the workspace's audit log
                where its members can see it.
            </p>

            <template #actions>
                <MdsButton variant="tertiary" @click="pendingTarget = null">Cancel</MdsButton>
                <MdsButton variant="primary" :loading="impersonating" @click="startImpersonation">
                    Sign in as {{ pendingTarget?.name }}
                </MdsButton>
            </template>
        </MdsModal>

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
            <!-- `#actions`, not `#footer` — MdsModal's slot is `actions` (MdsCard's is `footer`). Getting
                 this wrong renders the confirm buttons nowhere at all, silently. -->
            <template #actions>
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
