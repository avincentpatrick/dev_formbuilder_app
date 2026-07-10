<script setup lang="ts">
/**
 * The one-time, dismissible note shown where a field used to be when it becomes irrelevant while already
 * holding a respondent-entered value (UX §4.1). The value itself is retained in the store and restored if
 * relevance returns — this note only explains, non-blockingly, that the answer won't be submitted.
 */
defineProps<{ label: string }>();
defineEmits<{ dismiss: [] }>();
</script>

<template>
    <div class="relevance-note" role="note">
        <span class="relevance-note__text">
            Your answer to “{{ label }}” won’t be included because it’s no longer relevant.
        </span>
        <button type="button" class="relevance-note__dismiss" aria-label="Dismiss" @click="$emit('dismiss')">
            <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false" width="14" height="14">
                <path d="M4 4l8 8 M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
.relevance-note {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2) var(--mds-space-3);
    border-left: 3px solid var(--mds-color-border-strong);
    background-color: var(--mds-color-bg-sunken);
    border-radius: var(--mds-radius-sm);
}

.relevance-note__text {
    flex: 1;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.relevance-note__dismiss {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}

.relevance-note__dismiss:hover {
    color: var(--mds-color-text-body);
    background-color: var(--mds-color-bg-canvas);
}

.relevance-note__dismiss:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}
</style>
