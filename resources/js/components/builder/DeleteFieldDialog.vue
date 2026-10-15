<script setup lang="ts">
/**
 * M172 (`R-a44638b6`, `D133`) — deleting a question that responses have answered. The user's design (2026-10-15): the
 * delete works in the draft; with no responses nothing more is said; with responses the author is told how many hold
 * answers, and that they keep them — marked "removed from the form in version N" on the response page and in exports,
 * with every older version still viewable from the form's Versions panel.
 *
 * `count` null: the number could not be read. The author is still told what happens to any answers, rather than the
 * delete going through unexplained.
 */
import { MdsButton, MdsModal } from '@meridian/design-system';

const props = defineProps<{ open: boolean; label: string; count: number | null }>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();

function lead(): string {
    if (props.count === null) return 'Responses this form already holds may have answered this question.';
    return props.count === 1 ? '1 response holds an answer to this question.' : `${props.count} responses hold answers to this question.`;
}
</script>

<template>
    <MdsModal :open="open" :title="`Delete “${label}”?`" close-label="Cancel" @close="emit('cancel')">
        <div class="delete-field">
            <p class="delete-field__lead">{{ lead() }}</p>
            <p class="delete-field__note">
                They keep them, marked as removed from the form. The question is removed from the draft only — every version
                already published stays as it is, and you can open it from the form's Versions panel.
            </p>
        </div>
        <template #actions>
            <MdsButton variant="tertiary" @click="emit('cancel')">Cancel</MdsButton>
            <MdsButton variant="destructive" @click="emit('confirm')">Delete question</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.delete-field {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.delete-field__lead {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.delete-field__note {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-md-line-height);
}
</style>
