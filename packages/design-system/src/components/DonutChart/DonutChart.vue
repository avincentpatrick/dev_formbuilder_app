<script setup lang="ts">
/**
 * Donut chart — part of a whole, up to five slices plus a neutral *Other* (DSR §3.11, ADR-0011 §D10-§D12).
 *
 * §D10 recorded pie and donut as not built when the first chart primitives landed. This is that
 * primitive, added under the same rules: in-house SVG, static series classes, no `:style`, a real table.
 *
 * THE RING IS NOT STRETCHED. Every other chart canvas here uses `preserveAspectRatio="none"`; this one
 * keeps the default, in a fixed square box, because a stretched circle is an ellipse and an ellipse
 * misstates every angle on it.
 *
 * THE WHOLE IS THE SUM OF THE POSITIVE VALUES — of EVERY datum, plotted or not. A value of zero or below
 * draws no slice and keeps its legend row and its table row. A sixth non-neutral datum is past §D11's
 * cap: it is not painted, but it keeps its ANGLE, so the grey track shows through where it would have
 * been and every painted slice still spans its true share. Re-normalising to the five plotted slices
 * would make the ring disagree with the percentages printed beside it. The caller is the one that folds
 * extras into a `neutral` *Other* datum.
 *
 * `role="img"` IS ON THE PLOT GROUP — ring, centre text and legend together — as in `MdsBarChart`, and
 * for the same reason: the legend prints every value, so leaving it readable beside the data table
 * would announce the dataset twice. The group is one image whose alternative is the summary sentence;
 * the table carries the values and the shares.
 *
 * COLOUR IS NEVER THE ONLY CHANNEL (§D12). Each slice is outlined in the surface colour — the gap
 * between neighbours — with its own `--mds-chart-pattern-N`, matching the dash of its legend swatch;
 * and every slice is named, valued and given its share in text beside the ring.
 *
 * Shares are whole-number percentages rounded independently, so they may total 99 or 101.
 * `BarDatum.href` is ignored: a slice is not a link target, and the group is a `role="img"` leaf.
 */
import { computed } from 'vue';
import { arcPath } from '../../charts/scale';
import { MAX_CHART_SERIES, type BarDatum } from '../../charts/types';

const props = withDefaults(
    defineProps<{
        data: BarDatum[];
        title: string;
        /** Overrides the generated one-sentence description behind `role="img"`. */
        summary?: string;
        categoryLabel?: string;
        valueLabel?: string;
        /** Header of the table's percentage column. */
        shareLabel?: string;
        /** Display-ready text for the hole — typically the total. Not derived: the caller formats it. */
        centerValue?: string;
        centerLabel?: string;
        /** Renders the data table visibly instead of only to assistive tech. */
        tableVisible?: boolean;
        formatValue?: (value: number) => string;
    }>(),
    {
        summary: undefined,
        categoryLabel: 'Category',
        valueLabel: 'Value',
        shareLabel: 'Share',
        centerValue: undefined,
        centerLabel: undefined,
        tableVisible: false,
        formatValue: (value: number) => String(value),
    },
);

/** Ring geometry in viewBox units. The outer radius stops short of 50 so the outline is not clipped. */
const CENTRE = 50;
const OUTER_RADIUS = 48;
const INNER_RADIUS = 32;

/** What a datum contributes to the whole: its value when positive and finite, otherwise nothing. */
const portion = (value: number): number => (Number.isFinite(value) && value > 0 ? value : 0);

const total = computed(() => props.data.reduce((sum, datum) => sum + portion(datum.value), 0));

/**
 * One entry per datum, in data order: its slice (if any), its legend row and its table row.
 *
 * Colour slots are counted over the non-neutral data only, and a zero-valued datum still takes one —
 * colour follows the category, so a category does not change hue the day its count becomes non-zero.
 * Angles come from the RUNNING SUM rather than from accumulated angles, so the last slice ends on
 * exactly one full turn and float drift cannot leave a sliver.
 */
const entries = computed(() => {
    const whole = total.value;
    let hues = 0;
    let running = 0;

    return props.data.map((datum) => {
        const slot = datum.neutral ? 0 : hues < MAX_CHART_SERIES ? ++hues : null;
        const amount = portion(datum.value);
        const start = whole > 0 ? (running / whole) * 2 * Math.PI : 0;
        running += amount;
        const end = whole > 0 ? (running / whole) * 2 * Math.PI : 0;

        return {
            key: datum.key,
            label: datum.label,
            amount,
            value: Number.isFinite(datum.value) ? props.formatValue(datum.value) : '—',
            share: whole > 0 ? Math.round((amount / whole) * 100) : 0,
            className: slot === null ? undefined : slot === 0 ? 'mds-donut--other' : `mds-donut--s${slot}`,
            d: slot === null ? '' : arcPath(CENTRE, CENTRE, OUTER_RADIUS, INNER_RADIUS, start, end),
        };
    });
});

const slices = computed(() => entries.value.filter((entry) => entry.d !== ''));

const isEmpty = computed(() => props.data.length === 0);

const accessibleSummary = computed(() => {
    if (props.summary !== undefined) return props.summary;
    if (isEmpty.value) return `${props.title}. No data for this period.`;

    const count = entries.value.length;
    const top = entries.value.reduce((best, entry) => (entry.amount > best.amount ? entry : best));

    return (
        `${props.title}. Donut chart of ${count} ${count === 1 ? 'category' : 'categories'}, ` +
        `${props.formatValue(total.value)} in total. ` +
        `Largest: ${top.label}, ${top.value} (${top.share}%).`
    );
});
</script>

<template>
    <figure class="mds-donut">
        <div
            class="mds-donut__plot"
            :role="isEmpty ? undefined : 'img'"
            :aria-label="isEmpty ? undefined : accessibleSummary"
        >
            <div class="mds-donut__ring">
                <svg class="mds-donut__canvas" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                    <circle class="mds-donut__track" cx="50" cy="50" r="40" fill="none" stroke-width="16" />
                    <path
                        v-for="slice in slices"
                        :key="slice.key"
                        class="mds-donut__slice"
                        :class="slice.className"
                        :d="slice.d"
                        stroke-width="2"
                        stroke-linejoin="round"
                        vector-effect="non-scaling-stroke"
                    />
                </svg>

                <div v-if="centerValue || centerLabel" class="mds-donut__center">
                    <span v-if="centerValue" class="mds-donut__center-value">{{ centerValue }}</span>
                    <span v-if="centerLabel" class="mds-donut__center-label">{{ centerLabel }}</span>
                </div>
            </div>

            <ul v-if="!isEmpty" class="mds-donut__legend">
                <li v-for="entry in entries" :key="entry.key" class="mds-donut__item">
                    <svg
                        class="mds-donut__swatch"
                        :class="entry.className"
                        viewBox="0 0 24 8"
                        aria-hidden="true"
                        focusable="false"
                    >
                        <path d="M0 4 L24 4" fill="none" stroke-width="3" stroke-linecap="round" />
                    </svg>
                    <span class="mds-donut__label">{{ entry.label }}</span>
                    <span class="mds-donut__value">{{ entry.value }}</span>
                    <span class="mds-donut__share">{{ entry.share }}%</span>
                </li>
            </ul>
        </div>

        <div class="mds-donut__table-wrap" :class="{ 'mds-donut__table-wrap--sr': !tableVisible }">
            <table class="mds-donut__table">
                <caption>{{ title }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ categoryLabel }}</th>
                        <th scope="col">{{ valueLabel }}</th>
                        <th scope="col">{{ shareLabel }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.key">
                        <th scope="row">{{ entry.label }}</th>
                        <td>{{ entry.value }}</td>
                        <td>{{ entry.share }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>

<style scoped>
/*
 * Series colour is applied by a STATIC class per slot, never by interpolating a token name into a
 * `var()` — see `MdsTimeSeriesChart`, whose note on `token-references.test.ts` applies unchanged. The
 * same slot class sits on a slice and on its legend swatch, so the two cannot drift apart.
 */
/* `position: relative` is load-bearing: it is the containing block of the clipped table wrap below,
   which `clipped-node-containment.test.ts` requires to resolve inside this figure. */
.mds-donut {
    position: relative;
    margin: 0;
}

/* Ring beside the legend where there is room, above it where there is not — no breakpoint needed. */
.mds-donut__plot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: var(--mds-space-4) var(--mds-space-6);
}

/* The positioned wrapper the centre text is laid over. A fixed square: the ring must stay a circle. */
.mds-donut__ring {
    position: relative;
    flex: 0 0 auto;
    width: 200px;
    max-width: 100%;
    aspect-ratio: 1;
}

.mds-donut__canvas {
    display: block;
    width: 100%;
    height: 100%;
}

/* What an empty, all-zero or un-plotted span shows. Decorative scaffolding, like a gridline. */
.mds-donut__track {
    stroke: var(--mds-chart-grid);
}

/* Inset to the hole, so long text wraps or clips inside the ring instead of running over the slices. */
.mds-donut__center {
    position: absolute;
    inset: 22%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    text-align: center;
}

.mds-donut__center-value {
    max-width: 100%;
    font-size: var(--mds-type-heading-3-font-size);
    font-weight: var(--mds-type-heading-3-font-weight);
    line-height: var(--mds-type-heading-3-line-height);
    font-variant-numeric: tabular-nums;
    color: var(--mds-color-text-heading);
    overflow-wrap: anywhere;
}

.mds-donut__center-label {
    max-width: 100%;
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

/* The surface-coloured outline is the gap between neighbouring slices. */
.mds-donut__slice {
    stroke: var(--mds-color-bg-surface);
}

.mds-donut__slice.mds-donut--s1 {
    fill: var(--mds-chart-series-1);
    stroke-dasharray: var(--mds-chart-pattern-1);
}

.mds-donut__slice.mds-donut--s2 {
    fill: var(--mds-chart-series-2);
    stroke-dasharray: var(--mds-chart-pattern-2);
}

.mds-donut__slice.mds-donut--s3 {
    fill: var(--mds-chart-series-3);
    stroke-dasharray: var(--mds-chart-pattern-3);
}

.mds-donut__slice.mds-donut--s4 {
    fill: var(--mds-chart-series-4);
    stroke-dasharray: var(--mds-chart-pattern-4);
}

.mds-donut__slice.mds-donut--s5 {
    fill: var(--mds-chart-series-5);
    stroke-dasharray: var(--mds-chart-pattern-5);
}

/* §D11: the aggregated bucket is a NEUTRAL, never a recycled hue. */
.mds-donut__slice.mds-donut--other {
    fill: var(--mds-chart-other);
    stroke-dasharray: var(--mds-chart-pattern-1);
}

/* One row per datum on a shared four-column grid, so values and shares align down the list. */
.mds-donut__legend {
    flex: 1 1 14rem;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto auto;
    align-items: center;
    gap: var(--mds-space-2) var(--mds-space-3);
    min-width: 0;
    max-width: 24rem;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: var(--mds-type-body-sm-font-size);
}

.mds-donut__item {
    display: contents;
}

/* The same line sample `MdsChartLegend` draws. An un-plotted datum has no slot class and no stroke. */
.mds-donut__swatch {
    width: 24px;
    height: 8px;
}

.mds-donut__swatch.mds-donut--s1 path {
    stroke: var(--mds-chart-series-1);
    stroke-dasharray: var(--mds-chart-pattern-1);
}

.mds-donut__swatch.mds-donut--s2 path {
    stroke: var(--mds-chart-series-2);
    stroke-dasharray: var(--mds-chart-pattern-2);
}

.mds-donut__swatch.mds-donut--s3 path {
    stroke: var(--mds-chart-series-3);
    stroke-dasharray: var(--mds-chart-pattern-3);
}

.mds-donut__swatch.mds-donut--s4 path {
    stroke: var(--mds-chart-series-4);
    stroke-dasharray: var(--mds-chart-pattern-4);
}

.mds-donut__swatch.mds-donut--s5 path {
    stroke: var(--mds-chart-series-5);
    stroke-dasharray: var(--mds-chart-pattern-5);
}

.mds-donut__swatch.mds-donut--other path {
    stroke: var(--mds-chart-other);
    stroke-dasharray: var(--mds-chart-pattern-1);
}

.mds-donut__label {
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.mds-donut__value {
    font-variant-numeric: tabular-nums;
    text-align: end;
    color: var(--mds-color-text-body);
}

.mds-donut__share {
    font-variant-numeric: tabular-nums;
    text-align: end;
    color: var(--mds-color-text-secondary);
}

.mds-donut__table-wrap {
    margin-top: var(--mds-space-4);
    overflow-x: auto;
}

.mds-donut__table-wrap--sr {
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

.mds-donut__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.mds-donut__table caption {
    text-align: start;
    padding-bottom: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.mds-donut__table th,
.mds-donut__table td {
    padding: var(--mds-space-1) var(--mds-space-3);
    text-align: start;
    border-bottom: 1px solid var(--mds-color-border-default);
}

.mds-donut__table td {
    font-variant-numeric: tabular-nums;
}
</style>
