<script setup lang="ts">
/**
 * Ambient "your progress is saved" indicator (UX §5.1). Intentionally quiet — no toast, no live region that
 * would announce on every autosave tick; it just reflects the client-only localStorage autosave state.
 */
import { computed } from 'vue';

const props = defineProps<{ saving: boolean; savedAt: string | null }>();

const label = computed(() => {
    if (props.saving) {
        return 'Saving…';
    }
    return props.savedAt !== null ? 'Saved' : '';
});
</script>

<template>
    <span v-if="label" class="saved-indicator">
        <span class="saved-indicator__dot" :class="{ 'saved-indicator__dot--saving': props.saving }" aria-hidden="true" />
        {{ label }}
    </span>
</template>

<style scoped>
.saved-indicator {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.saved-indicator__dot {
    width: 8px;
    height: 8px;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-text-secondary);
}

.saved-indicator__dot--saving {
    background-color: var(--mds-color-text-secondary);
}
</style>
