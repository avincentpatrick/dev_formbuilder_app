<script setup lang="ts">
// The trend line and the breakdown bars (Increment H24b2).
//
// ── The trend chart is single-series and stays that way ─────────────────────────────────────────────────
// AnalyticsMetricsService::series() returns one zero-filled series; there is no per-category variant, and
// adding one would change the byte-diffed /api/v1 response shape. So MdsTimeSeriesChart's five-series cap
// never binds here, and no categorical colour is used at all — which is exactly ADR-0011 §D12's "a
// single-series chart distinguishes its categories by their axis labels".
//
// ── The breakdown table is NOT the chart's table ────────────────────────────────────────────────────────
// MdsBarChart tabulates what it is GIVEN, and the plot legitimately drops a zero Unassigned bucket. §D11's
// "nothing is hidden, only un-plotted" is only true if the page ALSO renders the full row set — including
// a zero Unassigned on an axis that has one, and the Other bucket's total. `breakdownTableRows()` builds
// that superset, and the MdsDataTable below is where it lands.
import { computed } from 'vue';
import {
    MdsBarChart,
    MdsCard,
    MdsDataTable,
    MdsEmptyState,
    MdsTimeSeriesChart,
    type ChartSeries,
    type DataTableColumn,
} from '@meridian/design-system';
import { breakdownBars, breakdownTableRows } from './breakdown-bars';
import { bucketFormatter, categoryLabel } from './bucket-label';
import type { ChartsReport } from './types';

// `ChartsReport` (I10c), not `Report`: this card reads only range/series/breakdown, and narrowing the prop
// lets a surface with no workspace-wide accepting count reuse it. Type-only — /analytics still passes a
// full Report, which is structurally assignable, so nothing changes at runtime.
const props = withDefaults(
    defineProps<{
        report: ChartsReport;
        /**
         * Copy for the breakdown's empty state.
         *
         * Overridable because the default names two remediations — widening the range, clearing a filter —
         * that only /analytics can offer. `/forms/{form}/analytics` has neither control and structurally
         * never will (its window and axis are literals in FormAnalyticsPresenter), so telling its user to
         * reach for them would send them hunting for affordances that do not exist. Caught by I10c's
         * adversarial review, which is the kind of thing reusing a component wholesale gets you.
         */
        breakdownEmptyDescription?: string;
    }>(),
    { breakdownEmptyDescription: 'Widen the date range, or clear a filter, to see a breakdown here.' },
);

const label = computed(() => bucketFormatter(props.report.range.granularity));

const series = computed<ChartSeries[]>(() => [
    {
        key: 'responses',
        label: 'Responses',
        points: props.report.series.map((point) => ({
            label: label.value(point.bucket),
            value: point.count,
        })),
    },
]);

const bars = computed(() => breakdownBars(props.report.breakdown));
const tableRows = computed(() => breakdownTableRows(props.report.breakdown));

const axisName = computed(
    () =>
        ({
            form: 'Form',
            source: 'Channel',
            status: 'Status',
            scope_node: 'Area',
            locale: 'Language',
        })[props.report.breakdown.axis],
);

const columns = computed<DataTableColumn[]>(() => [
    { key: 'label', header: axisName.value },
    { key: 'count', header: 'Responses', align: 'end' },
]);
</script>

<template>
    <div class="analytics__charts">
        <MdsCard>
            <template #header>
                <h3 class="analytics__card-title">Responses over time</h3>
            </template>
            <MdsTimeSeriesChart
                :series="series"
                title="Responses over time"
                variant="area"
                :category-label="categoryLabel(report.range.granularity)"
                value-label="Responses"
            />
        </MdsCard>

        <MdsCard>
            <template #header>
                <h3 class="analytics__card-title">Responses by {{ axisName.toLowerCase() }}</h3>
            </template>

            <MdsBarChart
                v-if="bars.length > 0"
                :data="bars"
                :title="`Responses by ${axisName.toLowerCase()}`"
                :category-label="axisName"
                value-label="Responses"
            />
            <MdsEmptyState v-else headline="No responses in this period" :description="breakdownEmptyDescription" />

            <div v-if="tableRows.length > 0" class="analytics__breakdown-table">
                <MdsDataTable
                    :columns="columns"
                    :rows="tableRows"
                    :caption="`Every ${axisName.toLowerCase()} in this period, including any folded into Other`"
                    row-key="key"
                />
            </div>
        </MdsCard>
    </div>
</template>

<style scoped>
.analytics__charts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-6);
}

.analytics__card-title {
    margin: 0;
    font-size: var(--mds-type-body-lg-font-size);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.analytics__breakdown-table {
    margin-top: var(--mds-space-4);
}
</style>
