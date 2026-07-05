<script setup lang="ts">
/**
 * The one icon component. Renders a glyph from the shared registry (icons.ts) as inline SVG.
 *
 * A11y (the load-bearing convention — DSR Icon System appendix):
 * - Decorative by DEFAULT: aria-hidden + focusable="false". Icon-only CONTROLS put their
 *   accessible name (aria-label) on the button/link, not here — the icon stays hidden.
 * - Pass `label` only for a rare standalone meaningful icon → role="img" + aria-label.
 *
 * Color inherits `currentColor`, so an icon matches the text color of whatever it sits in
 * (nav item, button, etc.). Standalone icons should set a color (e.g. --mds-color-text-secondary).
 */
import { icons, type IconName } from './icons';

withDefaults(
    defineProps<{
        name: IconName;
        size?: 'sm' | 'md' | 'lg';
        label?: string;
    }>(),
    { size: 'md' },
);
</script>

<template>
    <svg
        class="mds-icon"
        :class="`mds-icon--${size}`"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        :role="label ? 'img' : undefined"
        :aria-label="label"
        :aria-hidden="label ? undefined : true"
        focusable="false"
    >
        <path :d="icons[name]" />
    </svg>
</template>

<style scoped>
.mds-icon {
    display: inline-block;
    flex-shrink: 0;
    vertical-align: middle;
    color: inherit;
}
.mds-icon--sm {
    width: 16px;
    height: 16px;
}
.mds-icon--md {
    width: 20px;
    height: 20px;
}
.mds-icon--lg {
    width: 24px;
    height: 24px;
}
</style>
