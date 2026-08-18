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

   ⚠️ THE FIELD IS A 10% MIX OF THE ACCENT, NOT `action-primary-tint`, AND THE FIRST VERSION WAS
   INVISIBLE. `tint` is `{primary.50}` `#F2F7FF` in light, which measures **1.08:1 against the card
   surface and 1.00:1 against the canvas** — and the empty state that matters most, the forms list's,
   renders through `MdsDataTable`'s `#empty` slot, where `.mds-table__empty` has `padding: 0` and the
   table paints no background, so it sits directly on the canvas. A "tinted medallion" at 1.00:1 is
   not a subtle medallion, it is no medallion; the only thing that had actually changed was the glyph
   colour. Mixing the accent at 10% gives **1.14:1** on both grounds — which is also where the
   approved direction's own `--m-accent-soft` `#E6F0FE` sits, so this matches the mockup rather than
   inventing a value.
   Dark did not have the problem (`tint` is redeclared there as `rgba(88,155,253,0.18)`, compositing
   to ≈`#253755`), but the mix works on both grounds and needs no theme branch, which is the second
   reason to prefer it over a token that behaves differently per theme.
   Degradation is benign, unlike the stat value's: an engine without `color-mix` drops the
   declaration, `background-color` stays at its initial `transparent`, and the glyph is still an
   accent-coloured 64px drawing — i.e. the pre-JR2 rendering with a better colour. Nothing vanishes,
   so no `@supports` guard is needed here. Compare StatTile, where the same reasoning does NOT hold.

   `lg`, not `xl`: §2.6 puts "the tinted icon badges" on the compact-surface tier, and this is the
   same species as `MdsStatTile`'s 44px chip. The first version used `xl`, which is the page-level
   CARD tier — a rule this increment wrote and then broke two files later.

   CSS-only, deliberately: no wrapper element, so `.mds-empty__desc` and the rest of the markup stay
   exactly where every consumer and `dashboard.test.ts:467` expect them. Padding on an inline <svg>
   insets the viewBox, so `box-sizing: border-box` + 96px − 2×16px keeps the glyph at its original
   64px and grows only the field around it. */
.mds-empty__art {
    box-sizing: border-box;
    width: 96px;
    height: 96px;
    padding: var(--mds-space-4);
    border-radius: var(--mds-radius-lg);
    background-color: color-mix(in srgb, var(--mds-color-action-primary-bg) 10%, transparent);
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
