<script setup lang="ts">
/**
 * Optimistic-concurrency resolution (§8). When a content PATCH 409s (the row changed elsewhere since it
 * was loaded), this dialog shows BOTH sides and lets the author choose — the in-flight edit is never
 * silently discarded. "Keep mine" re-saves the author's values over the other change; "Use theirs" adopts
 * the server's current values. Either way both sides were seen first.
 */
import { MdsButton, MdsModal } from '@meridian/design-system';
import type { ConflictState } from './useBuilderStore';

defineProps<{ conflict: ConflictState | null }>();
const emit = defineEmits<{ resolve: [choice: 'mine' | 'theirs'] }>();
</script>

<template>
    <MdsModal
        :open="conflict !== null"
        title="This item changed elsewhere"
        close-label="Dismiss"
        @close="emit('resolve', 'theirs')"
    >
        <div v-if="conflict" class="conflict">
            <p class="conflict__lead">
                Someone else edited this {{ conflict.kind }} since you opened it. Choose which version to keep —
                your edit hasn't been lost.
            </p>
            <div class="conflict__cols">
                <div class="conflict__col">
                    <h3 class="conflict__col-title">Your changes</h3>
                    <p class="conflict__val">{{ conflict.mine.label }}</p>
                    <p class="conflict__key">{{ conflict.mine.key }}</p>
                </div>
                <div class="conflict__col">
                    <h3 class="conflict__col-title">Their changes</h3>
                    <p class="conflict__val">{{ conflict.theirs.label }}</p>
                    <p class="conflict__key">{{ conflict.theirs.key }}</p>
                </div>
            </div>
        </div>
        <template #actions>
            <MdsButton variant="tertiary" @click="emit('resolve', 'theirs')">Use theirs</MdsButton>
            <MdsButton variant="primary" @click="emit('resolve', 'mine')">Keep mine</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.conflict {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.conflict__lead {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.conflict__cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--mds-space-4);
}

.conflict__col {
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.conflict__col-title {
    margin: 0 0 var(--mds-space-2);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.conflict__val {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
}

.conflict__key {
    margin: var(--mds-space-1) 0 0;
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-mono, monospace);
    font-size: var(--mds-type-caption-font-size);
}
</style>
