<script setup lang="ts">
/**
 * Granting access on a scope node (Increment G10b2) — the escalation surface, and the reason
 * `includes_descendants` defaults to false.
 *
 * The blast-radius preview is the point of this component. It is the number an administrator makes the
 * decision on, so a few rules hold throughout:
 *
 *   - THE PREVIEW IS ADVISORY; THE SERVER IS THE DECISION. Nothing here is echoed back as a success
 *     message, because the counts can go stale between opening the modal and confirming.
 *   - The counterfactual ("N more under M descendant scopes") is shown even when the toggle is OFF — that
 *     is exactly the reach the checkbox would buy, and hiding it is how an admin ticks it without knowing.
 *   - Toggling `includes_descendants` RE-LABELS from the cached payload and never refetches: `direct` and
 *     `descendant` both arrive in one call.
 *   - The descendant SCOPE count is derived client-side from the node list rather than from
 *     `deletion.nodes`, which is unfiltered by is_active while `reach.descendant` excludes inactive
 *     branches. Composing the two would advertise "3 forms under 12 scopes" where several of the 12
 *     contribute nothing by construction.
 *   - Confirm stays disabled until the preview resolves, and for a deactivated node it stays disabled
 *     entirely: nodeReach returns 0/0 under an inactive branch, so a grant there would confer nothing and
 *     the raw zero would otherwise read as a bug.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsCheckbox, MdsFormField, MdsModal, MdsSelect } from '@meridian/design-system';
import { fetchNodeImpact } from './scopesClient';
import type { CapacityOption, NodeGrant, NodeImpact, Recipient, ScopeNodeRow } from './types';

const props = defineProps<{
    open: boolean;
    node: ScopeNodeRow;
    nodes: ScopeNodeRow[];
    recipients: Recipient[];
    capacities: CapacityOption[];
    existing: NodeGrant[];
}>();

const emit = defineEmits<{ 'update:open': [boolean]; granted: [] }>();

const form = useForm({
    scope_node_id: props.node.id,
    user_id: '',
    capacity: 'reviewer',
    includes_descendants: false,
});

const impact = ref<NodeImpact | null>(null);
const loading = ref(false);
const failed = ref(false);

// Rapid target changes can resolve out of order and paint one node's numbers under another's name.
let seq = 0;
let debounce: ReturnType<typeof setTimeout> | undefined;

function loadImpact(nodeId: string): void {
    clearTimeout(debounce);
    impact.value = null;
    failed.value = false;
    loading.value = true;
    const mine = ++seq;

    debounce = setTimeout(async () => {
        const payload = await fetchNodeImpact(nodeId);
        if (mine !== seq) return;
        impact.value = payload;
        failed.value = payload === null;
        loading.value = false;
    }, 250);
}

watch(
    () => [props.open, props.node.id] as const,
    ([open, nodeId]) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        form.scope_node_id = nodeId;
        form.capacity = props.capacities.find((c) => c.allowed)?.value ?? 'reviewer';
        loadImpact(nodeId);
    },
    { immediate: true },
);

/** Active descendant scopes — the honest denominator for the counterfactual. See the header note. */
const descendantScopes = computed(() => {
    const rows = props.nodes;
    const index = rows.findIndex((r) => r.id === props.node.id);
    if (index < 0) return 0;
    let count = 0;
    for (let i = index + 1; i < rows.length && rows[i].depth > props.node.depth; i++) {
        if (rows[i].is_active) count++;
    }
    return count;
});

const reach = computed(() => {
    if (!impact.value) return 0;
    const { direct, descendant } = impact.value.reach;
    return form.includes_descendants ? direct + descendant : direct;
});

const recipientOptions = computed(() =>
    props.recipients.map((r) => ({ value: r.id, label: `${r.name} (${r.email})` })),
);

/**
 * A capacity the actor cannot hand out is DISABLED with its reason, not hidden. Under the shipped role
 * matrix nothing is ever disabled — Owner and Admin hold both `.any` permissions — so this is forward
 * looking: an option that is always present and then silently vanishes for one future custom role reads as
 * a bug, where a disabled option with a reason reads as policy. ResourceGrantPolicy::grantCapacity remains
 * the enforcement point either way.
 */
const capacityOptions = computed(() =>
    props.capacities.map((c) => ({ value: c.value, label: c.label, disabled: !c.allowed })),
);

const disallowedReason = computed(
    () => props.capacities.find((c) => c.value === form.capacity && !c.allowed)?.reason ?? null,
);

/** `grant()` upserts on (target, user), so a second grant REPLACES rather than adds. Say so. */
const replaces = computed(() => props.existing.find((g) => g.user_id === form.user_id) ?? null);

const inactive = computed(() => !props.node.is_active);
const blocked = computed(() => loading.value || failed.value || inactive.value || form.user_id === '');

const confirmLabel = computed(() => {
    if (loading.value) return 'Checking access…';
    if (failed.value) return 'Grant access';
    return reach.value === 1 ? 'Grant access to 1 form' : `Grant access to ${reach.value} forms`;
});

function submit(): void {
    form.post('/resource-grants', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            emit('granted');
            emit('update:open', false);
        },
    });
}
</script>

<template>
    <MdsModal :open="open" title="Grant access" @close="emit('update:open', false)">
        <p class="grant__target">
            On <strong>{{ node.name }}</strong>
        </p>

        <MdsFormField v-slot="{ id, describedby, invalid }" label="Teammate" required :error="form.errors.user_id">
            <MdsSelect
                :id="id"
                v-model="form.user_id"
                :options="recipientOptions"
                :describedby="describedby"
                :invalid="invalid"
                placeholder="Choose a teammate"
            />
        </MdsFormField>

        <MdsFormField
            v-slot="{ id, describedby, invalid }"
            label="Capacity"
            :help="disallowedReason ?? 'Editors can change the form; reviewers can review its submissions.'"
            :error="form.errors.capacity"
        >
            <MdsSelect
                :id="id"
                v-model="form.capacity"
                :options="capacityOptions"
                :describedby="describedby"
                :invalid="invalid"
            />
        </MdsFormField>

        <MdsCheckbox
            v-model="form.includes_descendants"
            label="Also everything beneath this scope"
        />

        <!-- Live region: the confirm button is disabled until this resolves, so its content is the thing
             the administrator is actually deciding on. -->
        <div class="grant__impact" role="status">
            <template v-if="inactive">
                <p class="grant__warn">
                    This scope is deactivated, so a grant here confers no access until it is reactivated.
                </p>
            </template>
            <template v-else-if="loading">
                <p>Checking what this would reach…</p>
            </template>
            <template v-else-if="failed">
                <p class="grant__warn">Couldn't check what this would reach. Try again.</p>
            </template>
            <template v-else-if="impact">
                <p>
                    Reaches <strong>{{ reach }}</strong>
                    {{ reach === 1 ? 'form' : 'forms' }}, including any archived or deleted ones.
                </p>
                <p v-if="!form.includes_descendants && impact.reach.descendant > 0" class="grant__counterfactual">
                    {{ impact.reach.descendant }} more
                    {{ impact.reach.descendant === 1 ? 'form sits' : 'forms sit' }}
                    under {{ descendantScopes }}
                    {{ descendantScopes === 1 ? 'scope' : 'scopes' }} beneath this one, and would be included
                    if you tick the box above.
                </p>
                <p v-if="replaces" class="grant__replaces">
                    Replaces their existing <strong>{{ replaces.capacity }}</strong> grant here.
                </p>
            </template>
        </div>

        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Cancel</MdsButton>
            <MdsButton
                variant="primary"
                icon-left="shield"
                :disabled="blocked"
                :loading="form.processing"
                @click="submit"
            >
                {{ confirmLabel }}
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.grant__target {
    color: var(--mds-color-text-secondary);
}

.grant__impact {
    margin-block-start: var(--mds-space-4);
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
}

.grant__impact p {
    margin: 0;
}

.grant__impact p + p {
    margin-block-start: var(--mds-space-2);
}

.grant__counterfactual,
.grant__replaces {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.grant__warn {
    color: var(--mds-color-danger-text);
}
</style>
