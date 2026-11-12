<script setup lang="ts">
// One explorer result, drawn as the chart its declaration asked for (M236, ADR-0025 §D7).
//
// Every branch is a design-system primitive: this component chooses one and hands it data, and holds no
// geometry and no colour. `result-view.ts` does the mapping, so what each chart receives is under test.
//
// `showTable` turns on the primitive's own visible data table. The table is there for assistive technology
// either way — that is the primitives' contract — so the switch changes what a sighted reader sees and
// nothing about what a screen reader can reach.
import { computed } from 'vue';
import {
    MdsBarChart,
    MdsColumnChart,
    MdsDonutChart,
    MdsEmptyState,
    MdsStatTile,
    MdsTimeSeriesChart,
} from '@meridian/design-system';
import ExploreResultTable from './ExploreResultTable.vue';
import { formatNumber, toBarData, toColumns, toDonut, toLines, toTiles } from './result-view';
import type { Result } from './types';

const props = withDefaults(
    defineProps<{
        result: Result;
        /** The chart's name: the accessible name of the plot and the caption of its table. */
        title: string;
        showTable?: boolean;
    }>(),
    { showTable: false },
);

const categoryLabel = computed(() => props.result.dimensions[0]?.label ?? 'Group');
const valueLabel = computed(() => props.result.measures[0]?.label ?? 'Value');

const bars = computed(() => toBarData(props.result));
const columns = computed(() => toColumns(props.result));
const lines = computed(() => toLines(props.result));
const donut = computed(() => toDonut(props.result));
const tiles = computed(() => toTiles(props.result));

const isEmpty = computed(() => props.result.totals.rows === 0);

/**
 * Fewer axis labels when the labels are long. The primitives thin labels by an even stride and do not
 * measure text, so six of "Week of 10 Aug 2026" run into each other where six of "Aug 2026" do not.
 */
const maxTicks = computed(() => (props.result.categories.some((c) => c.label.length > 12) ? 4 : 6));
</script>

<template>
    <div class="explore-chart">
        <MdsEmptyState
            v-if="isEmpty"
            illustration="search"
            headline="Nothing in this range"
            :description="`No ${result.meta.row_noun} match this chart’s range and filters.`"
        />

        <div v-else-if="result.chart === 'number'" class="explore-chart__tiles">
            <MdsStatTile
                v-for="tile in tiles"
                :key="tile.key"
                :label="tile.label"
                icon="hash"
                :value="tile.value"
                :caption="tile.caption"
            />
        </div>

        <ExploreResultTable v-else-if="result.chart === 'table'" :result="result" :title="title" />

        <MdsBarChart
            v-else-if="result.chart === 'bar'"
            :data="bars"
            :title="title"
            :category-label="categoryLabel"
            :value-label="valueLabel"
            :table-visible="showTable"
            :format-value="formatNumber"
        />

        <MdsDonutChart
            v-else-if="result.chart === 'donut'"
            :data="donut.data"
            :title="title"
            :category-label="categoryLabel"
            :value-label="valueLabel"
            :center-value="formatNumber(donut.total)"
            center-label="Total"
            :table-visible="showTable"
            :format-value="formatNumber"
        />

        <MdsTimeSeriesChart
            v-else-if="result.chart === 'line' || result.chart === 'area'"
            :series="lines.series"
            :title="title"
            :variant="result.chart"
            :max-ticks="maxTicks"
            :category-label="categoryLabel"
            :value-label="valueLabel"
            :table-visible="showTable"
            :format-tick="formatNumber"
        />

        <MdsColumnChart
            v-else
            :categories="columns.categories"
            :series="columns.series"
            :title="title"
            :mode="result.chart === 'stacked' ? 'stacked' : 'grouped'"
            :max-ticks="maxTicks"
            :category-label="categoryLabel"
            :value-label="valueLabel"
            :table-visible="showTable"
            :format-tick="formatNumber"
            :format-value="formatNumber"
        />

        <p
            v-if="!isEmpty && (result.chart === 'line' || result.chart === 'area') && lines.substituted"
            class="explore-chart__note"
        >
            A period with nothing to measure is drawn at zero, and the table shows that zero too. It is not a measured zero: there was nothing to count.
        </p>
    </div>
</template>

<style scoped>
.explore-chart {
    min-width: 0;
}

.explore-chart__tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
    gap: var(--mds-space-4);
}

.explore-chart__note {
    margin: var(--mds-space-3) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
