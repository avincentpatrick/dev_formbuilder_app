<script setup lang="ts">
/**
 * Column chart — vertical bars over a categorical x-axis, grouped or stacked, one to five series
 * (DSR §3.11, ADR-0011 §D10-§D12).
 *
 * THE FRAME IS `MdsTimeSeriesChart`'s, ON PURPOSE. HTML tick labels on both axes, a stretched
 * `viewBox="0 0 100 100"` canvas at a fixed height, non-scaling strokes, and no text inside the SVG. The
 * reasons are written there and are the same here; only the marks differ.
 *
 * THE DOMAIN ALWAYS INCLUDES ZERO, and for a bar that is not a preference: a bar's LENGTH is the
 * encoding, so a truncated axis misstates every ratio on the plot.
 *
 * GROUPED AND STACKED READ MISSING AND NEGATIVE VALUES DIFFERENTLY.
 * - `grouped`: a negative value hangs below the zero line; a `null` leaves that series' slot empty.
 * - `stacked`: segments pile upward from zero in series order, and **a negative or `null` value
 *   contributes nothing** — a stack is a sum of parts, and a negative part has no honest position in it.
 *   The table still prints the value, so it is un-plotted rather than hidden.
 *
 * THE FIVE-SERIES CAP (§D11). The first five non-neutral series take `--s1` … `--s5`. A `neutral` series
 * is the aggregated *Other* bucket: it is painted `--mds-chart-other` and does not spend one of the five.
 * A sixth real series is not plotted and IS still tabulated.
 *
 * COLOUR IS NEVER THE ONLY CHANNEL (§D12). Every bar is outlined in the surface colour — the visible gap
 * between neighbours — and that outline carries the series' own `--mds-chart-pattern-N`, the same dash
 * the legend swatch shows. The outline is a fixed pixel width whatever the plot's width, so this is a
 * chart for a dozen or so categories, not for a 90-day series; that is `MdsTimeSeriesChart`'s job.
 *
 * `title` is the accessible name and the table caption; it is not rendered as a visible heading.
 */
import { computed } from 'vue';
import ChartLegend from '../ChartLegend/ChartLegend.vue';
import { bandRects, coord, niceTicks, scaleLinear, strideLabels } from '../../charts/scale';
import { MAX_CHART_SERIES, type ChartLegendItem, type ColumnSeries } from '../../charts/types';

const props = withDefaults(
    defineProps<{
        /** Display-ready x-axis labels, one per band. */
        categories: string[];
        series: ColumnSeries[];
        title: string;
        /** Overrides the generated one-sentence description behind `role="img"`. */
        summary?: string;
        mode?: 'grouped' | 'stacked';
        categoryLabel?: string;
        valueLabel?: string;
        /** Upper bound on x-axis tick labels. Thinning is an even stride, not collision avoidance. */
        maxTicks?: number;
        /** Renders the data table visibly instead of only to assistive tech. */
        tableVisible?: boolean;
        formatTick?: (value: number) => string;
        formatValue?: (value: number) => string;
    }>(),
    {
        summary: undefined,
        mode: 'grouped',
        categoryLabel: 'Category',
        valueLabel: 'Value',
        maxTicks: 6,
        tableVisible: false,
        formatTick: (value: number) => String(value),
        formatValue: (value: number) => String(value),
    },
);

/** `null`, a missing index and a non-finite number are all "no value": no bar, an em dash in the table. */
function valueAt(series: ColumnSeries, index: number): number | null {
    const value = series.values[index];

    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/**
 * The plotted subset, each with its colour slot: 1-5 for a real series, 0 for a neutral one. Slots are
 * counted over the non-neutral series only, so an *Other* bucket listed first does not shift the hues.
 */
const plotted = computed(() => {
    const out: { item: ColumnSeries; slot: number }[] = [];
    let hues = 0;

    for (const item of props.series) {
        if (item.neutral) out.push({ item, slot: 0 });
        else if (hues < MAX_CHART_SERIES) out.push({ item, slot: ++hues });
    }

    return out;
});

const seriesClass = (slot: number): string => (slot === 0 ? 'mds-col--other' : `mds-col--s${slot}`);

const plottedValues = computed(() =>
    plotted.value.map(({ item }) => props.categories.map((_, index) => valueAt(item, index))),
);

/**
 * Grouped: the extremes of the plotted values. Stacked: zero to the tallest STACK, since the axis has to
 * hold the sum and not merely the largest part.
 */
const ticks = computed(() => {
    if (props.mode === 'stacked') {
        const totals = props.categories.map((_, index) =>
            plottedValues.value.reduce((sum, row) => sum + Math.max(0, row[index] ?? 0), 0),
        );

        return niceTicks(0, Math.max(0, ...totals), 5);
    }

    const values = plottedValues.value.flat().filter((value): value is number => value !== null);

    return niceTicks(Math.min(0, ...values), Math.max(0, ...values), 5);
});

const domainMin = computed(() => ticks.value[0]);
const domainMax = computed(() => ticks.value[ticks.value.length - 1]);

/** Highest tick first — the y-axis reads top-down. */
const axisTicks = computed(() => [...ticks.value].reverse());

const y = (value: number): number => coord(scaleLinear(value, domainMin.value, domainMax.value, 100, 0));

const gridLines = computed(() => ticks.value.map((tick) => ({ tick, y: y(tick) })));
const zeroY = computed(() => y(0));

const bars = computed(() => {
    const rects = bandRects(
        plottedValues.value,
        props.categories.length,
        domainMin.value,
        domainMax.value,
        props.mode,
    );

    return plotted.value.flatMap(({ item, slot }, s) =>
        rects[s].flatMap((rect, index) =>
            rect === null ? [] : [{ key: `${item.key}:${index}`, className: seriesClass(slot), ...rect }],
        ),
    );
});

const labelIndices = computed(() => new Set(strideLabels(props.categories.length, props.maxTicks)));

const legendItems = computed<ChartLegendItem[]>(() =>
    plotted.value.map(({ item, slot }) =>
        slot === 0
            ? { key: item.key, label: item.label, neutral: true }
            : { key: item.key, label: item.label, series: slot },
    ),
);

/** Every series, plotted or not — §D11: "nothing is hidden, only un-plotted". */
const tableRows = computed(() =>
    props.categories.map((label, index) => ({
        label,
        cells: props.series.map((item) => {
            const value = valueAt(item, index);

            return value === null ? '—' : props.formatValue(value);
        }),
    })),
);

/** Every real number supplied, across every series: the summary reports the data, not the axis. */
const allValues = computed(() =>
    props.series.flatMap((item) =>
        props.categories.map((_, index) => valueAt(item, index)).filter((value): value is number => value !== null),
    ),
);

/** No categories, or categories with not one number among them: there is no picture to label. */
const isEmpty = computed(() => allValues.value.length === 0);

const accessibleSummary = computed(() => {
    if (props.summary !== undefined) return props.summary;
    if (isEmpty.value) return `${props.title}. No data for this period.`;

    const count = props.categories.length;
    const shape = props.mode === 'stacked' ? 'Stacked column chart' : 'Column chart';
    const names =
        props.series.length > 1
            ? `, ${props.series.length} series: ${props.series.map((item) => item.label).join(', ')}`
            : '';

    return (
        `${props.title}. ${shape} of ${count} ${count === 1 ? 'category' : 'categories'}${names}. ` +
        `Values from ${props.formatValue(Math.min(...allValues.value))} to ` +
        `${props.formatValue(Math.max(...allValues.value))}.`
    );
});
</script>

<template>
    <figure class="mds-col">
        <div class="mds-col__frame">
            <div class="mds-col__y" aria-hidden="true">
                <span v-for="tick in axisTicks" :key="tick">{{ formatTick(tick) }}</span>
            </div>

            <svg
                class="mds-col__canvas"
                viewBox="0 0 100 100"
                preserveAspectRatio="none"
                :role="isEmpty ? undefined : 'img'"
                :aria-label="isEmpty ? undefined : accessibleSummary"
                :aria-hidden="isEmpty ? 'true' : undefined"
                focusable="false"
            >
                <g class="mds-col__grid">
                    <line
                        v-for="line in gridLines"
                        :key="line.tick"
                        x1="0"
                        x2="100"
                        :y1="line.y"
                        :y2="line.y"
                        vector-effect="non-scaling-stroke"
                    />
                </g>

                <rect
                    v-for="bar in bars"
                    :key="bar.key"
                    class="mds-col__bar"
                    :class="bar.className"
                    :x="bar.x"
                    :y="bar.y"
                    :width="bar.width"
                    :height="bar.height"
                    stroke-width="2"
                    vector-effect="non-scaling-stroke"
                />

                <!-- After the bars, so a bar's surface-coloured outline cannot erase the baseline. -->
                <line
                    class="mds-col__axis"
                    x1="0"
                    x2="100"
                    :y1="zeroY"
                    :y2="zeroY"
                    vector-effect="non-scaling-stroke"
                />
            </svg>

            <div class="mds-col__x" aria-hidden="true">
                <span v-for="(label, index) in categories" :key="index">
                    <span v-if="labelIndices.has(index)">{{ label }}</span>
                </span>
            </div>
        </div>

        <ChartLegend v-if="plotted.length > 1" class="mds-col__legend" :items="legendItems" :aria-label="title" />

        <div class="mds-col__table-wrap" :class="{ 'mds-col__table-wrap--sr': !tableVisible }">
            <table class="mds-col__table">
                <caption>{{ title }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ categoryLabel }}</th>
                        <th v-for="item in series" :key="item.key" scope="col">
                            {{ series.length === 1 ? valueLabel : item.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in tableRows" :key="index">
                        <th scope="row">{{ row.label }}</th>
                        <td v-for="(cell, cellIndex) in row.cells" :key="cellIndex">{{ cell }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>

<style scoped>
/*
 * Series colour is applied by a STATIC class per slot, never by interpolating a token name into a
 * `var()` — see `MdsTimeSeriesChart`, whose note on `token-references.test.ts` applies unchanged. Five
 * slots and one neutral, six rules.
 */
/* `position: relative` is load-bearing: it is the containing block of the clipped table wrap below,
   which `clipped-node-containment.test.ts` requires to resolve inside this figure. */
.mds-col {
    position: relative;
    margin: 0;
}

.mds-col__frame {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-rows: 220px auto;
    column-gap: var(--mds-space-2);
    row-gap: var(--mds-space-2);
}

/* The half-line bleed puts each tick label's CENTRE on its gridline — as in `MdsTimeSeriesChart`. */
.mds-col__y {
    grid-column: 1;
    grid-row: 1;
    align-self: start;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    align-items: flex-end;
    height: calc(100% + 1em);
    margin-block: -0.5em;
    line-height: 1;
    font-size: var(--mds-type-caption-font-size);
    font-variant-numeric: tabular-nums;
    color: var(--mds-chart-axis);
}

.mds-col__canvas {
    grid-column: 2;
    grid-row: 1;
    width: 100%;
    height: 100%;
    min-width: 0;
    overflow: visible;
}

/*
 * One cell per CATEGORY, each exactly as wide as its band, so a label is centred under its own bars
 * with no per-label positioning. Thinning empties cells rather than removing them, which is what keeps
 * the survivors under the right band. `overflow: hidden` on the row is containment at 375px and at the
 * extra-large type scale: a clipped tick label degrades gracefully, and the table has every label.
 *
 * ⚠️ `text-align: center` ALONE STOPS CENTRING THE MOMENT TEXT OVERFLOWS, which is why each cell is a
 * flex container around an inner label. A thinned label is routinely wider than its band — that is why
 * it was thinned — and overflowing inline text is laid out from the cell's START edge, so every label
 * drifted right of its bars and the last one was clipped by the row. `justify-content: center`
 * overflows equally on both sides instead, which keeps a middle label on its band's centre.
 *
 * The two END cells must not bleed out of the row, so they centre with auto margins instead: those
 * centre a label that fits and collapse to zero for one that does not, leaving it on the cell's
 * main-start edge. `row-reverse` makes that the outer edge for the last cell. No `direction` trick, so
 * it holds in a right-to-left page as well.
 */
.mds-col__x {
    grid-column: 2;
    grid-row: 2;
    display: flex;
    min-width: 0;
    overflow: hidden;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-chart-axis);
}

.mds-col__x > span {
    flex: 1 1 0;
    min-width: 0;
    display: flex;
    justify-content: center;
    text-align: center;
    white-space: nowrap;
}

.mds-col__x > span:first-child,
.mds-col__x > span:last-child {
    justify-content: flex-start;
}

.mds-col__x > span:last-child {
    flex-direction: row-reverse;
}

.mds-col__x > span:first-child > span,
.mds-col__x > span:last-child > span {
    margin-inline: auto;
}

/* Decorative scaffolding — deliberately below 1.4.11's 3:1 so it never competes with the data. */
.mds-col__grid line {
    stroke: var(--mds-chart-grid);
    stroke-width: 1;
}

.mds-col__axis {
    stroke: var(--mds-chart-axis);
    stroke-width: 1;
}

/* The surface-coloured outline is the gap between neighbouring bars and between stacked segments. */
.mds-col__bar {
    stroke: var(--mds-color-bg-surface);
}

.mds-col__bar.mds-col--s1 {
    fill: var(--mds-chart-series-1);
    stroke-dasharray: var(--mds-chart-pattern-1);
}

.mds-col__bar.mds-col--s2 {
    fill: var(--mds-chart-series-2);
    stroke-dasharray: var(--mds-chart-pattern-2);
}

.mds-col__bar.mds-col--s3 {
    fill: var(--mds-chart-series-3);
    stroke-dasharray: var(--mds-chart-pattern-3);
}

.mds-col__bar.mds-col--s4 {
    fill: var(--mds-chart-series-4);
    stroke-dasharray: var(--mds-chart-pattern-4);
}

.mds-col__bar.mds-col--s5 {
    fill: var(--mds-chart-series-5);
    stroke-dasharray: var(--mds-chart-pattern-5);
}

/* §D11: the aggregated bucket is a NEUTRAL, never a recycled hue. Solid outline, as its legend swatch. */
.mds-col__bar.mds-col--other {
    fill: var(--mds-chart-other);
    stroke-dasharray: var(--mds-chart-pattern-1);
}

.mds-col__legend {
    margin-top: var(--mds-space-4);
}

.mds-col__table-wrap {
    margin-top: var(--mds-space-4);
    overflow-x: auto;
}

.mds-col__table-wrap--sr {
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

.mds-col__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.mds-col__table caption {
    text-align: start;
    padding-bottom: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.mds-col__table th,
.mds-col__table td {
    padding: var(--mds-space-1) var(--mds-space-3);
    text-align: start;
    border-bottom: 1px solid var(--mds-color-border-default);
    white-space: nowrap;
}

.mds-col__table td {
    font-variant-numeric: tabular-nums;
}
</style>
