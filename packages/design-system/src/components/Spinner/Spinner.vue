<script setup lang="ts">
/**
 * Indeterminate loading spinner (DSR §3.9) — for short, unmeasurable waits only (a measurable
 * operation uses the determinate progress bar, not this). `role="status"` + a visually-hidden
 * label announces the loading state to assistive tech.
 */
withDefaults(
    defineProps<{
        size?: 'sm' | 'md' | 'lg';
        label?: string;
    }>(),
    { size: 'md', label: 'Loading' },
);
</script>

<template>
    <span class="mds-spinner" :class="`mds-spinner--${size}`" role="status">
        <span class="mds-spinner__ring" aria-hidden="true" />
        <span class="mds-spinner__label">{{ label }}</span>
    </span>
</template>

<style scoped>
.mds-spinner {
    display: inline-flex;
    align-items: center;
}

.mds-spinner__ring {
    display: inline-block;
    border: 2px solid var(--mds-color-border-default);
    border-top-color: var(--mds-color-action-primary-bg);
    border-radius: var(--mds-radius-full);
    animation: mds-spin 700ms linear infinite;
}

.mds-spinner--sm .mds-spinner__ring {
    width: 16px;
    height: 16px;
}
.mds-spinner--md .mds-spinner__ring {
    width: 24px;
    height: 24px;
}
.mds-spinner--lg .mds-spinner__ring {
    width: 32px;
    height: 32px;
    border-width: 3px;
}

.mds-spinner__label {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}

@keyframes mds-spin {
    to {
        transform: rotate(360deg);
    }
}

/* A loading spinner is a functional indicator, so it keeps moving under reduced-motion —
   just slower and gentler. */
@media (prefers-reduced-motion: reduce) {
    .mds-spinner__ring {
        animation-duration: 1500ms;
    }
}
</style>
