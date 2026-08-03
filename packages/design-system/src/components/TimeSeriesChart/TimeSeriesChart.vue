<script setup lang="ts">
/**
 * Time-series chart — line or area, one to five series (DSR §3.11, ADR-0011 §D10-§D12).
 *
 * WHY THIS IS SVG AND NOT A LIBRARY. `build-tokens.mjs` emits `var()` REFERENCES rather than resolved
 * colours, and `useTheme` flips `data-theme-mode` / `data-accent` on `<html>` live, with no reload and
 * no re-render of this component. An SVG mark whose stroke is `var(--mds-chart-series-1)` re-paints for
 * free; a canvas renderer cannot resolve a custom property at paint time and goes stale until someone
 * wires a MutationObserver. That is the argument, not aesthetics.
 *
 * THE TABLE IS THE POINT, NOT A COURTESY. §D12: axe cannot detect a MISSING text alternative for an SVG
 * that carries a plausible `aria-label`, so the merge-blocking accessibility job will pass a chart that
 * is a beautiful, unreadable picture. Every instance therefore renders a real `<table>` of the plotted
 * data, and that contract is pinned by this component's Vitest suite rather than by the axe gate.
 *
 * THE DOMAIN ALWAYS INCLUDES ZERO. A truncated value axis exaggerates every wiggle into a cliff; for
 * response counts that is a lie the chart tells on its own initiative. Callers cannot switch it off.
 *
 * `title` is the ACCESSIBLE name and the table caption; it is not rendered as a visible heading, because
 * every current caller already sits inside a card that carries one.
 */
import { computed } from 'vue';
import ChartLegend from '../ChartLegend/ChartLegend.vue';
import { areaPath, bandPositions, linePath, niceTicks, scaleLinear, strideLabels } from '../../charts/scale';
import { MAX_CHART_SERIES, type ChartLegendItem, type ChartSeries } from '../../charts/types';

const props = withDefaults(
    defineProps<{
        series: ChartSeries[];
        title: string;
        /** Overrides the generated one-sentence description behind `role="img"`. */
        summary?: string;
        variant?: 'line' | 'area';
        categoryLabel?: string;
        valueLabel?: string;
        /** Upper bound on x-axis tick labels. Thinning is an even stride, not collision avoidance. */
        maxTicks?: number;
        /** Renders the data table visibly instead of only to assistive tech. */
        tableVisible?: boolean;
        formatTick?: (value: number) => string;
    }>(),
    {
        summary: undefined,
        variant: 'line',
        categoryLabel: 'Period',
        valueLabel: 'Value',
        maxTicks: 6,
        tableVisible: false,
        formatTick: (value: number) => String(value),
    },
);

/**
 * Past five series the extras are still listed in the table and still counted — they are simply not
 * plotted, which is §D11's rule ("nothing is hidden, only un-plotted"). Silently dropping them from
 * both would be the failure that rule exists to prevent.
 */
const plotted = computed(() => props.series.slice(0, MAX_CHART_SERIES));

/** The category axis is the longest series' labels; a shorter series leaves blank table cells. */
const axisLabels = computed<string[]>(() => {
    let longest: string[] = [];
    for (const series of props.series) {
        if (series.points.length > longest.length) longest = series.points.map((p) => p.label);
    }

    return longest;
});

const ticks = computed(() => {
    const values = props.series.flatMap((s) => s.points.map((p) => p.value));

    return niceTicks(Math.min(0, ...values), Math.max(0, ...values), 5);
});

const domainMin = computed(() => ticks.value[0]);
const domainMax = computed(() => ticks.value[ticks.value.length - 1]);

/** Highest tick first — the y-axis reads top-down. */
const axisTicks = computed(() => [...ticks.value].reverse());

const y = (value: number): number => scaleLinear(value, domainMin.value, domainMax.value, 100, 0);

const zeroY = computed(() => y(0));

const gridLines = computed(() => ticks.value.map((tick) => ({ tick, y: y(tick) })));

function pointsFor(series: ChartSeries): { x: number; y: number }[] {
    const xs = bandPositions(series.points.length, 0, 100);

    return series.points.map((point, i) => ({ x: xs[i], y: y(point.value) }));
}

/**
 * A single-point series draws a zero-length subpath, which `stroke-linecap="round"` renders as a dot.
 * Drawing a full-width horizontal line instead would claim a constant across a range with one bucket
 * in it. A `<circle>` is not an option: the canvas is stretched with `preserveAspectRatio="none"`, so
 * a circle would render as an ellipse.
 */
const linesFor = (series: ChartSeries): string => linePath(pointsFor(series));
const areasFor = (series: ChartSeries): string => areaPath(pointsFor(series), zeroY.value);

const seriesClass = (index: number): string => `mds-tsc--s${index + 1}`;

const labelIndices = computed(() => strideLabels(axisLabels.value.length, props.maxTicks));

const legendItems = computed<ChartLegendItem[]>(() =>
    plotted.value.map((series, i) => ({ key: series.key, label: series.label, series: i + 1 })),
);

const accessibleSummary = computed(() => {
    if (props.summary !== undefined) return props.summary;

    const labels = axisLabels.value;
    if (labels.length === 0) return `${props.title}. No data for this period.`;

    const values = props.series.flatMap((s) => s.points.map((p) => p.value));
    const shape = props.variant === 'area' ? 'Area chart' : 'Line chart';
    const span = labels.length === 1 ? labels[0] : `${labels[0]} to ${labels[labels.length - 1]}`;
    const names =
        props.series.length > 1 ? ` ${props.series.length} series: ${props.series.map((s) => s.label).join(', ')}.` : '';

    return (
        `${props.title}. ${shape}, ${labels.length} ${labels.length === 1 ? 'point' : 'points'} from ${span}.` +
        `${names} Values from ${Math.min(...values)} to ${Math.max(...values)}.`
    );
});

const isEmpty = computed(() => axisLabels.value.length === 0);
</script>

<template>
    <figure class="mds-tsc">
        <div class="mds-tsc__frame">
            <div class="mds-tsc__y" aria-hidden="true">
                <span v-for="tick in axisTicks" :key="tick">{{ formatTick(tick) }}</span>
            </div>

            <svg
                class="mds-tsc__canvas"
                viewBox="0 0 100 100"
                preserveAspectRatio="none"
                :role="isEmpty ? undefined : 'img'"
                :aria-label="isEmpty ? undefined : accessibleSummary"
                :aria-hidden="isEmpty ? 'true' : undefined"
                focusable="false"
            >
                <g class="mds-tsc__grid">
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

                <line
                    class="mds-tsc__axis"
                    x1="0"
                    x2="100"
                    :y1="zeroY"
                    :y2="zeroY"
                    vector-effect="non-scaling-stroke"
                />

                <template v-for="(item, index) in plotted" :key="item.key">
                    <path
                        v-if="variant === 'area'"
                        class="mds-tsc__area"
                        :class="seriesClass(index)"
                        :d="areasFor(item)"
                        fill-opacity="0.15"
                        stroke="none"
                    />
                    <path
                        class="mds-tsc__line"
                        :class="seriesClass(index)"
                        :d="linesFor(item)"
                        fill="none"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        vector-effect="non-scaling-stroke"
                    />
                </template>
            </svg>

            <div class="mds-tsc__x" aria-hidden="true">
                <span v-for="index in labelIndices" :key="index">{{ axisLabels[index] }}</span>
            </div>
        </div>

        <ChartLegend v-if="plotted.length > 1" class="mds-tsc__legend" :items="legendItems" :aria-label="title" />

        <div class="mds-tsc__table-wrap" :class="{ 'mds-tsc__table-wrap--sr': !tableVisible }">
            <table class="mds-tsc__table">
                <caption>{{ title }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ categoryLabel }}</th>
                        <th v-for="item in series" :key="item.key" scope="col">
                            {{ series.length > 1 ? item.label : valueLabel }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(label, index) in axisLabels" :key="index">
                        <th scope="row">{{ label }}</th>
                        <td v-for="item in series" :key="item.key">
                            {{ item.points[index] ? item.points[index].value : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>

<style scoped>
/*
 * Series colour is applied by a STATIC class per index, never by interpolating a token name into a
 * `var()`: `var(--mds-chart-series-${n})` in a template makes `token-references.test.ts` hunt for a
 * token literally named `--mds-chart-series-`, and a computed token name is the first step toward the
 * runtime colour resolution §D10 rules out. Five indices, five pairs of rules.
 *
 * The canvas is stretched with `preserveAspectRatio="none"` and every stroked mark carries
 * `vector-effect="non-scaling-stroke"`, so the plot fills any width at a fixed height with strokes that
 * stay 1-2 CSS pixels rather than being scaled into wedges. That is also why no text lives inside the
 * SVG: it would stretch with the box. The axis labels are HTML, aligned by the grid below.
 */
.mds-tsc {
    margin: 0;
}

.mds-tsc__frame {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-rows: 220px auto;
    column-gap: var(--mds-space-2);
    row-gap: var(--mds-space-2);
}

/*
 * The half-line-height bleed is what puts each tick label's CENTRE on its gridline. `space-between`
 * aligns box EDGES, so without it the top label sits half a line below its line and the bottom one half
 * a line above — the two errors point in opposite directions, which reads as a skewed axis.
 */
.mds-tsc__y {
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

.mds-tsc__canvas {
    grid-column: 2;
    grid-row: 1;
    width: 100%;
    height: 100%;
    min-width: 0;
    overflow: visible;
}

/*
 * `overflow: hidden` is containment, not tidiness. Six tick labels fit comfortably at 375px at the
 * default type scale — but `[data-font-size='extra_large']` multiplies them by 1.25, and a flex row of
 * nowrap text that outgrows its track pushes the DOCUMENT wide. `responsive-axe` asserts no horizontal
 * overflow at 375px, and a shared primitive with a non-wrapping row has reddened that gate three times
 * already (H12b, H14, H15b). Clipping a tick label degrades gracefully; the exact values are in the data
 * table either way.
 */
.mds-tsc__x {
    grid-column: 2;
    grid-row: 2;
    display: flex;
    justify-content: space-between;
    gap: var(--mds-space-2);
    min-width: 0;
    overflow: hidden;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-chart-axis);
}

.mds-tsc__x span {
    white-space: nowrap;
}

.mds-tsc__x span:first-child {
    text-align: start;
}

.mds-tsc__x span:last-child {
    text-align: end;
}

/* Decorative scaffolding — deliberately below 1.4.11's 3:1 so it never competes with the data. */
.mds-tsc__grid line {
    stroke: var(--mds-chart-grid);
    stroke-width: 1;
}

.mds-tsc__axis {
    stroke: var(--mds-chart-axis);
    stroke-width: 1;
}

.mds-tsc__line.mds-tsc--s1 {
    stroke: var(--mds-chart-series-1);
    stroke-dasharray: var(--mds-chart-pattern-1);
}

.mds-tsc__line.mds-tsc--s2 {
    stroke: var(--mds-chart-series-2);
    stroke-dasharray: var(--mds-chart-pattern-2);
}

.mds-tsc__line.mds-tsc--s3 {
    stroke: var(--mds-chart-series-3);
    stroke-dasharray: var(--mds-chart-pattern-3);
}

.mds-tsc__line.mds-tsc--s4 {
    stroke: var(--mds-chart-series-4);
    stroke-dasharray: var(--mds-chart-pattern-4);
}

.mds-tsc__line.mds-tsc--s5 {
    stroke: var(--mds-chart-series-5);
    stroke-dasharray: var(--mds-chart-pattern-5);
}

.mds-tsc__area.mds-tsc--s1 {
    fill: var(--mds-chart-series-1);
}

.mds-tsc__area.mds-tsc--s2 {
    fill: var(--mds-chart-series-2);
}

.mds-tsc__area.mds-tsc--s3 {
    fill: var(--mds-chart-series-3);
}

.mds-tsc__area.mds-tsc--s4 {
    fill: var(--mds-chart-series-4);
}

.mds-tsc__area.mds-tsc--s5 {
    fill: var(--mds-chart-series-5);
}

.mds-tsc__legend {
    margin-top: var(--mds-space-4);
}

.mds-tsc__table-wrap {
    margin-top: var(--mds-space-4);
    overflow-x: auto;
}

.mds-tsc__table-wrap--sr {
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

.mds-tsc__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.mds-tsc__table caption {
    text-align: start;
    padding-bottom: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.mds-tsc__table th,
.mds-tsc__table td {
    padding: var(--mds-space-1) var(--mds-space-3);
    text-align: start;
    border-bottom: 1px solid var(--mds-color-border-default);
    white-space: nowrap;
}

.mds-tsc__table td {
    font-variant-numeric: tabular-nums;
}
</style>
