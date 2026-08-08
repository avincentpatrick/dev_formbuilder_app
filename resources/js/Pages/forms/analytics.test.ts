import { describe, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import type { FormReport } from '@/components/analytics/types';

/**
 * Increment I10c — `/forms/{form}/analytics`, `docs/PRD.md:198`'s Form Owner/Editor view.
 *
 * ── WHY THE PAIRED-TABLE ASSERTIONS ARE HERE AND NOT LEFT TO THE AXE GATE ───────────────────────────────
 * ADR-0011 §D12: axe CANNOT detect a MISSING text alternative for an SVG that carries a plausible
 * `aria-label`, so the merge-blocking accessibility job would pass a chart that is a beautiful, unreadable
 * picture. The contract is pinned by Vitest per primitive or it is not pinned at all.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', template: '<a :href="$attrs.href"><slot /></a>' },
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="breadcrumbs" /></header>' },
}));

// Imported AFTER the mocks so the component resolves them.
const Analytics = (await import('./Analytics.vue')).default;

// A NON-UTC zone in the fixture, but note where it does and does not go: `range.timezone` reaches only the
// banner text via `rangeLabel()`. `bucketFormatter()` takes ONLY a granularity and formats at UTC by design
// — bucket-label.ts records that formatting at the QUERY's zone was H24b1's bug, since the bucket string is
// already a UTC-truncated date. A non-UTC fixture here is what makes that separation visible rather than
// accidental.
const TZ = 'Asia/Manila';

function report(overrides: Partial<FormReport> = {}): FormReport {
    return {
        range: { from: '2026-07-05', to: '2026-08-03', timezone: TZ, granularity: 'day' },
        prior_range: { from: '2026-06-05', to: '2026-07-04' },
        total: { current: 9, prior: 4, change: 125 },
        series: [
            { bucket: '2026-07-05', count: 4 },
            { bucket: '2026-07-06', count: 5 },
        ],
        breakdown: {
            axis: 'source',
            rows: [
                { key: 'guest', label: 'Guest link', count: 6 },
                { key: 'manual', label: 'Manual entry', count: 3 },
            ],
            other: null,
            unassigned: 0,
            unassigned_label: 'Unassigned',
            has_unassigned_bucket: false,
        },
        drafts: {
            suppressed: false,
            reason: null,
            denominator: 6,
            converted: 3,
            conversion_rate: 50,
            median_seconds: 372,
        },
        week_starts_on: 'monday',
        ...overrides,
    } as FormReport;
}

function render(overrides: Partial<FormReport> = {}): VueWrapper {
    return mount(Analytics, {
        props: {
            form: { id: 'f1', title: 'Clinic Intake' },
            report: report(overrides),
        },
    });
}

function tile(wrapper: VueWrapper, label: string) {
    const found = wrapper
        .findAll('.mds-stat-tile')
        .find((t) => t.find('.mds-stat-tile__label').text() === label);

    if (found === undefined) {
        throw new Error(`no tile labelled "${label}"`);
    }

    return found;
}

describe('forms/Analytics — the §D12 non-visual equivalents', () => {
    it('pairs the trend chart with a table carrying one row per plotted point', () => {
        const wrapper = render();

        // Replacing MdsTimeSeriesChart with a bare <svg aria-label> is the exact substitution axe cannot
        // see, and this is what reddens on it.
        expect(wrapper.findAll('.mds-tsc__table tbody tr')).toHaveLength(2);
        wrapper.unmount();
    });

    it('pairs the channel chart with a table naming every channel', () => {
        const wrapper = render();
        const text = wrapper.text();

        expect(text).toContain('Guest link');
        expect(text).toContain('Manual entry');
        expect(wrapper.findAll('.mds-table tbody tr').length).toBeGreaterThanOrEqual(2);
        wrapper.unmount();
    });

    it('tabulates rows the PLOT drops — a zero Unassigned and the Other total', () => {
        // This file is the only mount of AnalyticsChartsCard in the tree, and §D11's "nothing is hidden, only
        // un-plotted" is a claim about the TABLE being a superset of the bars. Wiring the table to the same
        // builder as the plot would satisfy every other assertion here and quietly break that.
        const wrapper = render({
            breakdown: {
                axis: 'source',
                rows: [
                    { key: 'guest', label: 'Guest link', count: 6 },
                    { key: 'manual', label: 'Manual entry', count: 3 },
                ],
                other: { count: 2, categories: 1 },
                unassigned: 0,
                unassigned_label: 'Unassigned',
                has_unassigned_bucket: true,
            },
        } as Partial<FormReport>);

        const table = wrapper.find('.analytics__breakdown-table');
        expect(table.exists()).toBe(true);
        // The Other total is disclosed in text even though the plot folded it.
        expect(table.text()).toContain('Other');
        wrapper.unmount();
    });
});

describe('forms/Analytics — the page contract', () => {
    it('renders exactly three tiles and never an "Accepting responses" one', () => {
        // AnalyticsSummaryTiles renders a FOURTH tile from `forms_accepting` — a workspace-wide count with
        // no range and no form selection, which on a one-form page is a confidently wrong number beside
        // three correct ones. AnalyticsReportBuilder leaves the key out of this payload entirely.
        const wrapper = render();

        expect(wrapper.findAll('.mds-stat-tile')).toHaveLength(3);
        expect(wrapper.text()).not.toContain('Accepting responses');
        wrapper.unmount();
    });

    it('names the comparison window from prior_range, never a hard-coded "previous 30 days"', () => {
        const wrapper = render();

        expect(tile(wrapper, 'Responses').text()).toContain('vs.');
        expect(wrapper.text()).not.toContain('previous 30 days');
        wrapper.unmount();
    });

    it('does not claim "no drafts were saved" when six were saved and none converted', () => {
        // ADR-0011 §D5's three states. The two draft tiles reach "unavailable" by DIFFERENT routes and must
        // not share a sentence — the contradiction this pins ("0% of 6 saved drafts" beside "No drafts were
        // explicitly saved") is why draft-metrics.ts exists, and why this page imports it rather than
        // hand-rolling the copy.
        const wrapper = render({
            drafts: {
                suppressed: false,
                reason: null,
                denominator: 6,
                converted: 0,
                conversion_rate: 0,
                median_seconds: null,
            },
        });

        expect(wrapper.text()).not.toContain('No drafts were explicitly saved');
        wrapper.unmount();
    });

    it('does not tell the reader to widen a range or clear a filter it cannot offer', () => {
        // AnalyticsChartsCard's DEFAULT empty-state copy names two remediations only /analytics can offer.
        // This page overrides it, and that override is the assertion.
        const wrapper = render({
            breakdown: {
                axis: 'source',
                rows: [],
                other: null,
                unassigned: 0,
                unassigned_label: 'Unassigned',
                has_unassigned_bucket: false,
            },
        } as Partial<FormReport>);

        expect(wrapper.text()).toContain('No responses in this period');
        expect(wrapper.text()).not.toContain('Widen the date range');
        expect(wrapper.text()).not.toContain('clear a filter');
        wrapper.unmount();
    });

    it('offers no export control and no axis control', () => {
        // The gate boundary, rendered. FormAnalyticsPresenter cannot reach the export or an axis picker by
        // construction; this is the client-side half of that claim.
        const wrapper = render();

        expect(wrapper.text()).not.toMatch(/export/i);
        expect(wrapper.findAll('select')).toHaveLength(0);
        expect(wrapper.findAll('.mds-segmented')).toHaveLength(0);
        wrapper.unmount();
    });

    it('links back to Forms and names the form it is reporting on', () => {
        const wrapper = render();

        expect(wrapper.find('a[href="/forms"]').text()).toContain('Forms');
        expect(wrapper.text()).toContain('Clinic Intake');
        wrapper.unmount();
    });

    it('formats bucket labels at UTC regardless of the range’s declared zone', () => {
        // ⚠️ THE CONTRACT IS THE OPPOSITE OF WHAT IT LOOKS LIKE, AND I WROTE IT BACKWARDS FIRST.
        // `bucketFormatter()` takes ONLY a granularity and hard-codes `timeZone: 'UTC'`; the query zone is
        // deliberately NOT plumbed into it. bucket-label.ts's own docblock records that formatting at the
        // query's zone was the H24b1 BUG — the bucket string is already a UTC-truncated date, so re-applying
        // an offset shifts it a day for any negative-offset zone. The fixture's Manila zone therefore reaches
        // only `rangeLabel()`'s banner text, never the axis.
        //
        // So this asserts the bucket renders as its OWN day whatever the range claims, which is what the
        // hard-coded UTC buys.
        const wrapper = render();

        expect(wrapper.find('.mds-tsc__table tbody tr').text()).toMatch(/5 Jul|Jul 5/);
        expect(wrapper.text()).not.toContain('4 Jul');
        wrapper.unmount();
    });
});
