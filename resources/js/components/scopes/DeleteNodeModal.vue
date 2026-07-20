<script setup lang="ts">
/**
 * Deleting a scope node (Increment G10b2).
 *
 * The warning names BOTH consequences, because they are separate losses and only one of them is obvious:
 * the child scopes cascade away, the forms beneath are silently UN-SCOPED (the FK is ON DELETE SET NULL, so
 * they survive but stop being reachable through the hierarchy), and every grant on the subtree is deleted
 * in the same transaction. That last one is the quiet one — `resource_grants` is a morph with no FK, so
 * nothing would cascade them and ScopeNodeService::delete() clears them explicitly rather than leaving
 * orphaned authorization rows behind.
 *
 * Confirm stays disabled until the impact resolves: this is destructive and the counts are the whole basis
 * for the decision.
 */
import { ref, watch } from 'vue';
import { MdsButton, MdsModal } from '@meridian/design-system';
import { fetchNodeImpact } from './scopesClient';
import type { NodeImpact, ScopeNodeRow } from './types';

const props = defineProps<{ open: boolean; node: ScopeNodeRow }>();
const emit = defineEmits<{ 'update:open': [boolean]; confirm: [] }>();

const impact = ref<NodeImpact | null>(null);
const loading = ref(false);
const failed = ref(false);

let seq = 0;

watch(
    () => [props.open, props.node.id] as const,
    async ([open, nodeId]) => {
        if (!open) return;
        impact.value = null;
        failed.value = false;
        loading.value = true;
        const mine = ++seq;

        const payload = await fetchNodeImpact(nodeId);
        if (mine !== seq) return;
        impact.value = payload;
        failed.value = payload === null;
        loading.value = false;
    },
    { immediate: true },
);
</script>

<template>
    <MdsModal :open="open" title="Delete scope" @close="emit('update:open', false)">
        <p>
            Delete <strong>{{ node.name }}</strong>?
        </p>

        <div class="delete-node__impact" role="status">
            <template v-if="loading">
                <p>Checking what this would affect…</p>
            </template>
            <template v-else-if="failed">
                <p class="delete-node__warn">Couldn't check what this would affect. Try again.</p>
            </template>
            <template v-else-if="impact">
                <ul class="delete-node__list">
                    <li v-if="impact.deletion.nodes > 0">
                        <strong>{{ impact.deletion.nodes }}</strong>
                        {{ impact.deletion.nodes === 1 ? 'scope beneath it is' : 'scopes beneath it are' }}
                        deleted too.
                    </li>
                    <li v-if="impact.deletion.forms > 0">
                        <strong>{{ impact.deletion.forms }}</strong>
                        {{ impact.deletion.forms === 1 ? 'form keeps' : 'forms keep' }}
                        its data but
                        {{ impact.deletion.forms === 1 ? 'is' : 'are' }}
                        no longer assigned to any scope.
                    </li>
                    <li v-if="impact.deletion.grants > 0">
                        <strong>{{ impact.deletion.grants }}</strong>
                        {{ impact.deletion.grants === 1 ? 'access grant is' : 'access grants are' }}
                        revoked — people who reached those forms through this branch will lose access.
                    </li>
                    <li v-if="impact.deletion.nodes === 0 && impact.deletion.forms === 0 && impact.deletion.grants === 0">
                        Nothing else is affected.
                    </li>
                </ul>
                <p class="delete-node__warn">This cannot be undone.</p>
            </template>
        </div>

        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Cancel</MdsButton>
            <MdsButton
                variant="destructive"
                icon-left="trash"
                :disabled="loading || failed"
                @click="emit('confirm')"
            >
                Delete scope
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.delete-node__impact {
    margin-block-start: var(--mds-space-4);
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
}

.delete-node__impact p {
    margin: 0;
}

.delete-node__list {
    margin: 0 0 var(--mds-space-2);
    padding-inline-start: var(--mds-space-5);
}

.delete-node__warn {
    color: var(--mds-color-danger-text);
}
</style>
