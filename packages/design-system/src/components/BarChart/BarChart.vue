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
 *
 * ── ⚠️ A LINKED DATUM CHANGES THE PLOT'S ACCESSIBLE STRUCTURE, AND IT HAS TO (Increment J2a) ────────────
 * `BarDatum.href` makes a bar's label a link to the object it counts. That cannot simply be dropped into
 * the markup: the plot carries `role="img"`, and an element with that role is a LEAF — every descendant is
 * removed from the accessibility tree. The visible labels and values already inside it are not announced
 * today either (the summary sentence plus the data table below are what a screen reader gets, which is the
 * existing deliberate design). A link left in there would therefore be not merely unlabelled but
 * unreachable, which is worse than the dead end J2 set out to remove.
 *
 * So when ANY datum carries an href the plot stops being one image: `role="img"` and its label come off,
 * the summary moves into a visually-hidden paragraph immediately before the plot so the one-sentence
 * overview is not lost, and each linked row's label becomes a real anchor. The tracks stay `aria-hidden`
 * either way, so nothing gains "a dozen unlabelled images". With no hrefs the rendered output is
 * byte-identical to before J2a — which is what `BarChart.test.ts` asserts, in both directions.
 *
 * ⚠️ AND THE sr-ONLY DATA TABLE COMES OFF WITH IT, which is not an optimisation. Un-pruning the plot
 * exposes its labels and values to a screen reader for the first time; leaving the table rendered as well
 * announced the entire dataset TWICE — the summary, then every row, then every row again. The table is the
 * plot's ALTERNATIVE, so it is rendered when the plot is an image and not when the plot is readable. It
 * stays whenever `tableVisible`, which is a visible feature rather than an alternative.
 *
 * The VALUE is deliberately outside the anchor. The link goes to the category, not to its count, and
 * "Clinic Intake" is the accessible name a reader needs; "Clinic Intake 128" reads as a single odd label
 * and makes every link name change whenever the data moves. **`MdsTabNav` deliberately does the opposite**
 * with its badge, and the rule the two share is: a count belongs INSIDE the name when it describes the
 * destination ("Submissions 128" — how much is behind this link), and outside it when it is the data being
 * plotted.
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

/**
 * See the docblock: one linked datum is enough to take the plot out of `role="img"`.
 *
 * ⚠️ `Boolean(d.href)` MUST STAY THE SAME PREDICATE THE TEMPLATE USES (`v-if="bar.href"`). An earlier
 * version tested `!== undefined` here while the template tested truthiness, so `href: ''` — the obvious
 * shape of a `cond ? url : ''` call site — stripped `role="img"` AND its label AND rendered zero links:
 * the worst of both structures, for no benefit, with every test still green.
 */
const isInteractive = computed(() => props.data.some((d) => Boolean(d.href)));
</script>

<template>
    <figure class="mds-bar">
        <p v-if="isInteractive && !isEmpty" class="mds-bar__summary">{{ accessibleSummary }}</p>

        <div
            class="mds-bar__plot"
            :role="isEmpty || isInteractive ? undefined : 'img'"
            :aria-label="isEmpty || isInteractive ? undefined : accessibleSummary"
        >
            <div v-for="bar in bars" :key="bar.key" class="mds-bar__row">
                <a v-if="bar.href" class="mds-bar__label mds-bar__label--link" :href="bar.href">
                    {{ bar.label }}
                </a>
                <span v-else class="mds-bar__label">{{ bar.label }}</span>
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

        <!-- ⚠️ The sr-only table is the plot's ALTERNATIVE, so it is not rendered when the plot is itself
             readable. Dropping `role="img"` un-prunes the plot's labels and values, and leaving the table
             in as well made a screen reader announce the whole dataset TWICE — summary, then every row,
             then every row again. Kept whenever `tableVisible`, which is a deliberate visible feature
             rather than an alternative. -->
        <div
            v-if="tableVisible || !isInteractive"
            class="mds-bar__table-wrap"
            :class="{ 'mds-bar__table-wrap--sr': !tableVisible }"
        >
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
/* `position: relative` so the two clipped-but-present children (`.mds-bar__summary` and the sr-only table
   wrap) resolve their containing block INSIDE this figure. Without a positioned ancestor an absolutely
   positioned 1px element is clipped by whatever ancestor happens to establish one, and can then extend the
   DOCUMENT's scrollable width — the G11 defect `MdsDataTable`'s scroll wrapper records at length, which
   `assertClean`'s horizontal-overflow assertion is what finally caught. */
.mds-bar {
    position: relative;
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

/* The summary the plot's `aria-label` carries when the chart is static. Rendered only in the interactive
   shape, and only to assistive tech — sighted readers have the bars themselves. Same clip idiom as
   `.mds-bar__table-wrap--sr`; it holds no focusable content, so it mints no invisible tab stop. */
.mds-bar__summary {
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

.mds-bar__label--link {
    color: var(--mds-color-text-body);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.mds-bar__label--link:hover {
    color: var(--mds-color-text-heading);
}

.mds-bar__label--link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
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
