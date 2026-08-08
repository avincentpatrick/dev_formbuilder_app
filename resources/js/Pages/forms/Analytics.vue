<script setup lang="ts">
/**
 * Per-form response statistics (Increment I10c) — `docs/PRD.md:198`'s Form Owner/Editor view: "submissions
 * over time for that form", the two ADR-0011 §D5 draft metrics, and a breakdown by submission channel.
 *
 * UNGATED, on every tier. PRD.md:198 is a Phase-1 acceptance criterion; `advanced_analytics` gates the
 * Phase-3 surface. `FormAnalyticsPresenter`'s docblock records the five structural mechanisms that keep this
 * page from drifting into a second `/analytics`, and `routes/tenant.php` records why the route carries
 * `can:view,form` and no `feature:`.
 *
 * ── WHY THE THREE TILES ARE HAND-ROLLED AND THE CHARTS ARE NOT ──────────────────────────────────────────
 * `AnalyticsChartsCard` is reused wholesale — it already renders a trend plus an arbitrary-axis breakdown
 * with its paired data table, and it already names the `source` axis "Channel", so this page and
 * `/analytics` cannot come to call the same thing two different things. NO new chart code ships here.
 *
 * `AnalyticsSummaryTiles` is NOT reused, and the reason is a number rather than a preference: it renders a
 * fourth tile, "Accepting responses", from `report.forms_accepting` — a workspace-wide count that takes no
 * range and no form selection. On a page scoped to ONE form that would be a confidently wrong number sitting
 * beside three correct ones, which is exactly why `AnalyticsReportBuilder` leaves the key out of this
 * payload altogether. What that component's docblock actually protects — the two draft SENTENCES, whose
 * duplication once produced "0% of 6 saved drafts" beside "No drafts were explicitly saved" — is preserved,
 * because those live in `draft-metrics.ts` and this page imports them unchanged.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { MdsStatTile } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import AnalyticsChartsCard from '@/components/analytics/AnalyticsChartsCard.vue';
import { conversionTile, medianTile } from '@/components/analytics/draft-metrics';
import { dateLabel, rangeLabel as formatRange } from '@/components/analytics/bucket-label';
import type { FormReport } from '@/components/analytics/types';

const props = defineProps<{
    form: { id: string; title: string };
    report: FormReport;
}>();

const conversion = computed(() => conversionTile(props.report.drafts));
const median = computed(() => medianTile(props.report.drafts));

const rangeLabel = computed(() =>
    formatRange(props.report.range.from, props.report.range.to, props.report.range.timezone),
);

// Named from the RESOLVED prior range, never hard-coded. The window here is fixed at 30 days today, but a
// literal "vs. previous 30 days" would be a claim the page has no basis for the moment that changes — the
// same rule AnalyticsSummaryTiles records.
const deltaLabel = computed(
    () => `vs. ${dateLabel(props.report.prior_range.from)} – ${dateLabel(props.report.prior_range.to)}`,
);
</script>

<template>
    <div>
        <Head :title="`Response statistics — ${form.title}`" />

        <PageHeader title="Response statistics" icon="chart-bar">
            <template #breadcrumbs>
                <Link href="/forms" class="fa__crumb">← Forms</Link>
            </template>
        </PageHeader>

        <p class="fa__intro">
            Responses to <strong>{{ form.title }}</strong> over the last 30 days.
        </p>
        <p class="fa__range">{{ rangeLabel }}</p>

        <div class="fa__tiles">
            <MdsStatTile
                label="Responses"
                icon="submissions"
                :value="report.total.current.toLocaleString()"
                :delta="report.total.change"
                :delta-label="deltaLabel"
            />
            <MdsStatTile
                label="Draft conversion"
                icon="edit"
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

        <AnalyticsChartsCard :report="report" />
    </div>
</template>

<style scoped>
.fa__crumb {
    color: var(--mds-color-text-secondary);
    text-decoration: none;
}

.fa__crumb:hover {
    text-decoration: underline;
}

.fa__intro {
    margin: 0 0 var(--mds-space-1);
    color: var(--mds-color-text-body);
}

.fa__range {
    margin: 0 0 var(--mds-space-6);
    font-size: var(--mds-type-body-sm-font-size, var(--mds-type-caption-font-size));
    color: var(--mds-color-text-secondary);
}

.fa__tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-6);
}
</style>
