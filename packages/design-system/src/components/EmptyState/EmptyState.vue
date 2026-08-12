<script setup lang="ts">
/**
 * Empty state (DSR §3.10): line-art illustration, one headline, one line of secondary copy, and
 * AT MOST ONE primary CTA (the `action` slot). Use the copy itself to distinguish a first-run
 * empty ("Create your first form") from a permission-restricted or filtered-to-zero empty.
 */
withDefaults(
    defineProps<{
        headline: string;
        description?: string;
        illustration?: 'default' | 'search' | 'lock';
    }>(),
    { illustration: 'default' },
);
</script>

<template>
    <div class="mds-empty">
        <svg
            class="mds-empty__art"
            viewBox="0 0 64 64"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
            focusable="false"
        >
            <template v-if="illustration === 'search'">
                <circle cx="28" cy="28" r="14" />
                <path d="M38 38l10 10" />
            </template>
            <template v-else-if="illustration === 'lock'">
                <rect x="18" y="28" width="28" height="22" rx="2" />
                <path d="M24 28v-6a8 8 0 0 1 16 0v6" />
            </template>
            <template v-else>
                <rect x="16" y="10" width="28" height="44" rx="2" />
                <path d="M24 24h16 M24 32h16 M24 40h10" />
            </template>
        </svg>

        <h3 class="mds-empty__headline">{{ headline }}</h3>
        <p v-if="description" class="mds-empty__desc">{{ description }}</p>
        <div v-if="$slots.action" class="mds-empty__action"><slot name="action" /></div>
    </div>
</template>

<style scoped>
.mds-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--mds-space-3);
    padding: var(--mds-space-10) var(--mds-space-6);
}

/* JR2 — the line art moves into a tinted medallion. Neither approved mockup shows an empty state, so
   this is a derivation rather than a transcription; what it derives from is the direction's own rule
   that a tinted field plus an accent glyph is how a non-text element carries the brand (the same
   construction as `MdsStatTile`'s icon chip and the sidebar's active item).
   The measured reason it is worth doing: the diagnosis behind this whole re-skin names "empty states
   are plain text, no illustration" as one of the thirty compounding causes, and a grey stroke on a
   white ground is the plainest thing on any page that has one.

   `action-primary-tint` is the load-bearing token choice. It is the ONE accent fill that is
   re-declared for dark as `rgba(88,155,253,0.18)` (theme-overrides.css:84,145) rather than riding the
   primary ramp — reach for `primary-50` instead and dark mode gets a near-white slab.

   CSS-only, deliberately: no wrapper element, so `.mds-empty__desc` and the rest of the markup stay
   exactly where every consumer and `dashboard.test.ts:467` expect them. Padding on an inline <svg>
   insets the viewBox, so `box-sizing: border-box` + 96px − 2×16px keeps the glyph at its original
   64px and grows only the field around it. */
.mds-empty__art {
    box-sizing: border-box;
    width: 96px;
    height: 96px;
    padding: var(--mds-space-4);
    border-radius: var(--mds-radius-xl);
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-action-primary-fg);
    margin-bottom: var(--mds-space-1);
}

.mds-empty__headline {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.mds-empty__desc {
    max-width: 42ch;
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}

.mds-empty__action {
    margin-top: var(--mds-space-2);
}
</style>
