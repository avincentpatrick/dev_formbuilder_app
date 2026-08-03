<script setup lang="ts">
/**
 * Chart legend (DSR §3.11, ADR-0011 §D10-§D12).
 *
 * Shared across the chart primitives, and it carries the NON-COLOUR channel key as well as the colour
 * one: each swatch is a line sample drawn with the series' own stroke AND its own dash pattern, so a
 * reader who cannot separate the hues can still match "the dotted one" to its label. §D12 is explicit
 * that colour is never the only channel; a legend that showed only colour swatches would be the place
 * that quietly broke it.
 *
 * The list is a real `<ul>` with text labels — no `role="img"`, no `aria-hidden`. The swatches are
 * decorative; the words are the content.
 */
import type { ChartLegendItem } from '../../charts/types';

withDefaults(
    defineProps<{
        items: ChartLegendItem[];
        /** Labels the list for assistive tech when the legend is not adjacent to its chart's title. */
        ariaLabel?: string;
    }>(),
    { ariaLabel: undefined },
);

/** Series index → a static class. Never a `var(--mds-chart-series-${n})` template — see the note below. */
function swatchClass(item: ChartLegendItem): string {
    if (item.neutral) return 'mds-chart-legend__swatch--other';

    return `mds-chart-legend__swatch--${Math.min(Math.max(item.series ?? 1, 1), 5)}`;
}
</script>

<template>
    <ul class="mds-chart-legend" :aria-label="ariaLabel">
        <li v-for="item in items" :key="item.key" class="mds-chart-legend__item">
            <svg
                class="mds-chart-legend__swatch"
                :class="swatchClass(item)"
                viewBox="0 0 24 8"
                aria-hidden="true"
                focusable="false"
            >
                <path d="M0 4 L24 4" fill="none" stroke-width="3" stroke-linecap="round" />
            </svg>
            <span class="mds-chart-legend__label">{{ item.label }}</span>
        </li>
    </ul>
</template>

<style scoped>
/*
 * Series colour is applied by a STATIC class per index, never by interpolating a token name into a
 * `var()` at runtime. Two reasons, both mechanical: `var(--mds-chart-series-${n})` in a template makes
 * `token-references.test.ts` hunt for a token literally named `--mds-chart-series-`, which does not
 * exist and fails the suite; and a computed token name is a step toward the runtime colour resolution
 * ADR-0011 §D10 rules out. Five indices, five rules.
 */
.mds-chart-legend {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2) var(--mds-space-4);
    margin: 0;
    padding: 0;
    list-style: none;
}

.mds-chart-legend__item {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.mds-chart-legend__swatch {
    width: 24px;
    height: 8px;
    flex-shrink: 0;
}

.mds-chart-legend__swatch--1 path {
    stroke: var(--mds-chart-series-1);
    stroke-dasharray: var(--mds-chart-pattern-1);
}

.mds-chart-legend__swatch--2 path {
    stroke: var(--mds-chart-series-2);
    stroke-dasharray: var(--mds-chart-pattern-2);
}

.mds-chart-legend__swatch--3 path {
    stroke: var(--mds-chart-series-3);
    stroke-dasharray: var(--mds-chart-pattern-3);
}

.mds-chart-legend__swatch--4 path {
    stroke: var(--mds-chart-series-4);
    stroke-dasharray: var(--mds-chart-pattern-4);
}

.mds-chart-legend__swatch--5 path {
    stroke: var(--mds-chart-series-5);
    stroke-dasharray: var(--mds-chart-pattern-5);
}

.mds-chart-legend__swatch--other path {
    stroke: var(--mds-chart-other);
    stroke-dasharray: var(--mds-chart-pattern-1);
}
</style>
