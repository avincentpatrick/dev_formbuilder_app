<script setup lang="ts">
// Authenticated tenant landing page, rendered inside the persistent AppLayout (assigned in app.ts).
// KPI tiles show real, visibility-scoped counts from DashboardController → DashboardMetricsService (H11):
// Owner/Admin/Viewer see org-wide totals; a Form Editor/Reviewer sees own-forms counts and no Members tile.
//
// H24b1 renders the `trends` prop H24a has been serving inertly since PR #86. It is UNGATED for every
// tier — these are `docs/PRD.md:197`'s Phase-1 acceptance criteria, not the Business-only cross-form
// surface — and it is pinned at the response layer by DashboardKpisTest, so a controller-side regression
// is visible before it reaches a screen.
//
// FOUR SHAPES HERE ARE TRAPS, each recorded because rendering it carelessly states something false.
// `dashboard.test.ts` pins all four; the last was found by looking at the rendered page, not by a gate:
//   · `total.change === null` means the prior period held NO rows, so the change is undefined. It is not
//     zero and it is not +100%. MdsStatTile renders an em dash for it.
//   · `drafts` has THREE states, not two — suppressed / unavailable / a number (ADR-0011 §D5). A
//     suppressed rate rendered as "0%" is the same defect the suppression exists to prevent.
//   · `top_forms.other === null` means nothing overflowed the top-N, and `unassigned` is always present
//     even at 0 and is NOT inside `rows`.
//   · The two draft tiles reach "unavailable" by DIFFERENT routes and must not share a sentence — the
//     reasoning now lives with the code, in `components/analytics/draft-metrics.ts`.
//
// H24b2 moved four derivations OUT of this file and into `components/analytics/` — the bucket formatter,
// the breakdown-bar builder and the two draft tiles. Not for tidiness: /analytics renders the same tile
// pair from the same prop shape, and a second copy over there would be the one that regresses, silently,
// exactly as the fourth trap above did the first time. Nothing about what this page RENDERS changed, which
// is why `dashboard.test.ts` still passes byte-unchanged.
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    MdsBarChart,
    MdsButton,
    MdsCard,
    MdsDataTable,
    MdsEmptyState,
    MdsStatTile,
    MdsTimeSeriesChart,
    type ChartSeries,
    type DataTableColumn,
    type IconName,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import AnalyticsViewSwitcher from '@/components/analytics/AnalyticsViewSwitcher.vue';
import { bucketFormatter, rangeLabel as formatRange } from '@/components/analytics/bucket-label';
import { breakdownBars, breakdownTableRows } from '@/components/analytics/breakdown-bars';
import { conversionTile, medianTile } from '@/components/analytics/draft-metrics';
import type { Breakdown, DraftMetrics } from '@/components/analytics/types';

interface Trends {
    range: { from: string; to: string; timezone: string };
    total: { current: number; prior: number; change: number | null };
    series: { bucket: string; count: number }[];
    top_forms: {
        rows: { key: string | null; label: string; count: number }[];
        other: { count: number; categories: number } | null;
        unassigned: number;
    };
    // I10c — the same shape as `top_forms`, deliberately, so one client-side builder reads both.
    channels: {
        rows: { key: string | null; label: string; count: number }[];
        other: { count: number; categories: number } | null;
        unassigned: number;
    };
    forms_accepting: number;
    drafts: DraftMetrics;
}

const props = defineProps<{
    // `members` is null when the user lacks org-wide visibility → the Members tile is omitted.
    kpis: { forms: number; submissions: number; members: number | null };
    trends: Trends;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const canCreate = computed(() => page.props.auth.can.manageForms);

const number = (value: number): string => value.toLocaleString();

// The bucket-timezone trap now lives in `bucket-label.ts` with its explanation and its own test — this
// page's trend is always daily, so it asks for the daily formatter.
const dayLabel = computed(() => bucketFormatter('day'));

const rangeLabel = computed(() =>
    formatRange(props.trends.range.from, props.trends.range.to, props.trends.range.timezone),
);

const tiles = computed(() => {
    const list: { label: string; value: string; icon: IconName; caption?: string }[] = [
        { label: 'Forms', value: number(props.kpis.forms), icon: 'forms' },
        { label: 'Submissions', value: number(props.kpis.submissions), icon: 'submissions', caption: 'All time' },
    ];
    if (props.kpis.members !== null) {
        list.push({ label: 'Members', value: number(props.kpis.members), icon: 'users' });
    }
    // Deliberately not range-scoped: `acceptingFormsCount()` is a right-NOW state (published, inside its
    // window, under its response cap), so the caption says so rather than letting it read as a period total.
    list.push({
        label: 'Accepting responses',
        value: number(props.trends.forms_accepting),
        icon: 'inbox',
        caption: 'Right now',
    });

    return list;
});

const responseSeries = computed<ChartSeries[]>(() => [
    {
        key: 'responses',
        label: 'Responses',
        points: props.trends.series.map((point) => ({
            label: dayLabel.value(point.bucket),
            value: point.count,
        })),
    },
]);

// `top_forms` IS a breakdown on the `form` axis — the same shape /analytics renders, minus the two keys
// the API's axis-agnostic response carries. Naming them here rather than widening the dashboard's prop
// keeps one bar-builder honest across both pages: `form_id` is NOT NULL, so the axis has no Unassigned
// bucket and the builder drops the always-zero value on its own.
const topFormsBreakdown = computed<Breakdown>(() => ({
    axis: 'form',
    rows: props.trends.top_forms.rows,
    other: props.trends.top_forms.other,
    unassigned: props.trends.top_forms.unassigned,
    unassigned_label: 'Unassigned',
    has_unassigned_bucket: false,
}));

const topForms = computed(() => breakdownBars(topFormsBreakdown.value));

// I10c — `docs/PRD.md:198`'s channel breakdown, named here for the same reason `top_forms` is above.
// `has_unassigned_bucket: false` is AnalyticsAxis::hasUnassignedBucket()'s answer for `source`, restated
// client-side exactly as the form axis already is: `submissions.source` is NOT NULL, so there is no
// Unassigned bucket and the builder drops the always-zero value on its own.
const channelsBreakdown = computed<Breakdown>(() => ({
    axis: 'source',
    rows: props.trends.channels.rows,
    other: props.trends.channels.other,
    unassigned: props.trends.channels.unassigned,
    unassigned_label: 'Unassigned',
    has_unassigned_bucket: false,
}));

const channels = computed(() => breakdownBars(channelsBreakdown.value));

// ADR-0011 §D11's "nothing is hidden, only un-plotted", as a table beside the plot. On THIS axis nothing is
// ever folded — the service asks for the full six-case top-N precisely so a CLOSED axis cannot lose a name —
// so the table is a text equivalent of the same rows rather than a disclosure of missing ones. It still earns
// its place: §D12 requires a non-visual equivalent, and axe cannot detect a missing one.
const channelRows = computed(() => breakdownTableRows(channelsBreakdown.value));
const channelColumns = computed<DataTableColumn[]>(() => [
    { key: 'label', header: 'Channel' },
    { key: 'count', header: 'Responses', align: 'end' },
]);

/**
 * Only THREE of the six channels are ever written today (`manual`, `guest`, `offline_sync` — OCR and API
 * import are unbuilt), so the ordinary state of a real tenant is ONE bar at full width: a chart that says
 * "100% of something" and names no quantity. This states the fact in prose instead. Null at two or more
 * channels, where the bars carry the comparison themselves, and the empty state owns the zero case.
 */
const channelNote = computed<string | null>(() => {
    const used = props.trends.channels.rows.filter((row) => row.count > 0);

    return used.length === 1 ? `Every response in this period arrived by ${used[0].label.toLowerCase()}.` : null;
});

// ADR-0011 §D5's three states, and the reason the two tiles never share a sentence, live in
// `draft-metrics.ts` — /analytics renders the identical pair from the identical prop shape.
const conversion = computed(() => conversionTile(props.trends.drafts));
const median = computed(() => medianTile(props.trends.drafts));

// The create-form flow is the "New form" modal on the Forms page; land there rather than duplicate it.
const goToForms = () => router.visit('/forms');
</script>

<template>
    <div>
        <PageHeader title="Dashboard" icon="dashboard">
            <template #actions>
                <!-- Renders NOTHING unless the tenant is entitled AND the user may read analytics. §D9:
                     hidden, never a locked control with an upgrade CTA — Business cannot be bought. -->
                <AnalyticsViewSwitcher current="overview" />
                <MdsButton v-if="canCreate" variant="primary" icon-left="plus" @click="goToForms">
                    Create form
                </MdsButton>
            </template>
        </PageHeader>

        <p class="dash__welcome">Welcome back, {{ user?.name }}.</p>

        <div class="dash__stats">
            <MdsStatTile
                v-for="tile in tiles"
                :key="tile.label"
                :label="tile.label"
                :value="tile.value"
                :icon="tile.icon"
                :caption="tile.caption"
            />
        </div>

        <MdsCard v-if="kpis.forms === 0" class="dash__empty-card">
            <MdsEmptyState
                headline="No forms yet"
                description="Create your first form to start collecting responses."
            >
                <template v-if="canCreate" #action>
                    <MdsButton variant="primary" icon-left="plus" @click="goToForms">Create form</MdsButton>
                </template>
            </MdsEmptyState>
        </MdsCard>

        <section v-else class="dash__trends" aria-labelledby="dash-trends-heading">
            <div class="dash__section-head">
                <h2 id="dash-trends-heading" class="dash__section-title">Last 30 days</h2>
                <p class="dash__section-range">{{ rangeLabel }}</p>
            </div>

            <div class="dash__stats">
                <MdsStatTile
                    label="Responses"
                    icon="submissions"
                    :value="number(trends.total.current)"
                    :delta="trends.total.change"
                    delta-label="vs. previous 30 days"
                />
                <MdsStatTile
                    label="Draft conversion"
                    icon="activity"
                    :value="conversion.value"
                    :unavailable="conversion.unavailable"
                    :unavailable-note="conversion.note"
                    :caption="conversion.caption"
                />
                <MdsStatTile
                    label="Median time to submit"
                    icon="clock"
                    :value="median.value"
                    :unavailable="median.unavailable"
                    :unavailable-note="median.note"
                    :caption="median.caption"
                />
            </div>

            <div class="dash__charts">
                <MdsCard>
                    <template #header><h3 class="dash__card-title">Responses per day</h3></template>
                    <MdsTimeSeriesChart
                        :series="responseSeries"
                        title="Responses per day"
                        variant="area"
                        category-label="Day"
                        value-label="Responses"
                    />
                </MdsCard>

                <MdsCard>
                    <template #header><h3 class="dash__card-title">Top forms</h3></template>
                    <MdsBarChart
                        v-if="topForms.length > 0"
                        :data="topForms"
                        title="Responses by form"
                        category-label="Form"
                        value-label="Responses"
                    />
                    <MdsEmptyState
                        v-else
                        headline="No responses in this period"
                        description="Once a form is submitted, the busiest forms appear here."
                    />
                </MdsCard>

                <MdsCard>
                    <!-- "Channel", never "Source" — the same word AnalyticsChartsCard uses for this axis, so
                         the dashboard and /analytics cannot name the same thing two ways. -->
                    <template #header><h3 class="dash__card-title">Responses by channel</h3></template>
                    <template v-if="channels.length > 0">
                        <MdsBarChart
                            :data="channels"
                            title="Responses by channel"
                            category-label="Channel"
                            value-label="Responses"
                        />
                        <p v-if="channelNote" class="dash__card-note">{{ channelNote }}</p>
                        <MdsDataTable
                            :columns="channelColumns"
                            :rows="channelRows"
                            caption="Every channel in this period"
                            row-key="key"
                        />
                    </template>
                    <!-- Deliberately NOT the top-forms sentence: two adjacent cards rendering the identical
                         copy teaches nothing, and naming the channels means a zero card still says what it
                         will show. -->
                    <MdsEmptyState
                        v-else
                        headline="No responses in this period"
                        description="Responses are grouped by how they arrived — manual entry, guest link or offline sync."
                    />
                </MdsCard>
            </div>
        </section>
    </div>
</template>

<style scoped>
.dash__welcome {
    margin: 0 0 var(--mds-space-6);
    font-size: var(--mds-type-body-lg-font-size);
    color: var(--mds-color-text-secondary);
}

.dash__stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-6);
}

.dash__empty-card {
    padding: 0;
}

.dash__card-note {
    margin: var(--mds-space-3) 0 0;
    font-size: var(--mds-type-body-sm-font-size, var(--mds-type-caption-font-size));
    line-height: var(--mds-type-body-sm-line-height, var(--mds-type-caption-line-height));
    color: var(--mds-color-text-secondary);
}

.dash__section-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--mds-space-3);
    margin-bottom: var(--mds-space-4);
}

.dash__section-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.dash__section-range {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.dash__charts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr));
    gap: var(--mds-space-4);
}

.dash__card-title {
    margin: 0;
    font-size: var(--mds-type-body-lg-font-size);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}
</style>
