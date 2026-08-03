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
//   · The two draft tiles reach "unavailable" by DIFFERENT routes and must not share a sentence — see
//     the note above `draftsUnavailable`.
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    MdsBarChart,
    MdsButton,
    MdsCard,
    MdsEmptyState,
    MdsStatTile,
    MdsTimeSeriesChart,
    type BarDatum,
    type ChartSeries,
    type IconName,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

interface Trends {
    range: { from: string; to: string; timezone: string };
    total: { current: number; prior: number; change: number | null };
    series: { bucket: string; count: number }[];
    top_forms: {
        rows: { key: string | null; label: string; count: number }[];
        other: { count: number; categories: number } | null;
        unassigned: number;
    };
    forms_accepting: number;
    drafts: {
        suppressed: boolean;
        reason: string | null;
        denominator: number;
        converted: number | null;
        conversion_rate: number | null;
        median_seconds: number | null;
    };
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

/**
 * Every bucket is a `YYYY-MM-DD` string in the QUERY's timezone, which the prop states (UTC today).
 * `new Date('2026-08-03')` parses as UTC midnight, so formatting it without an explicit `timeZone`
 * renders the PREVIOUS day for any viewer west of Greenwich — an off-by-one that is invisible to
 * whoever writes the code and wrong for half the planet.
 */
const dayLabel = computed(() => {
    const format = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        timeZone: props.trends.range.timezone,
    });

    return (bucket: string): string => format.format(new Date(`${bucket}T00:00:00Z`));
});

const rangeLabel = computed(() => {
    const format = new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: props.trends.range.timezone,
    });
    const from = format.format(new Date(`${props.trends.range.from}T00:00:00Z`));
    const to = format.format(new Date(`${props.trends.range.to}T00:00:00Z`));

    return `${from} – ${to} (${props.trends.range.timezone})`;
});

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

const topForms = computed<BarDatum[]>(() => {
    const bars: BarDatum[] = props.trends.top_forms.rows.map((row) => ({
        key: row.key ?? 'unassigned-row',
        label: row.label,
        value: row.count,
    }));

    // Always present, never inside `rows`, and only worth a bar when it holds something.
    if (props.trends.top_forms.unassigned > 0) {
        bars.push({ key: 'unassigned', label: 'Unassigned', value: props.trends.top_forms.unassigned });
    }

    // `null` means nothing overflowed the top-N. When it does, the bucket is NEUTRAL, never a recycled
    // hue, so "Other" cannot read as a peer category (ADR-0011 §D11).
    const other = props.trends.top_forms.other;
    if (other !== null) {
        bars.push({
            key: 'other',
            label: `Other (${other.categories} ${other.categories === 1 ? 'form' : 'forms'})`,
            value: other.count,
            neutral: true,
        });
    }

    return bars;
});

const RETENTION_NOTE =
    'This period reaches past the draft retention window, and expired drafts are deleted — a rate computed over what survived would rise every night.';
const NO_DRAFTS_NOTE = 'No drafts were explicitly saved in this period.';

/**
 * THE TWO TILES ARE UNAVAILABLE FOR DIFFERENT REASONS AND MUST SAY SO SEPARATELY.
 *
 * Found by looking at the real screen rather than by a gate: with six saved drafts and none of them yet
 * submitted, the conversion tile correctly read "0% — of 6 saved drafts" while the median tile, sharing
 * one note, read "No drafts were explicitly saved in this period." Two tiles side by side, disagreeing
 * about whether any drafts exist.
 *
 * The states are not the same shape. `conversion_rate` is null only when the denominator is zero;
 * `median_seconds` is additionally null when the denominator is POSITIVE but nothing in it converted —
 * `percentile_cont` over an empty set returns NULL. That third state needs its own sentence, which is
 * §D5's "suppression has three states, never two" landing on the UI side of the same line.
 */
const draftsUnavailable = computed(
    () => props.trends.drafts.suppressed || props.trends.drafts.conversion_rate === null,
);

const medianUnavailable = computed(
    () => props.trends.drafts.suppressed || props.trends.drafts.median_seconds === null,
);

const conversionNote = computed(() => (props.trends.drafts.suppressed ? RETENTION_NOTE : NO_DRAFTS_NOTE));

const medianNote = computed(() => {
    if (props.trends.drafts.suppressed) return RETENTION_NOTE;
    if (props.trends.drafts.denominator === 0) return NO_DRAFTS_NOTE;

    return 'None of the drafts saved in this period have been submitted yet, so there is no first-save-to-submit time to measure.';
});

const draftDenominator = computed(() => {
    const n = props.trends.drafts.denominator;

    return `of ${number(n)} saved ${n === 1 ? 'draft' : 'drafts'}`;
});

function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
    if (seconds < 86400) {
        const hours = Math.floor(seconds / 3600);

        return `${hours}h ${Math.round((seconds - hours * 3600) / 60)}m`;
    }

    const days = Math.floor(seconds / 86400);

    return `${days}d ${Math.round((seconds - days * 86400) / 3600)}h`;
}

const medianValue = computed(() =>
    props.trends.drafts.median_seconds === null ? null : formatDuration(props.trends.drafts.median_seconds),
);

// The create-form flow is the "New form" modal on the Forms page; land there rather than duplicate it.
const goToForms = () => router.visit('/forms');
</script>

<template>
    <div>
        <PageHeader title="Dashboard" icon="dashboard">
            <template v-if="canCreate" #actions>
                <MdsButton variant="primary" icon-left="plus" @click="goToForms">Create form</MdsButton>
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
                    :value="trends.drafts.conversion_rate === null ? null : `${trends.drafts.conversion_rate}%`"
                    :unavailable="draftsUnavailable"
                    :unavailable-note="draftsUnavailable ? conversionNote : undefined"
                    :caption="draftsUnavailable ? undefined : draftDenominator"
                />
                <MdsStatTile
                    label="Median time to submit"
                    icon="clock"
                    :value="medianValue"
                    :unavailable="medianUnavailable"
                    :unavailable-note="medianUnavailable ? medianNote : undefined"
                    :caption="medianUnavailable ? undefined : `${draftDenominator}, first save to submit`"
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
