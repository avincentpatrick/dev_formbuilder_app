<script setup lang="ts">
/**
 * Horizontal categorical bar chart (DSR §3.11, ADR-0011 §D10-§D12).
 *
 * SINGLE SERIES BY CONSTRUCTION, AND THAT IS WHY IT NEEDS NO PALETTE. §D12: "a single-series chart uses
 * no categorical colour at all — its categories are distinguished by their axis labels." So every bar
 * takes `--mds-chart-series-1`, and the five-token cap is never strained here. The one exception is a
 * `neutral` datum, painted `--mds-chart-other`, which is how §D11's aggregated *Other (N)* bucket
 * renders without ever reading as a peer category.
 *
 * EACH ROW IS AN HTML LABEL BESIDE A ONE-RECT SVG, NOT AN SVG `<text>`. SVG text has no wrapping and no
 * ellipsis, so a long form name at 375px would run off the plot — which is the non-wrapping-row failure
 * `responsive-axe` records as having reddened that gate three times (H12b, H14, H15b). HTML labels wrap
 * and truncate for free, and the mark stays SVG, which is where 1.4.11 applies.
 *
 * Deliberately NOT built (§D10, so a later increment does not assume otherwise): stacked bars, grouped
 * bars, negative values, and a value axis. A bar's number is printed beside it instead.
 */
import { computed } from 'vue';
import { coord } from '../../charts/scale';
import type { BarDatum } from '../../charts/types';

const props = withDefaults(
    defineProps<{
        data: BarDatum[];
        title: string;
        /** Overrides the generated one-sentence description behind `role="img"`. */
        summary?: string;
        categoryLabel?: string;
        valueLabel?: string;
        /** Renders the data table visibly instead of only to assistive tech. */
        tableVisible?: boolean;
        formatValue?: (value: number) => string;
    }>(),
    {
        summary: undefined,
        categoryLabel: 'Category',
        valueLabel: 'Count',
        tableVisible: false,
        formatValue: (value: number) => String(value),
    },
);

/**
 * The widest bar is the scale, floored at 1 so an all-zero set does not divide by zero and render
 * `width="NaN"` — which draws nothing, throws nothing, and fails no gate. An all-zero breakdown is the
 * ordinary state of a quiet tenant, not an exotic one.
 */
const scaleMax = computed(() => Math.max(1, ...props.data.map((d) => Math.max(0, d.value))));

const bars = computed(() =>
    props.data.map((datum) => ({
        ...datum,
        // Percent of the track, so the rect needs no knowledge of the rendered pixel width.
        width: coord((Math.max(0, datum.value) / scaleMax.value) * 100),
    })),
);

const accessibleSummary = computed(() => {
    if (props.summary !== undefined) return props.summary;
    if (props.data.length === 0) return `${props.title}. No data for this period.`;

    const total = props.data.reduce((sum, d) => sum + d.value, 0);
    const top = [...props.data].sort((a, b) => b.value - a.value)[0];

    return (
        `${props.title}. Bar chart of ${props.data.length} ` +
        `${props.data.length === 1 ? 'category' : 'categories'}, ${total} in total. ` +
        `Largest: ${top.label}, ${top.value}.`
    );
});

const isEmpty = computed(() => props.data.length === 0);
</script>

<template>
    <figure class="mds-bar">
        <div
            class="mds-bar__plot"
            :role="isEmpty ? undefined : 'img'"
            :aria-label="isEmpty ? undefined : accessibleSummary"
        >
            <div v-for="bar in bars" :key="bar.key" class="mds-bar__row">
                <span class="mds-bar__label">{{ bar.label }}</span>
                <svg
                    class="mds-bar__track"
                    viewBox="0 0 100 10"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                    focusable="false"
                >
                    <rect
                        class="mds-bar__fill"
                        :class="bar.neutral ? 'mds-bar__fill--other' : 'mds-bar__fill--series'"
                        x="0"
                        y="0"
                        height="10"
                        :width="bar.width"
                    />
                </svg>
                <span class="mds-bar__value">{{ formatValue(bar.value) }}</span>
            </div>
        </div>

        <div class="mds-bar__table-wrap" :class="{ 'mds-bar__table-wrap--sr': !tableVisible }">
            <table class="mds-bar__table">
                <caption>{{ title }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ categoryLabel }}</th>
                        <th scope="col">{{ valueLabel }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="datum in data" :key="datum.key">
                        <th scope="row">{{ datum.label }}</th>
                        <td>{{ datum.value }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>

<style scoped>
/*
 * `role="img"` sits on the ROW CONTAINER, not on each `<svg>`: the visible category labels and values
 * are already text, so the group is one image whose alternative is the summary sentence, and the tracks
 * themselves are decorative. Putting the role on each track would announce a dozen unlabelled images.
 */
.mds-bar {
    margin: 0;
}

.mds-bar__plot {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.mds-bar__row {
    display: grid;
    grid-template-columns: minmax(6rem, 12rem) minmax(0, 1fr) auto;
    align-items: center;
    gap: var(--mds-space-3);
}

.mds-bar__label {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.mds-bar__track {
    width: 100%;
    height: 12px;
    min-width: 0;
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
}

.mds-bar__fill--series {
    fill: var(--mds-chart-series-1);
}

/* §D11: the aggregated bucket is a NEUTRAL, never a recycled hue, so it cannot read as a peer. */
.mds-bar__fill--other {
    fill: var(--mds-chart-other);
}

.mds-bar__value {
    font-size: var(--mds-type-body-sm-font-size);
    font-variant-numeric: tabular-nums;
    color: var(--mds-color-text-secondary);
}

.mds-bar__table-wrap {
    margin-top: var(--mds-space-4);
    overflow-x: auto;
}

.mds-bar__table-wrap--sr {
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

.mds-bar__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.mds-bar__table caption {
    text-align: start;
    padding-bottom: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.mds-bar__table th,
.mds-bar__table td {
    padding: var(--mds-space-1) var(--mds-space-3);
    text-align: start;
    border-bottom: 1px solid var(--mds-color-border-default);
}

.mds-bar__table td {
    font-variant-numeric: tabular-nums;
}

@media (max-width: 480px) {
    /* At mobile the label takes its own line so a long form title never squeezes the track to nothing. */
    .mds-bar__row {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .mds-bar__label {
        grid-column: 1 / -1;
    }
}
</style>
