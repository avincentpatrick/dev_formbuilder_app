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
/* ⚠️ `position: relative` IS LOAD-BEARING. The visually-hidden node below is `position: absolute` +
   `clip: rect(0 0 0 0)`, and absolute positioning resolves against the nearest POSITIONED ancestor —
   so without this line its containing block is whatever happens to be positioned further up, no
   scroll container in between can clip it, and a 1px hidden node parked past a viewport edge extends
   the DOCUMENT's scrollable box. G11 found it on `MdsDataTable`, JR5 on `MdsSegmentedControl`, and
   J3b found it still latent HERE while building `MdsPasswordStrength` against the same hazard.
   Nothing in this repository can execute the check (happy-dom lays nothing out; the e2e assertion
   reads a `scrollWidth` that `.app-shell { overflow-x: clip }` pins flat; axe has no rule), which is
   why it stayed latent through four increments and why the guard is a source-text test. */
.mds-spinner {
    position: relative;
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
