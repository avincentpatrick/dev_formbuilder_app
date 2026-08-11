import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { AppliedQuery, FilterOptions, QuestionAggregate, QuestionRow, Report, SavedView } from '@/components/analytics/types';

/**
 * Increment H24b2 — the /analytics page's reading of AnalyticsPresenter's props.
 *
 * ADR-0011 §D12 is why this file is not optional: **axe cannot detect a missing text alternative for an SVG
 * that carries a plausible `aria-label`**, so the merge-blocking accessibility job will happily pass a
 * chart that is a beautiful, unreadable picture. The data-table assertions below are the only gate over
 * that contract, and deleting one loses coverage nothing replaces.
 *
 * The rest are the places where rendering a shape carelessly states something the data does not support —
 * the same four traps `dashboard.test.ts` names, arriving here on a second surface where the range is
 * user-chosen rather than fixed.
 */

const mocks = vi.hoisted(() => ({
    pageProps: {
        auth: { user: { name: 'Demo Owner' }, can: { manageForms: true, viewAnalytics: true } },
        entitlements: { features: { advanced_analytics: true } },
    },
    get: vi.fn(),
    visit: vi.fn(),
    del: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { get: mocks.get, visit: mocks.visit, delete: mocks.del },
    usePage: () => ({ props: mocks.pageProps }),
    useForm: () => ({
        name: '',
        errors: {},
        processing: false,
        clearErrors: vi.fn(),
        transform: vi.fn().mockReturnThis(),
        post: vi.fn(),
        patch: vi.fn(),
    }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

// Imported AFTER the mocks so the component resolves them.
const Analytics = (await import('./Index.vue')).default;

// A NON-UTC zone throughout: under a UTC runner a component that dropped the `timeZone` option would still
// render the right day, so a UTC fixture cannot see the bug the timezone plumbing exists to prevent.
const TZ = 'Asia/Manila';

function applied(overrides: Partial<AppliedQuery> = {}): AppliedQuery {
    return {
        v: 1,
        from: '2026-07-05',
        to: '2026-08-03',
        timezone: TZ,
        granularity: 'day',
        selection: 'all',
        form_ids: [],
        scope_node_id: null,
        axis: 'form',
        statuses: [],
        sources: [],
        locales: [],
        top_n: 5,
        ...overrides,
    };
}

function report(overrides: Partial<Report> = {}): Report {
    return {
        range: { from: '2026-07-05', to: '2026-08-03', timezone: TZ, granularity: 'day' },
        prior_range: { from: '2026-06-05', to: '2026-07-04' },
        total: { current: 30, prior: 12, change: 150 },
        series: Array.from({ length: 30 }, (_, i) => ({
            bucket: `2026-07-${String(5 + i).padStart(2, '0')}`,
            count: i % 5,
        })).slice(0, 26),
        breakdown: {
            axis: 'form',
            rows: [
                { key: 'f1', label: 'Community Health Survey', count: 9, url: '/forms/f1' },
                { key: 'f2', label: 'Household Roster', count: 6, url: '/forms/f2' },
            ],
            other: null,
            unassigned: 0,
            unassigned_label: 'Unassigned',
            has_unassigned_bucket: false,
        },
        drafts: {
            suppressed: false,
            reason: null,
            denominator: 8,
            converted: 5,
            conversion_rate: 62.5,
            median_seconds: 900,
        },
        forms_accepting: 5,
        week_starts_on: 'monday',
        ...overrides,
    };
}

function filters(): FilterOptions {
    return {
        forms: [{ value: 'f1', label: 'Community Health Survey' }],
        scope_nodes: [{ value: 'n1', label: 'Luzon', depth: 0, is_active: true }],
        locales: [
            { value: 'en', label: 'en' },
            { value: 'es', label: 'es' },
        ],
        axes: [
            { value: 'form', label: 'Form' },
            { value: 'source', label: 'Channel' },
        ],
        granularities: [{ value: 'day', label: 'Daily' }],
        selections: [{ value: 'all', label: 'All forms I can see' }],
        statuses: [{ value: 'submitted', label: 'Submitted' }],
        sources: [{ value: 'guest', label: 'Guest' }],
        timezones: [TZ, 'UTC'],
        limits: {
            max_range_days: 366,
            max_form_ids: 100,
            max_top_n: 20,
            default_top_n: 5,
            default_range_days: 29,
        },
    };
}

function render(overrides: Record<string, unknown> = {}): VueWrapper {
    return mount(Analytics, {
        props: {
            applied: applied(),
            refusal: null,
            can: { createView: true },
            filters: filters(),
            summary: { exports: { used: 2, limit: 100 } },
            report: report(),
            questions: [] as QuestionRow[],
            question: null as QuestionAggregate | null,
            views: [] as SavedView[],
            ...overrides,
        },
    });
}

/** The tile whose visible label matches, so assertions do not depend on tile ORDER. */
function tile(wrapper: VueWrapper, label: string) {
    const found = wrapper
        .findAll('.mds-stat-tile')
        .find((t) => t.find('.mds-stat-tile__label').text() === label);
    expect(found, `no tile labelled "${label}"`).toBeDefined();

    return found!;
}

describe('Analytics — the §D12 data tables (axe cannot see these)', () => {
    it('pairs the trend chart with a table carrying one row per plotted point', () => {
        const wrapper = render();

        expect(wrapper.findAll('.mds-tsc__table tbody tr')).toHaveLength(26);
        wrapper.unmount();
    });

    it('pairs the breakdown chart with a table of every category on a NON-linkable axis', () => {
        // A `source` axis names values, not entities, so no bar carries an href and `MdsBarChart` keeps
        // `role="img"` AND its own sr-only table. This is the half of §D12 that survives J2d unchanged.
        const wrapper = render({
            report: report({
                breakdown: {
                    axis: 'source',
                    rows: [
                        { key: 'guest', label: 'Guest link', count: 9, url: null },
                        { key: 'manual', label: 'Manual entry', count: 3, url: null },
                    ],
                    other: null,
                    unassigned: 0,
                    unassigned_label: 'Unassigned',
                    has_unassigned_bucket: true,
                },
            }),
        });

        expect(wrapper.findAll('.mds-bar__table tbody tr')).toHaveLength(2);
        expect(wrapper.find('.mds-table').exists()).toBe(true);
        wrapper.unmount();
    });

    it('drops the chart’s own table on the FORM axis, where the bars become links', () => {
        /*
         * ⚠️ THIS ASSERTION WAS INVERTED BY J2d, AND THE INVERSION IS CORRECT RATHER THAN A LOSS.
         * `role="img"` is a LEAF: every descendant leaves the accessibility tree, so a link inside the plot
         * would be unreachable, not merely unlabelled. `MdsBarChart` therefore drops `role="img"` the moment
         * any datum is linked — and with it the sr-only data table, which would otherwise make a screen
         * reader announce the whole dataset twice (the plot is now traversable).
         *
         * §D12's "nothing is hidden" still holds on this page, and that is what the third assertion pins:
         * the paired `MdsDataTable` is unconditional here and carries every category regardless.
         */
        const wrapper = render();

        expect(wrapper.findAll('a.mds-bar__label--link').map((a) => a.attributes('href'))).toEqual([
            '/forms/f1',
            '/forms/f2',
        ]);
        expect(wrapper.find('.mds-bar__table').exists()).toBe(false);
        expect(wrapper.find('.mds-bar__summary').exists()).toBe(true);
        expect(wrapper.find('.mds-table').exists()).toBe(true);
        wrapper.unmount();
    });

    it('tabulates the Other bucket the plot folds away, so nothing is HIDDEN, only un-plotted', () => {
        const wrapper = render({
            report: report({
                breakdown: {
                    axis: 'form',
                    rows: [{ key: 'f1', label: 'Community Health Survey', count: 9, url: '/forms/f1' }],
                    other: { count: 3, categories: 2 },
                    unassigned: 0,
                    unassigned_label: 'Unassigned',
                    has_unassigned_bucket: false,
                },
            }),
        });

        expect(wrapper.text()).toContain('Other (2 forms)');
        expect(wrapper.findAll('.mds-bar__fill--other')).toHaveLength(1);
        wrapper.unmount();
    });

    it('tabulates a ZERO Unassigned bucket that the plot legitimately drops', () => {
        const wrapper = render({
            report: report({
                breakdown: {
                    axis: 'locale',
                    rows: [{ key: 'en', label: 'en', count: 9, url: null }],
                    other: null,
                    unassigned: 0,
                    unassigned_label: 'Not recorded',
                    has_unassigned_bucket: true,
                },
            }),
        });

        // Absent from the plot…
        expect(wrapper.findAll('.mds-bar__label').map((s) => s.text())).toEqual(['en']);
        // …and present in the page's own table (`.mds-table`, the MdsDataTable root).
        expect(wrapper.find('.mds-table').text()).toContain('Not recorded');
        wrapper.unmount();
    });
});

describe('Analytics — the delta', () => {
    it('renders a null change as an em dash, never as a percentage', () => {
        const wrapper = render({ report: report({ total: { current: 9, prior: 0, change: null } }) });

        const delta = tile(wrapper, 'Responses').find('.mds-stat-tile__delta');
        expect(delta.text()).toContain('—');
        expect(delta.text()).not.toContain('%');
        wrapper.unmount();
    });

    it('names the comparison window from the RESOLVED prior range, not a hard-coded 30 days', () => {
        // The range here is the user's, so "vs. previous 30 days" would be a claim the page cannot support
        // the moment they pick a different one.
        const wrapper = render();

        const text = tile(wrapper, 'Responses').text();
        expect(text).toContain('+150%');
        expect(text).toContain('Jun 5, 2026');
        expect(text).toContain('Jul 4, 2026');
        expect(text).not.toContain('previous 30 days');
        wrapper.unmount();
    });
});

describe('Analytics — the three draft states', () => {
    it('shows both rates with their denominator when the data supports them', () => {
        const wrapper = render();

        expect(tile(wrapper, 'Draft conversion').find('.mds-stat-tile__value').text()).toBe('62.5%');
        expect(tile(wrapper, 'Draft conversion').text()).toContain('of 8 saved drafts');
        expect(tile(wrapper, 'Median time to submit').find('.mds-stat-tile__value').text()).toBe('15m');
        wrapper.unmount();
    });

    it('suppresses BOTH tiles past the retention window, and never as a zero', () => {
        const wrapper = render({
            report: report({
                drafts: {
                    suppressed: true,
                    reason: 'beyond_draft_retention',
                    denominator: 0,
                    converted: null,
                    conversion_rate: null,
                    median_seconds: null,
                },
            }),
        });

        for (const label of ['Draft conversion', 'Median time to submit']) {
            expect(tile(wrapper, label).find('.mds-stat-tile__value').text()).toBe('—');
            expect(tile(wrapper, label).text()).toContain('draft retention window');
            expect(tile(wrapper, label).text()).not.toContain('0%');
        }
        wrapper.unmount();
    });

    it('does not claim "no drafts were saved" when eight were saved and none converted', () => {
        // The H24b1 defect, re-asserted on the SECOND surface. Both pages render this pair from one module
        // now, and this is the assertion that proves the second page inherited the fix rather than the bug.
        const wrapper = render({
            report: report({
                drafts: {
                    suppressed: false,
                    reason: null,
                    denominator: 8,
                    converted: 0,
                    conversion_rate: 0,
                    median_seconds: null,
                },
            }),
        });

        expect(tile(wrapper, 'Draft conversion').find('.mds-stat-tile__value').text()).toBe('0%');
        const median = tile(wrapper, 'Median time to submit');
        expect(median.find('.mds-stat-tile__value').text()).toBe('—');
        expect(median.text()).toContain('have been submitted yet');
        expect(median.text()).not.toContain('No drafts were explicitly saved');
        wrapper.unmount();
    });
});

describe('Analytics — the range and its timezone', () => {
    it('formats bucket labels in the QUERY timezone, not the viewer’s', () => {
        const wrapper = render();

        expect(wrapper.findAll('.mds-tsc__x span')[0].text()).toBe('Jul 5');
        wrapper.unmount();
    });

    it('states the window and its zone, so no tile reads as an all-time total', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Jul 5, 2026 – Aug 3, 2026 (Asia/Manila)');
        wrapper.unmount();
    });
});

describe('Analytics — the refusal state', () => {
    it('renders the reason and no charts when the selection cannot be resolved', () => {
        const wrapper = render({
            refusal: { reason: 'scope_node_missing', message: 'The area this report groups by has been deleted.' },
            report: null,
        });

        expect(wrapper.text()).toContain('has been deleted');
        expect(wrapper.find('.mds-tsc').exists()).toBe(false);
        expect(wrapper.find('.mds-stat-tile').exists()).toBe(false);
        // The filter rail stays, so the user can change the selection rather than being stuck.
        expect(wrapper.find('.analytics__filters').exists()).toBe(true);
        wrapper.unmount();
    });
});

describe('Analytics — the question explorer', () => {
    const questions: QuestionRow[] = [
        {
            key: 'district',
            label: 'District',
            field_type: 'short_text',
            indexed_data_type: 'text',
            reportable: true,
            refusal: null,
            refusal_label: null,
            first_indexed_version: 1,
            type_changed_at_version: null,
        },
        {
            key: 'notes',
            label: 'Notes',
            field_type: 'long_text',
            indexed_data_type: null,
            reportable: false,
            refusal: 'not_flagged',
            refusal_label: 'Turn on "Indexed for reporting" to report on this question from the next published version onward.',
            first_indexed_version: null,
            type_changed_at_version: null,
        },
    ];

    it('ENCODES each refusal as visible text beside the question, never as a bare disabled row', () => {
        // §D3: the picker must STATE why a question is unavailable. A colour or a disabled attribute alone
        // fails WCAG 1.4.1 and, worse, tells the author nothing about what to change.
        const wrapper = render({ questions });

        expect(wrapper.text()).toContain('Indexed for reporting');
        // Only the reportable question is selectable at all. Scoped to the picker: MdsSegmentedControl
        // (the view switcher) is built on native radios too, so an unscoped query counts those.
        const radios = wrapper.find('.analytics__picker').findAll('input[type="radio"]');
        expect(radios).toHaveLength(1);
        expect(radios[0].attributes('value')).toBe('district');
        wrapper.unmount();
    });

    it('discloses the version a question became reportable from', () => {
        const wrapper = render({ questions });

        expect(wrapper.text()).toContain('Reportable from version 1 onward');
        wrapper.unmount();
    });

    it('renders a refused aggregate with its message and NO number beside it', () => {
        const wrapper = render({
            questions,
            question: {
                key: 'district',
                label: 'District',
                refused: true,
                reason: 'type_changed',
                message: 'This question changed type in version 4; values before it cannot be combined with values after.',
                indexed_data_type: 'text',
                first_indexed_version: 1,
                type_changed_at_version: 4,
                coverage: { indexed: 0, countable: 30 },
                indexed_column: true,
                rows: null,
                other: null,
                summary: null,
            },
        });

        expect(wrapper.text()).toContain('changed type in version 4');
        expect(wrapper.text()).not.toContain('of 30 responses in this period carry a value');
        wrapper.unmount();
    });

    it('discloses coverage rather than implying completeness', () => {
        // §D3(iii): the projector drops rows silently WITHIN a flagged field, so a count over the index
        // under-counts against `submissions` and the surface must show its own coverage.
        const wrapper = render({
            questions,
            question: {
                key: 'district',
                label: 'District',
                refused: false,
                reason: null,
                message: null,
                indexed_data_type: 'text',
                first_indexed_version: 1,
                type_changed_at_version: null,
                coverage: { indexed: 4, countable: 30 },
                indexed_column: true,
                rows: [
                    { key: 'Sampaloc', count: 2 },
                    { key: 'Malate', count: 1 },
                ],
                other: null,
                summary: null,
            },
        });

        expect(wrapper.text()).toContain('4 of 30 responses in this period carry a value');
        wrapper.unmount();
    });

    it('renders the unindexed-column note when indexed_column is false, and maps true/false to Yes/No', () => {
        const wrapper = render({
            questions,
            question: {
                key: 'follow_up_needed',
                label: 'Follow-up needed',
                refused: false,
                reason: null,
                message: null,
                indexed_data_type: 'boolean',
                first_indexed_version: 1,
                type_changed_at_version: null,
                coverage: { indexed: 5, countable: 30 },
                indexed_column: false,
                rows: [
                    { key: 'true', count: 3 },
                    { key: 'false', count: 2 },
                ],
                other: null,
                summary: null,
            },
        });

        expect(wrapper.text()).toContain('not separately indexed');
        // `value_boolean::text` comes back as the literal strings — rendering them raw would put "true" on
        // a chart a respondent answered "Yes" to.
        const labels = wrapper.findAll('.mds-bar__label').map((s) => s.text());
        expect(labels).toContain('Yes');
        expect(labels).toContain('No');
        expect(labels).not.toContain('true');
        wrapper.unmount();
    });

    it('renders a numeric question as a summary rather than as a category chart', () => {
        const wrapper = render({
            questions,
            question: {
                key: 'households_reached',
                label: 'Households reached',
                refused: false,
                reason: null,
                message: null,
                indexed_data_type: 'number',
                first_indexed_version: 1,
                type_changed_at_version: null,
                coverage: { indexed: 5, countable: 30 },
                indexed_column: true,
                rows: null,
                other: null,
                summary: { count: 5, min: 12, max: 88, average: 43.8, median: 34 },
            },
        });

        expect(tile(wrapper, 'Median').find('.mds-stat-tile__value').text()).toBe('34');
        expect(tile(wrapper, 'Average').find('.mds-stat-tile__value').text()).toBe('43.8');
        wrapper.unmount();
    });
});

describe('Analytics — saved views', () => {
    const views: SavedView[] = [
        {
            id: 'v1',
            name: 'Field team',
            definition: {},
            refused: false,
            reason: null,
            message: null,
            stale_form_ids: [],
            is_applied: true,
            created_at: null,
            updated_at: null,
        },
        {
            id: 'v2',
            name: 'Legacy rollup',
            definition: {},
            refused: true,
            reason: 'malformed_definition',
            message: 'This report was saved in format v99 and cannot be read.',
            stale_form_ids: [],
            is_applied: false,
            created_at: null,
            updated_at: null,
        },
    ];

    it('renders a refused view BESIDE the working ones, with its reason, and refuses to apply it', () => {
        const wrapper = render({ views });

        expect(wrapper.text()).toContain('Field team');
        expect(wrapper.text()).toContain('cannot be read');

        const applyButtons = wrapper.findAll('.analytics__view').map((row) =>
            row.findAll('button').find((b) => b.text() === 'Apply'),
        );
        // Both disabled, for OPPOSITE reasons: one is already showing, the other cannot be resolved.
        expect(applyButtons[0]?.attributes('disabled')).toBeDefined();
        expect(applyButtons[1]?.attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('discloses stale form ids without refusing the view', () => {
        const wrapper = render({
            views: [{ ...views[0], is_applied: false, stale_form_ids: ['gone-1', 'gone-2'] }],
        });

        expect(wrapper.text()).toContain('2 of the forms in this report no longer exist');
        wrapper.unmount();
    });
});

describe('Analytics — the reload contract', () => {
    it('drives a filter change through a PARTIAL reload, not a full visit', () => {
        mocks.get.mockClear();
        const wrapper = render();

        wrapper.findComponent({ name: 'AnalyticsFilterBar' }).vm.$emit('apply', { axis: 'source' });

        expect(mocks.get).toHaveBeenCalledTimes(1);
        const [url, params, options] = mocks.get.mock.calls[0];
        expect(url).toBe('/analytics');
        expect(params.axis).toBe('source');
        // `v` is never echoed: the server stamps the schema version, and a second author of it is how a
        // stored definition comes to carry a version nothing wrote.
        expect(params).not.toHaveProperty('v');
        expect(options.only).toContain('report');
        // The option catalogues are tenant-scoped and cannot change with a filter — leaving them out is
        // what makes shipping the full IANA timezone list affordable.
        expect(options.only).not.toContain('filters');
        expect(options.preserveState).toBe(true);
        wrapper.unmount();
    });

    it('never says "Pick a question" while one IS picked', async () => {
        // FOUND BY LOOKING, not by a gate. With the radio checked and the aggregate still in flight, the
        // card read "Pick a question" — two controls on one screen disagreeing about whether a question had
        // been picked. Same class as H24b1's two draft tiles, and the same lesson: the gates were all green.
        const wrapper = render({
            questions: [
                {
                    key: 'district',
                    label: 'District',
                    field_type: 'short_text',
                    indexed_data_type: 'text',
                    reportable: true,
                    refusal: null,
                    refusal_label: null,
                    first_indexed_version: null,
                    type_changed_at_version: null,
                },
            ],
        });

        wrapper.findComponent({ name: 'QuestionPicker' }).vm.$emit('select', 'district');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Pick a question');
        expect(wrapper.text()).toContain('Summarising this question');
        wrapper.unmount();
    });

    it('shows pending rather than the PREVIOUS question’s numbers when switching questions', async () => {
        // The comparison is on `key`, not on null: keeping the old aggregate visible under the new
        // question's name is worse than an empty card, because it is a number that looks right.
        const wrapper = render({
            question: {
                key: 'district',
                label: 'District',
                refused: false,
                reason: null,
                message: null,
                indexed_data_type: 'text',
                first_indexed_version: null,
                type_changed_at_version: null,
                coverage: { indexed: 4, countable: 30 },
                indexed_column: true,
                rows: [{ key: 'Malate', count: 4 }],
                other: null,
                summary: null,
            },
        });

        expect(wrapper.text()).toContain('Malate');

        wrapper.findComponent({ name: 'QuestionPicker' }).vm.$emit('select', 'households_reached');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Summarising this question');
        expect(wrapper.text()).not.toContain('Malate');
        wrapper.unmount();
    });

    it('re-fetches ONLY the aggregate when a question is picked', () => {
        mocks.get.mockClear();
        const wrapper = render({
            questions: [
                {
                    key: 'district',
                    label: 'District',
                    field_type: 'short_text',
                    indexed_data_type: 'text',
                    reportable: true,
                    refusal: null,
                    refusal_label: null,
                    first_indexed_version: null,
                    type_changed_at_version: null,
                },
            ],
        });

        wrapper.findComponent({ name: 'QuestionPicker' }).vm.$emit('select', 'district');

        const [, params, options] = mocks.get.mock.calls[0];
        expect(params.question).toBe('district');
        expect(options.only).toEqual(['applied', 'question']);
        wrapper.unmount();
    });

    it('re-seeds its controls from what the SERVER applied, not from what was asked for', () => {
        // The honesty mechanism. When the server coerces a value — a defaulted window, a clamped top_n —
        // the controls have to move with it, or the page shows one declaration and reports another.
        const wrapper = render();

        wrapper.setProps({ applied: applied({ top_n: 3, axis: 'status' }) });

        return wrapper.vm.$nextTick().then(() => {
            expect(wrapper.findComponent({ name: 'AnalyticsFilterBar' }).props('applied')).toMatchObject({
                top_n: 3,
                axis: 'status',
            });
            wrapper.unmount();
        });
    });
});
