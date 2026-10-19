<script setup lang="ts">
/**
 * Optimistic-concurrency resolution (§8). When a content PATCH 409s (the row changed elsewhere since it
 * was loaded), this dialog shows BOTH sides and lets the author choose — the in-flight edit is never
 * silently discarded. "Keep mine" re-saves the author's values over the other change; "Use the saved version"
 * (M176; it read "Use theirs") adopts the server's current values. Either way both sides were seen first.
 *
 * M176 (`R-164579e8`) — it lists EVERY setting where the two copies part, in the panel's words, side by side. It
 * printed only the label and the key, so the testers saw two identical columns when the difference was Required.
 * And closing it no longer means "Use theirs": the X, Escape and the backdrop DEFER the choice — the author's edit
 * stays on screen and in the journal, and the builder's notice brings the dialog back. Only the two buttons choose.
 */
import { computed } from 'vue';

import { MdsButton, MdsModal } from '@meridian/design-system';
import type { LabelLookup } from './condition-describer';
import { conflictDifferences } from './conflict-diff';
import { shortLabel } from './label-text';
import type { BuilderEnums } from './types';
import type { ConflictState } from './useBuilderStore';

const props = defineProps<{
    conflict: ConflictState | null;
    /** The author closed the dialog without choosing; the conflict is still pending. */
    deferred?: boolean;
    enums: BuilderEnums;
    labels?: LabelLookup;
    typeLabel?: (value: string) => string;
}>();
const emit = defineEmits<{ resolve: [choice: 'mine' | 'theirs']; defer: [] }>();

const differences = computed(() =>
    props.conflict === null
        ? []
        : conflictDifferences(props.conflict.kind, props.conflict.mine, props.conflict.theirs, {
              enums: props.enums,
              labels: props.labels,
              typeLabel: props.typeLabel,
          }),
);

const noun = computed(() => (props.conflict?.kind === 'section' ? 'section' : 'question'));
const name = computed(() => shortLabel(props.conflict?.mine.label || props.conflict?.theirs.label || ''));
</script>

<template>
    <MdsModal
        :open="conflict !== null && !deferred"
        size="lg"
        title="This item changed elsewhere"
        close-label="Decide later"
        @close="emit('defer')"
    >
        <div v-if="conflict" class="conflict">
            <p class="conflict__lead">
                This {{ noun }}<template v-if="name"> (“{{ name }}”)</template> was saved somewhere else since you opened
                it. Choose which version to keep — your edit hasn't been lost.
            </p>
            <table v-if="differences.length > 0" class="conflict__table">
                <caption class="conflict__caption">What is different</caption>
                <thead>
                    <tr>
                        <th scope="col">Setting</th>
                        <th scope="col">Your version</th>
                        <th scope="col">Saved version</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in differences" :key="row.setting" data-conflict-difference>
                        <th scope="row">{{ row.setting }}</th>
                        <td>{{ row.mine }}</td>
                        <td>{{ row.theirs }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="conflict__same" data-conflict-same>
                Nothing else is different: only the time it was saved changed. Keeping yours loses nothing.
            </p>
            <p class="conflict__note">If you close this, your version stays on screen until you choose.</p>
        </div>
        <template #actions>
            <MdsButton variant="tertiary" @click="emit('resolve', 'theirs')">Use the saved version</MdsButton>
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

.conflict__lead,
.conflict__same,
.conflict__note {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.conflict__note {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
}

.conflict__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.conflict__caption {
    margin-bottom: var(--mds-space-2);
    text-align: start;
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
}

.conflict__table th,
.conflict__table td {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
    text-align: start;
    vertical-align: top;
    overflow-wrap: anywhere;
}

.conflict__table thead th {
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
}

.conflict__table tbody th {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

.conflict__table td {
    color: var(--mds-color-text-body);
}
</style>
