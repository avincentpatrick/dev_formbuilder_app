<script setup lang="ts">
/**
 * Loading skeleton (DSR §3.9). A layout-preserving placeholder that communicates *approximately what
 * is coming* and prevents layout shift once real data arrives (unlike a spinner). Purely decorative
 * (`aria-hidden`) — the container that renders skeletons owns the single "Loading" cue for assistive
 * tech (e.g. DataTable's `aria-busy` region). Consumes semantic tokens only; the shimmer is disabled
 * under reduced-motion (§2.8), leaving a static block.
 */
withDefaults(
    defineProps<{
        variant?: 'text' | 'block' | 'circle';
        width?: string;
        height?: string;
    }>(),
    { variant: 'text' },
);
</script>

<template>
    <span
        class="mds-skeleton"
        :class="`mds-skeleton--${variant}`"
        :style="{ width, height }"
        aria-hidden="true"
    />
</template>

<style scoped>
.mds-skeleton {
    display: block;
    background-color: var(--mds-color-bg-sunken);
    background-image: linear-gradient(
        90deg,
        var(--mds-color-bg-sunken) 25%,
        var(--mds-color-border-default) 37%,
        var(--mds-color-bg-sunken) 63%
    );
    background-size: 400% 100%;
    animation: mds-skeleton-shimmer 1400ms ease infinite;
}

.mds-skeleton--text {
    width: 100%;
    height: 0.85em;
    border-radius: var(--mds-radius-sm);
}

.mds-skeleton--block {
    width: 100%;
    height: var(--mds-space-10);
    border-radius: var(--mds-radius-md);
}

.mds-skeleton--circle {
    width: var(--mds-space-10);
    height: var(--mds-space-10);
    border-radius: var(--mds-radius-full);
}

@keyframes mds-skeleton-shimmer {
    0% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0 50%;
    }
}

/* Structural placeholder, not a functional indicator — so it goes fully static under reduced motion. */
@media (prefers-reduced-motion: reduce) {
    .mds-skeleton {
        background-image: none;
        animation: none;
    }
}
</style>
