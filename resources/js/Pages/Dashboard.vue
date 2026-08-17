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
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    MdsBarChart,
    MdsButton,
    MdsCard,
    MdsChecklist,
    MdsDataTable,
    MdsEmptyState,
    MdsIcon,
    MdsStatTile,
    MdsTimeSeriesChart,
    type ChartSeries,
    type ChecklistItem,
    type DataTableColumn,
    type IconName,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import CreateFormModal from '@/components/forms/CreateFormModal.vue';
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
        rows: { key: string | null; label: string; count: number; url: string | null }[];
        other: { count: number; categories: number } | null;
        unassigned: number;
    };
    // I10c — the same shape as `top_forms`, deliberately, so one client-side builder reads both.
    channels: {
        rows: { key: string | null; label: string; count: number; url: string | null }[];
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
    // J5b — null when the card should not appear at all: dismissed, or every step done. The server decides
    // that; this page reads the null rather than re-deriving the condition, for the same reason
    // `empty_reason` is server-computed on the forms list — the client cannot see what the server knows.
    checklist: ChecklistItem[] | null;
    // J5c — onboarding §2's two first-run choices, each resolved against the gates its own route carries.
    // NOT read from the `entitlements` prop: that snapshot is built from `EntitlementService::feature()`,
    // which fails CLOSED on an unseeded plan catalog while the route's middleware fails OPEN, so a
    // client-side gate would withhold the template card on a request the server would have served. See
    // DashboardController::firstRunChoices().
    start: { can_create: boolean; can_use_templates: boolean };
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const canCreate = computed(() => page.props.auth.can.manageForms);
// J2d — one ability per DESTINATION, read from the same `ShellAbilities` map the sidebar and the command
// palette read. `/dashboard` itself is ungated, so a tile linking somewhere gated is the J2c defect over
// again: `/forms` is `viewAny,Form` (a Reviewer and a Viewer hold none of its three keys) and `/members` is
// `tenant.members.invite` (Owner/Admin only) while the Members TILE renders for anyone with
// `dashboard.org.view` — which a Viewer has.
const canManageMembers = computed(() => page.props.auth.can.manageMembers);
const canViewSubmissions = computed(() => page.props.auth.can.viewSubmissions);

const number = (value: number): string => value.toLocaleString();

// The bucket-timezone trap now lives in `bucket-label.ts` with its explanation and its own test — this
// page's trend is always daily, so it asks for the daily formatter.
const dayLabel = computed(() => bucketFormatter('day'));

const rangeLabel = computed(() =>
    formatRange(props.trends.range.from, props.trends.range.to, props.trends.range.timezone),
);

const tiles = computed(() => {
    const list: { label: string; value: string; icon: IconName; caption?: string; href?: string }[] = [
        {
            label: 'Forms',
            value: number(props.kpis.forms),
            icon: 'forms',
            ...(canCreate.value ? { href: '/forms' } : {}),
        },
        {
            label: 'Submissions',
            value: number(props.kpis.submissions),
            icon: 'submissions',
            caption: 'All time',
            ...(canViewSubmissions.value ? { href: '/submissions' } : {}),
        },
    ];
    if (props.kpis.members !== null) {
        list.push({
            label: 'Members',
            value: number(props.kpis.members),
            icon: 'users',
            ...(canManageMembers.value ? { href: '/members' } : {}),
        });
    }
    // Deliberately not range-scoped: `acceptingFormsCount()` is a right-NOW state (published, inside its
    // window, under its response cap), so the caption says so rather than letting it read as a period total.
    // ⚠️ DELIBERATELY NOT A LINK, and the reason is the same one that makes the other three safe. This
    // counts forms that are published AND inside their window AND under their response cap; `/forms` has no
    // filter expressing that set, so linking it would land the reader on a list whose length disagrees with
    // the number they just clicked. A destination that does not answer the question asked is the defect this
    // sweep removes, not an instance of it. The three trend tiles below are unlinked for the twin reason:
    // they are period-scoped, and `/submissions` is an all-time inbox.
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

// The header action stays a trip to the Forms page: it is the *ordinary* create affordance, and the list
// is where somebody with forms expects to manage them. The first-run moment below is the exception — it
// opens the shared dialog in place, because sending a workspace with nothing in it to an empty list to be
// offered the same two choices a second time is one click of pure ceremony.
const goToForms = () => router.visit('/forms');

/**
 * Hide the getting-started card for this person, in this workspace, for good (J5b).
 *
 * `MdsChecklist` emits and never hides itself — the `MdsAlert`/`MdsToast` contract — so the disappearance
 * is the server's answer on the next render rather than a local flag. That is what makes the dismissal
 * survive a reload, and it is why there is no optimistic hide here: a local `v-if` would make the card
 * vanish even when the write failed, and the user would find it back tomorrow with no idea why.
 *
 * `preserveScroll` because the card sits mid-page and the read below it is what the reader was doing.
 */
const dismissChecklist = () => router.post('/onboarding/dismiss', {}, { preserveScroll: true });

// ── The first-run moment (J5c — onboarding plan §2) ──────────────────────────────────────────────────
// Zero forms is the whole condition. A brand-new workspace lands here and gets ONE screen with two
// choices on it, not a wizard: §2 argues against a scripted tour in its own words, because a walkthrough
// delays the thing that demonstrates the product's value.
const isFirstRun = computed(() => props.kpis.forms === 0);

const createOpen = ref(false);

/**
 * The blank card is a real `<button>` and the template card a real `<Link>` — never a div with a click
 * handler, the rule `MdsCard`'s own `interactive` variant exists to enforce. So this fires on both, and
 * does nothing on the one whose element already navigates.
 */
function chooseStart(choice: { href: string | null }): void {
    if (choice.href === null) {
        createOpen.value = true;
    }
}

// ⚠️ TWO CARDS, NOT AN EMPTY STATE WITH TWO BUTTONS, AND THE TWO DOCUMENTS DO NOT ACTUALLY DISAGREE.
// DSR §3.10's governing rule is that an empty state carries EXACTLY ONE primary CTA — which is why the
// forms list renders one primary and one tertiary here, correctly. §2 asks for two EQUALLY-WEIGHTED
// choices and names the pattern in the same sentence: "presented as the standard card-grid pattern"
// (§3.5). So the first-run moment is a card grid; equal weight is what the grid is for.
//
// ⚠️ AND A REFUSED TEMPLATE CARD IS ABSENT, NEVER LOCKED WITH AN UPGRADE PROMPT. ADR-0011 §D9's posture
// for every plan-gated surface in this product, and it is not a style preference: Business is held from
// sale, so an upsell would point at a plan nobody can buy. The blank card then stands alone and the moment
// still works — which is the test §2's "equally weighted" has to survive on a free tenant.
const startChoices = computed(() => {
    if (!props.start.can_create) {
        return [];
    }

    const blank = {
        key: 'blank',
        href: null,
        icon: 'plus' as IconName,
        title: 'Start from blank',
        body: 'Name your form and open the builder with an empty canvas.',
    };

    if (!props.start.can_use_templates) {
        return [blank];
    }

    return [
        {
            key: 'template',
            href: '/forms/templates',
            icon: 'layout' as IconName,
            title: 'Start from a template',
            body: 'Pick a ready-made form — a survey, a registration, a feedback questionnaire — and change what you like.',
        },
        blank,
    ];
});
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

        <!--
            ⚠️ THE FOUR KPI TILES AND THE TREND SECTION ARE BOTH SUPPRESSED ON THE FIRST RUN, WHICH IS THE
            POINT OF ONBOARDING §2 RATHER THAN A TIDY-UP. Its words: "rather than dropping a brand-new
            tenant onto a literal empty dashboard, the first authenticated screen is a lightweight 'Create
            your first form' moment". Four zeroes and an empty chart above that moment are exactly the
            literal empty dashboard it names.
        -->
        <template v-if="isFirstRun">
            <section class="dash__start" aria-labelledby="dash-start-heading">
                <h2 id="dash-start-heading" class="dash__section-title">Create your first form</h2>
                <p class="dash__start-lede">
                    Two ways in. Both open the builder, so you can change everything afterwards.
                </p>

                <div v-if="startChoices.length > 0" class="dash__start-grid">
                    <component
                        :is="choice.href ? Link : 'button'"
                        v-for="choice in startChoices"
                        :key="choice.key"
                        :href="choice.href ?? undefined"
                        :type="choice.href ? undefined : 'button'"
                        class="dash__start-card"
                        @click="chooseStart(choice)"
                    >
                        <span class="dash__start-icon"><MdsIcon :name="choice.icon" size="md" /></span>
                        <span class="dash__start-title">{{ choice.title }}</span>
                        <span class="dash__start-body">{{ choice.body }}</span>
                    </component>
                </div>

                <!--
                    No CTA, and different copy — §3.10's extended governing rule: a surface that is empty
                    because of a PERMISSION restriction explains why, rather than offering a button that
                    would 403. This reader can see the workspace and cannot author in it.
                -->
                <MdsCard v-else class="dash__empty-card">
                    <MdsEmptyState
                        headline="No forms yet"
                        description="Nobody has built a form in this workspace. When someone does, its responses appear here."
                    />
                </MdsCard>
            </section>

            <CreateFormModal v-model:open="createOpen" />
        </template>

        <template v-else>
            <div class="dash__stats">
                <MdsStatTile
                    v-for="tile in tiles"
                    :key="tile.label"
                    :label="tile.label"
                    :value="tile.value"
                    :icon="tile.icon"
                    :caption="tile.caption"
                    :href="tile.href"
                />
            </div>

            <!--
                J5b — passive, and BELOW the numbers on purpose: onboarding §2 rules out anything the user
                must click through before reaching the product, and a card that sits under the tiles is
                read by someone who chose to keep reading. It renders only once a form exists, because the
                first-run moment above already says "create your first form" and one screen must not say
                it twice.
            -->
            <MdsCard v-if="checklist" class="dash__checklist">
                <MdsChecklist
                    :items="checklist"
                    :link-component="Link"
                    dismissible
                    @dismiss="dismissChecklist"
                />
            </MdsCard>

            <section class="dash__trends" aria-labelledby="dash-trends-heading">
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
        </template>
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

/* ── The first-run moment (J5c) ─────────────────────────────────────────────────────────────────────── */
.dash__start {
    margin-bottom: var(--mds-space-6);
}

.dash__start-lede {
    margin: var(--mds-space-1) 0 var(--mds-space-4);
    color: var(--mds-color-text-secondary);
}

/* ⚠️ `minmax(min(100%, 260px), 1fr)`, and the `min()` is load-bearing rather than decorative — the JR3
   finding, stated again because it is invisible when it breaks: `.app-shell` is `overflow-x: clip`, so a
   track that overruns its container is CLIPPED rather than scrolled, and the e2e overflow assertion reads
   `documentElement.scrollWidth`, which that clip pins flat. An overrunning grid is therefore structurally
   invisible to CI. Never simplify this to a bare `minmax(260px, 1fr)`. */
.dash__start-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
    gap: var(--mds-space-4);
}

/* The two choices are ONE grid of equal tracks, which is what makes them equally weighted in the sense
   onboarding §2 means — not two buttons of different variants. Card chrome is repeated here rather than
   composed from `MdsCard`, because `MdsCard`'s interactive variant takes a single href and one of these
   two opens a dialog instead. */
.dash__start-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--mds-space-2);
    width: 100%;
    padding: var(--mds-space-5);
    text-align: left;
    font: inherit;
    color: var(--mds-color-text-body);
    text-decoration: none;
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-xl);
    box-shadow: var(--mds-shadow-1);
    cursor: pointer;
    transition:
        box-shadow var(--mds-duration-base) var(--mds-ease-standard),
        border-color var(--mds-duration-base) var(--mds-ease-standard);
}

/* `-fg`, never `-bg`: a border is a coloured edge, and the `-bg` half guarantees contrast only for text
   printed ON it. `MdsCard`'s interactive variant measures the identical pair — 7.01:1 / 6.54:1 light and
   8.29:1 / 9.56:1 dark — and this hover is deliberately the same one, so the two surfaces cannot drift. */
.dash__start-card:hover {
    box-shadow: var(--mds-shadow-2);
    border-color: var(--mds-color-action-primary-fg);
}

.dash__start-card:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .dash__start-card {
        transition: none;
    }
}

/* The tinted medallion §3.10 specifies for an empty state's illustration, reused deliberately: this is the
   same first-run family, and `--mds-color-action-primary-tint` is the ONE accent fill that is redeclared
   for dark rather than riding the primary ramp — `primary-50` here would put a near-white slab on a dark
   card, which is the trap §3.10's own note records. */
.dash__start-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: var(--mds-radius-lg);
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-action-primary-fg);
}

.dash__start-title {
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.dash__start-body {
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.dash__checklist {
    margin-bottom: var(--mds-space-6);
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
