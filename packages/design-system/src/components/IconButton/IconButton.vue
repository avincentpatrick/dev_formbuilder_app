<script setup lang="ts">
/**
 * Icon-only button for dense action rows (table row actions, toolbars, the builder's field controls).
 * An icon has no intrinsic text, so `label` (the accessible name) is REQUIRED — it drives both
 * aria-label and the native hover title. The interactive box is >= 24px (WCAG 2.2 §2.5.8 target size)
 * at both sizes. Consumes semantic tokens only.
 */
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

withDefaults(
    defineProps<{
        icon: IconName;
        label: string;
        variant?: 'tertiary' | 'danger';
        size?: 'sm' | 'md';
        disabled?: boolean;
        loading?: boolean;
    }>(),
    { variant: 'tertiary', size: 'md', disabled: false, loading: false },
);

defineEmits<{ click: [event: MouseEvent] }>();
</script>

<template>
    <button
        type="button"
        class="mds-icon-button"
        :class="[`mds-icon-button--${variant}`, `mds-icon-button--${size}`]"
        :aria-label="label"
        :title="label"
        :aria-busy="loading || undefined"
        :disabled="disabled || loading"
        @click="$emit('click', $event)"
    >
        <Icon :name="icon" :size="size" />
    </button>
</template>

<style scoped>
.mds-icon-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-md);
    background: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
    transition:
        background-color var(--mds-duration-fast) var(--mds-ease-standard),
        color var(--mds-duration-fast) var(--mds-ease-standard);
}

.mds-icon-button--sm {
    width: 28px;
    height: 28px;
}

.mds-icon-button--md {
    width: 32px;
    height: 32px;
}

.mds-icon-button:hover:not(:disabled) {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}

.mds-icon-button--danger:hover:not(:disabled) {
    color: var(--mds-color-action-danger-bg);
}

.mds-icon-button:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

.mds-icon-button:disabled {
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}
</style>
