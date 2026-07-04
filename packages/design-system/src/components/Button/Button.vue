<script setup lang="ts">
/**
 * The one Button. Every screen uses this — never a page-local button style.
 * Consumes SEMANTIC tokens only (never primitives), so it re-themes for free
 * under data-theme-mode / data-accent.
 */
withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'destructive';
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
    }>(),
    { variant: 'primary', type: 'button', disabled: false },
);
</script>

<template>
    <button
        class="mds-button"
        :class="`mds-button--${variant}`"
        :type="type"
        :disabled="disabled"
    >
        <slot />
    </button>
</template>

<style scoped>
.mds-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0 1rem;
    border: 1px solid transparent;
    border-radius: 3px;
    font: inherit;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    transition: background-color 120ms ease, border-color 120ms ease;
}

/* Never remove the focus indicator — accessibility is a build-time constraint (WCAG 2.2 AA). */
.mds-button:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.mds-button--primary {
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-action-primary-text);
}

.mds-button--primary:hover:not(:disabled) {
    background-color: var(--mds-color-action-primary-bg-hover);
}

.mds-button--secondary {
    background-color: transparent;
    color: var(--mds-color-action-primary-bg);
    border-color: var(--mds-color-action-primary-bg);
}

.mds-button--secondary:hover:not(:disabled) {
    background-color: var(--mds-color-bg-canvas);
}

.mds-button--destructive {
    background-color: var(--mds-color-danger-bg);
    color: var(--mds-color-danger-text);
}

.mds-button--destructive:hover:not(:disabled) {
    background-color: var(--mds-color-danger-bg-hover);
}
</style>
