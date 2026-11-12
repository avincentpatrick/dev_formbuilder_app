import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MdsCheckbox, MdsColumnChart } from '@meridian/design-system';
import { rangeSentence } from './result-view';
import type { BuilderOptions, ChartType, Definition, Field, Filter, Result, ResultCategory, ResultSeries, RunPayload, SavedChart } from './types';

/**
 * M236 — the six components the data explorer is drawn from.
 *
 * ── WHY THE DATA-TABLE ASSERTIONS ARE HERE AND NOT LEFT TO THE AXE GATE ─────────────────────────────────
 * ADR-0011 §D12, on its third surface: **axe cannot detect a missing text alternative for an SVG that
 * carries a plausible `aria-label`**, so the merge-blocking accessibility job passes a chart that is a
 * picture and nothing else. `ExploreChart` chooses between five plotting primitives, and each branch is a
 * place the table could be lost — so the contract is asserted once PER chart type, below, and deleting one
 * of those cases loses coverage nothing replaces.
 *
 * ── AND THE TWO THINGS A SAVE MUST NEVER DO ─────────────────────────────────────────────────────────────
 * Renaming or sharing a chart sends a name and a switch. It never sends the declaration on screen, because
 * the reader may have been twiddling it — and "rename" silently rewriting what a chart charts is the kind
 * of defect that is found by a colleague, weeks later, on a board. `ExploreSaveModal`'s cases pin the WIRE
 * body for that reason, not merely that a request happened.
 */

const mocks = vi.hoisted(() => ({
    fetchChartResult: vi.fn(),
    sent: { method: '', url: '', payload: null as Record<string, unknown> | null },
}));

vi.mock('./exploreClient', () => ({ fetchChartResult: mocks.fetchChartResult }));

// `reactive` is load-bearing: Inertia's real useForm returns a reactive object, and a plain one freezes the
// submit button's `disabled` at its first value. `transform` + `post`/`patch` are captured so a case can
// assert the body that would be sent — RuleFormModal's spec established this shape for the same reason.
vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', template: '<a><slot /></a>' },
    useForm: (initial: Record<string, unknown>) => {
        type Body = Record<string, unknown>;

        function send(form: Body, method: string, url: string): void {
            const transform = form.__transform as ((data: Body) => Body) | undefined;

            mocks.sent.method = method;
            mocks.sent.url = url;
            mocks.sent.payload = transform ? transform(form) : { ...form };
        }

        return reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            clearErrors: () => {},
            transform(this: Body, fn: (data: Body) => Body) {
                this.__transform = fn;

                return this;
            },
            post(this: Body, url: string) {
                send(this, 'post', url);
            },
            patch(this: Body, url: string) {
                send(this, 'patch', url);
            },
        });
    },
}));

// Imported AFTER the mocks so the components resolve them.
const ExploreChart = (await import('./ExploreChart.vue')).default;
const ExploreResult = (await import('./ExploreResult.vue')).default;
const ExploreResultTable = (await import('./ExploreResultTable.vue')).default;
const ExploreFilters = (await import('./ExploreFilters.vue')).default;
const ExploreTile = (await import('./ExploreTile.vue')).default;
const ExploreSaveModal = (await import('./ExploreSaveModal.vue')).default;

// A NON-UTC zone throughout, as on /analytics.
const TZ = 'Asia/Manila';
const TITLE = 'Responses by channel';

function category(key: string, label: string, other = false): ResultCategory {
    return { key, label, other };
}

function series(key: string, label: string, values: (number | null)[], other = false): ResultSeries {
    return { key, label, other, values };
}

/** Responses by channel, as `ExplorerQueryRunner::run()` shapes a one-group-by row count. */
function result(overrides: Partial<Result> = {}, meta: Partial<Result['meta']> = {}): Result {
    return {
        chart: 'bar',
        dimensions: [{ key: 'source', label: 'Channel', type: 'category', bucket: null }],
        measures: [{ key: 'm0', label: 'Count of responses', aggregate: 'count', additive: true }],
        categories: [category('guest', 'Guest link'), category('manual', 'Manual entry'), category('offline_sync', 'Offline sync')],
        series: [series('m0', 'Count of responses', [210, 120, 44])],
        totals: { rows: 374, values: [374], coverage: [null] },
        ...overrides,
        meta: {
            source: 'responses',
            source_label: 'Responses',
            row_noun: 'responses',
            form: null,
            from: '2026-03-01',
            to: '2026-03-31',
            timezone: TZ,
            range_label: 'Submitted',
            groups: 3,
            hidden_groups: 0,
            hidden_series: 0,
            capped: false,
            notes: [],
            ...meta,
        },
    };
}

/** Average completeness by month for two channels — the shape where the server sends real nulls. */
function monthly(chart: ChartType, guest: (number | null)[], manual: (number | null)[]): Result {
    return result({
        chart,
        dimensions: [
            { key: 'submitted_at', label: 'Submitted', type: 'time', bucket: 'month' },
            { key: 'source', label: 'Channel', type: 'category', bucket: null },
        ],
        measures: [{ key: 'm0', label: 'Average of Completeness (%)', aggregate: 'avg', additive: false }],
        categories: [category('2026-01-01', 'Jan 2026'), category('2026-02-01', 'Feb 2026'), category('2026-03-01', 'Mar 2026')],
        series: [series('guest', 'Guest link', guest), series('manual', 'Manual entry', manual)],
    });
}

function definition(overrides: Partial<Definition> = {}): Definition {
    return {
        source: 'responses',
        form_id: null,
        chart: 'bar',
        dimensions: [{ field: 'source', bucket: null }],
        measures: [{ aggregate: 'count', field: null }],
        filters: [],
        from: '2026-03-01',
        to: '2026-03-31',
        timezone: TZ,
        sort: 'value_desc',
        limit: 10,
        ...overrides,
    };
}

function savedChart(overrides: Partial<SavedChart> = {}): SavedChart {
    return {
        id: 'c1',
        name: TITLE,
        definition: { v: 1, ...definition() },
        is_shared: false,
        is_mine: true,
        owner: null,
        source_label: 'Responses',
        can: { update: true, delete: true },
        updated_at: '2026-03-31T09:00:00+08:00',
        ...overrides,
    };
}

beforeEach(() => {
    mocks.fetchChartResult.mockReset();
    mocks.sent.method = '';
    mocks.sent.url = '';
    mocks.sent.payload = null;
});

// ── ExploreChart ────────────────────────────────────────────────────────────────────────────────────────

function drawn(of: Result, props: { showTable?: boolean } = {}): VueWrapper {
    return mount(ExploreChart, { props: { result: of, title: TITLE, ...props } });
}

/** Every chart type that is a PICTURE, with the root class of its primitive and of its table's wrapper. */
const PLOTTED: { chart: ChartType; root: string; wrap: string }[] = [
    { chart: 'bar', root: '.mds-bar', wrap: '.mds-bar__table-wrap' },
    { chart: 'column', root: '.mds-col', wrap: '.mds-col__table-wrap' },
    { chart: 'stacked', root: '.mds-col', wrap: '.mds-col__table-wrap' },
    { chart: 'line', root: '.mds-tsc', wrap: '.mds-tsc__table-wrap' },
    { chart: 'area', root: '.mds-tsc', wrap: '.mds-tsc__table-wrap' },
    { chart: 'donut', root: '.mds-donut', wrap: '.mds-donut__table-wrap' },
];

const EVERY_PLOT = ['.mds-bar', '.mds-col', '.mds-tsc', '.mds-donut'];

describe('ExploreChart — which primitive draws which chart', () => {
    it.each(PLOTTED)('draws a $chart chart with $root and with no other plot beside it', ({ chart, root }) => {
        const wrapper = drawn(result({ chart }));

        expect(wrapper.find(root).exists()).toBe(true);
        for (const other of EVERY_PLOT.filter((plot) => plot !== root)) {
            expect(wrapper.find(other).exists(), other).toBe(false);
        }
        wrapper.unmount();
    });

    it('reaches the column primitive in STACKED mode for a stacked chart, and grouped for a plain one', () => {
        // The two share a primitive and differ by one prop, so the root class cannot tell them apart. What a
        // reader of the accessible name hears is what does.
        const stacked = drawn(monthly('stacked', [80, 70, 90], [60, 55, 75]));
        const grouped = drawn(monthly('column', [80, 70, 90], [60, 55, 75]));

        expect(stacked.findComponent(MdsColumnChart).props('mode')).toBe('stacked');
        expect(stacked.get('.mds-col__canvas').attributes('aria-label')).toContain('Stacked column chart');
        expect(grouped.findComponent(MdsColumnChart).props('mode')).toBe('grouped');
        expect(grouped.get('.mds-col__canvas').attributes('aria-label')).not.toContain('Stacked');
        stacked.unmount();
        grouped.unmount();
    });

    it('fills the area under an area chart and not under a line chart', () => {
        const area = drawn(monthly('area', [80, 70, 90], [60, 55, 75]));
        const line = drawn(monthly('line', [80, 70, 90], [60, 55, 75]));

        expect(area.findAll('.mds-tsc__area')).toHaveLength(2);
        expect(line.find('.mds-tsc__area').exists()).toBe(false);
        area.unmount();
        line.unmount();
    });

    it('shows a Number chart as one tile per value, with its coverage beneath the one taken over a field', () => {
        const wrapper = drawn(
            result({
                chart: 'number',
                dimensions: [],
                measures: [
                    { key: 'm0', label: 'Count of responses', aggregate: 'count', additive: true },
                    { key: 'm1', label: 'Average of Completeness (%)', aggregate: 'avg', additive: false },
                ],
                categories: [],
                series: [],
                totals: { rows: 374, values: [374, 43.8], coverage: [null, 300] },
            }),
        );

        const tiles = wrapper.findAll('.mds-stat-tile');
        expect(tiles.map((tile) => tile.get('.mds-stat-tile__label').text())).toEqual([
            'Count of responses',
            'Average of Completeness (%)',
        ]);
        expect(tiles.map((tile) => tile.get('.mds-stat-tile__value').text())).toEqual(['374', '43.8']);
        expect(tiles[0].find('.mds-stat-tile__caption').exists()).toBe(false);
        expect(tiles[1].get('.mds-stat-tile__caption').text()).toBe('From 300 of 374 responses');
        wrapper.unmount();
    });

    it('shows a Table chart as the plain table and draws no plot', () => {
        const wrapper = drawn(result({ chart: 'table' }));

        expect(wrapper.find('.explore-table table').exists()).toBe(true);
        for (const plot of EVERY_PLOT) expect(wrapper.find(plot).exists(), plot).toBe(false);
        wrapper.unmount();
    });
});

describe('ExploreChart — the §D12 data tables (axe cannot see these)', () => {
    it.each(PLOTTED)('pairs a $chart chart with a real table naming every category and its value', ({ chart, wrap }) => {
        const wrapper = drawn(result({ chart }));

        // Exactly one, and a real one: a `<table>` element with a caption and row headers, not a list
        // styled to look like it.
        expect(wrapper.findAll('table')).toHaveLength(1);
        const table = wrapper.get(`${wrap} table`);

        expect(table.get('caption').text()).toBe(TITLE);
        expect(table.findAll('tbody th[scope="row"]').map((th) => th.text())).toEqual([
            'Guest link',
            'Manual entry',
            'Offline sync',
        ]);
        // The FIRST cell of each row: a donut's table carries a share column after it.
        expect(table.findAll('tbody tr').map((row) => row.get('td').text())).toEqual(['210', '120', '44']);
        wrapper.unmount();
    });

    it.each(PLOTTED)('keeps a $chart chart’s table for assistive technology only until the reader asks to see it', async ({ chart, wrap }) => {
        // `showTable` changes what a SIGHTED reader sees and nothing about what a screen reader can reach:
        // the table is in the document either way, and only its visually-hidden class comes and goes.
        const wrapper = drawn(result({ chart }));

        expect(wrapper.get(wrap).classes()).toContain(`${wrap.slice(1)}--sr`);

        await wrapper.setProps({ showTable: true });

        expect(wrapper.get(wrap).classes()).not.toContain(`${wrap.slice(1)}--sr`);
        expect(wrapper.find(`${wrap} table`).exists()).toBe(true);
        wrapper.unmount();
    });

    it('names both series in the table of a chart split by a second field', () => {
        const wrapper = drawn(monthly('column', [80, 70, 90], [60, null, 75]));
        const table = wrapper.get('.mds-col__table');

        expect(table.findAll('thead th[scope="col"]').map((th) => th.text())).toEqual(['Submitted', 'Guest link', 'Manual entry']);
        // The un-plotted value is a dash in the table: nothing is hidden, and nothing is invented.
        expect(table.findAll('tbody tr')[1].findAll('td').map((td) => td.text())).toEqual(['70', '—']);
        wrapper.unmount();
    });
});

describe('ExploreChart — no value is not zero', () => {
    it('draws no bar for a category that has no value', () => {
        // Three smaller channels folded into "Other" have no average between them. The component-level
        // half of `toBarData`'s rule: what reaches the screen is two bars, not two and a flat one.
        const wrapper = drawn(
            result({
                measures: [{ key: 'm0', label: 'Average of Completeness (%)', aggregate: 'avg', additive: false }],
                categories: [category('guest', 'Guest link'), category('manual', 'Manual entry'), category('__other__', 'Other (3)', true)],
                series: [series('m0', 'Average of Completeness (%)', [82.5, 61, null])],
            }),
        );

        expect(wrapper.findAll('.mds-bar__row')).toHaveLength(2);
        expect(wrapper.findAll('.mds-bar__label').map((label) => label.text())).toEqual(['Guest link', 'Manual entry']);
        expect(wrapper.findAll('.mds-bar__value').map((value) => value.text())).not.toContain('0');
        wrapper.unmount();
    });

    it('says so beneath a line chart that had to draw a missing value at zero', () => {
        const wrapper = drawn(monthly('line', [80, 70, 90], [60, null, 75]));

        expect(wrapper.get('.explore-chart__note').text()).toContain('drawn at zero');
        wrapper.unmount();
    });

    it('says so beneath an area chart too', () => {
        const wrapper = drawn(monthly('area', [80, 70, 90], [60, null, 75]));

        expect(wrapper.find('.explore-chart__note').exists()).toBe(true);
        wrapper.unmount();
    });

    it('says nothing of the kind when every point is a real value, including a real zero', () => {
        const wrapper = drawn(monthly('line', [80, 0, 90], [60, 55, 75]));

        expect(wrapper.find('.explore-chart__note').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('drawn at zero');
        wrapper.unmount();
    });

    it('says nothing of the kind under a column chart, which leaves the slot empty instead', () => {
        // The sentence is true of a line only. A column chart with the same gap draws no column there,
        // so claiming a zero was drawn would be the false statement.
        const wrapper = drawn(monthly('column', [80, 70, 90], [60, null, 75]));

        expect(wrapper.find('.explore-chart__note').exists()).toBe(false);
        wrapper.unmount();
    });

    it('says the table shows the drawn zero too, which is what the table prints', () => {
        // `toLines()` substitutes the zero before the primitive sees the data, so the primitive's table
        // prints it. The note once said the table showed a dash; it now says what is true, and a chart
        // that can draw a gap is filed as `explorer-line-gap`.
        const wrapper = drawn(monthly('line', [80, 70, 90], [60, null, 75]), { showTable: true });

        const note = wrapper.get('.explore-chart__note').text();
        expect(note).toContain('the table shows that zero too');
        expect(note).not.toContain('dash');

        const february = wrapper.get('.mds-tsc__table').findAll('tbody tr')[1];
        expect(february.get('th').text()).toBe('Feb 2026');
        // Guest link's real 70, then Manual entry's substituted zero.
        expect(february.findAll('td').map((td) => td.text())).toEqual(['70', '0']);
        wrapper.unmount();
    });
});

describe('ExploreChart — an empty range', () => {
    it.each(['bar', 'line', 'donut', 'number', 'table'] as ChartType[])('says nothing matched instead of drawing an empty %s chart', (chart) => {
        // Zero rows is not a chart of zeros: an axis with nothing on it reads as "the figure was 0".
        const wrapper = drawn(result({ chart, categories: [], series: [], totals: { rows: 0, values: [0], coverage: [null] } }));

        expect(wrapper.get('.mds-empty__headline').text()).toBe('Nothing in this range');
        expect(wrapper.text()).toContain('No responses match this chart’s range and filters.');
        for (const plot of [...EVERY_PLOT, '.mds-stat-tile', 'table']) {
            expect(wrapper.find(plot).exists(), plot).toBe(false);
        }
        wrapper.unmount();
    });

    it('names the rows in the source’s own word', () => {
        const wrapper = drawn(
            result({ categories: [], series: [], totals: { rows: 0, values: [0], coverage: [null] } }, { row_noun: 'members' }),
        );

        expect(wrapper.text()).toContain('No members match');
        wrapper.unmount();
    });
});

// ── ExploreResult ───────────────────────────────────────────────────────────────────────────────────────

function outcome(props: { result: Result | null; refusal?: RunPayload['refusal']; loading?: boolean; compact?: boolean }): VueWrapper {
    return mount(ExploreResult, { props: { refusal: null, loading: false, title: TITLE, ...props } });
}

describe('ExploreResult — loading, refused, drawn', () => {
    it('shows a skeleton and marks itself busy while the first answer is on its way', () => {
        const wrapper = outcome({ result: null, loading: true });

        expect(wrapper.find('.mds-skeleton').exists()).toBe(true);
        expect(wrapper.get('.explore-result').attributes('aria-busy')).toBe('true');
        // The skeleton is `aria-hidden`; this is what a screen reader is told instead.
        expect(wrapper.text()).toContain('Loading the chart');
        expect(wrapper.find('.explore-chart').exists()).toBe(false);
        wrapper.unmount();
    });

    it('prints a refusal’s own sentence and draws no chart', () => {
        const wrapper = outcome({
            result: null,
            refusal: { reason: 'form_unavailable', message: 'The form this chart reads has been deleted, or you can no longer open it.' },
        });

        expect(wrapper.get('.mds-empty__headline').text()).toBe('This chart cannot be drawn');
        expect(wrapper.text()).toContain('has been deleted, or you can no longer open it');
        expect(wrapper.find('.explore-chart').exists()).toBe(false);
        expect(wrapper.find('.mds-skeleton').exists()).toBe(false);
        expect(wrapper.get('.explore-result').attributes('aria-busy')).toBe('false');
        wrapper.unmount();
    });

    it('states the range above a drawn chart and what it left out beneath it', () => {
        const drawnResult = result({}, { hidden_groups: 4, notes: ['2 questions marked personal or sensitive are not offered here.'] });
        const wrapper = outcome({ result: drawnResult });

        // No tile and no preview reads as an all-time total: the window, its zone and the row count are
        // always there.
        expect(wrapper.get('.explore-result__range').text()).toBe(rangeSentence(drawnResult));
        expect(wrapper.get('.explore-result__range').text()).toContain('Asia/Manila');
        expect(wrapper.find('.mds-bar').exists()).toBe(true);
        expect(wrapper.findAll('.explore-result__notes li').map((li) => li.text())).toEqual([
            '4 smaller groups are added together as “Other”.',
            '2 questions marked personal or sensitive are not offered here.',
        ]);
        wrapper.unmount();
    });

    it('renders no empty list when there is nothing to disclose', () => {
        const wrapper = outcome({ result: result() });

        expect(wrapper.find('.explore-result__notes').exists()).toBe(false);
        wrapper.unmount();
    });

    it('drops the notes on a compact tile and keeps the chart and its range', () => {
        const wrapper = outcome({ result: result({}, { hidden_groups: 4 }), compact: true });

        expect(wrapper.find('.explore-result__notes').exists()).toBe(false);
        expect(wrapper.find('.mds-bar').exists()).toBe(true);
        expect(wrapper.find('.explore-result__range').exists()).toBe(true);
        wrapper.unmount();
    });

    it('keeps the PREVIOUS chart on screen while a newer answer is loading', () => {
        // The builder re-runs on every change. Swapping the chart for a skeleton each time would make the
        // preview flash dozens of times a minute; busy is announced and the last answer stays put.
        const wrapper = outcome({ result: result(), loading: true });

        expect(wrapper.find('.mds-bar').exists()).toBe(true);
        expect(wrapper.find('.mds-skeleton').exists()).toBe(false);
        expect(wrapper.get('.explore-result').attributes('aria-busy')).toBe('true');
        wrapper.unmount();
    });

    it('passes the reader’s “show the numbers” choice down to the chart', () => {
        const wrapper = mount(ExploreResult, {
            props: { result: result(), refusal: null, loading: false, title: TITLE, showTable: true },
        });

        expect(wrapper.get('.mds-bar__table-wrap').classes()).not.toContain('mds-bar__table-wrap--sr');
        wrapper.unmount();
    });
});

// ── ExploreResultTable ──────────────────────────────────────────────────────────────────────────────────

describe('ExploreResultTable', () => {
    it('is a real table: captioned with the chart’s name, with column and row headers scoped', () => {
        const wrapper = mount(ExploreResultTable, {
            props: {
                title: TITLE,
                result: result({
                    chart: 'table',
                    series: [series('m0', 'Count of responses', [210, 120, 44]), series('m1', 'Average of Completeness (%)', [82.5, 61, null])],
                }),
            },
        });

        expect(wrapper.get('caption').text()).toBe(TITLE);
        expect(wrapper.findAll('thead th').every((th) => th.attributes('scope') === 'col')).toBe(true);
        expect(wrapper.findAll('thead th[scope="col"]').map((th) => th.text())).toEqual([
            'Channel',
            'Count of responses',
            'Average of Completeness (%)',
        ]);
        expect(wrapper.findAll('tbody th[scope="row"]').map((th) => th.text())).toEqual(['Guest link', 'Manual entry', 'Offline sync']);
        // One header cell per row and no more: a second `th` in a body row would be read as a second name.
        expect(wrapper.findAll('tbody th')).toHaveLength(3);
        expect(wrapper.findAll('tbody tr')[2].findAll('td').map((td) => td.text())).toEqual(['44', '—']);
        wrapper.unmount();
    });

    it('is a single Total row for a chart with no group-by', () => {
        const wrapper = mount(ExploreResultTable, {
            props: { title: TITLE, result: result({ chart: 'table', dimensions: [], categories: [], series: [] }) },
        });

        expect(wrapper.findAll('tbody tr')).toHaveLength(1);
        expect(wrapper.get('tbody th[scope="row"]').text()).toBe('Total');
        expect(wrapper.get('tbody td').text()).toBe('374');
        wrapper.unmount();
    });
});

// ── ExploreFilters ──────────────────────────────────────────────────────────────────────────────────────

// `ExplorerFilterOperator::cases()` as `ExplorerPresenter::builder()` sends them, in enum order.
const OPERATORS: BuilderOptions['operators'] = [
    { value: 'in', label: 'is any of', takes_values: true },
    { value: 'not_in', label: 'is none of', takes_values: true },
    { value: 'gte', label: 'is at least', takes_values: true },
    { value: 'lte', label: 'is at most', takes_values: true },
    { value: 'is_empty', label: 'is empty', takes_values: false },
    { value: 'not_empty', label: 'is not empty', takes_values: false },
];

// The range field comes FIRST on purpose: "the first filterable field" is only a claim worth testing when
// the first field is not it.
const FIELDS: Field[] = [
    { key: 'submitted_at', label: 'Submitted', type: 'time', group: 'Dates', options: null },
    {
        key: 'status',
        label: 'Status',
        type: 'category',
        group: 'Response',
        options: [
            { value: 'submitted', label: 'Submitted' },
            { value: 'approved', label: 'Approved' },
            { value: 'returned', label: 'Returned' },
        ],
    },
    { key: 'locale', label: 'Language', type: 'category', group: 'Response', options: null },
    { key: 'completeness', label: 'Completeness (%)', type: 'number', group: 'Numbers', options: null },
    { key: 'answer:services', label: 'Services received', type: 'multi_category', group: 'Questions', options: null },
    { key: 'answer:visit_date', label: 'Date of visit', type: 'date', group: 'Questions', options: null },
];

function filtersOf(filters: Filter[], props: { fields?: Field[]; max?: number } = {}): VueWrapper {
    return mount(ExploreFilters, { props: { filters, fields: FIELDS, operators: OPERATORS, max: 8, ...props } });
}

/** The list the component last emitted. It owns no state, so this is the whole of what it did. */
function emitted(wrapper: VueWrapper): Filter[] {
    const events = wrapper.emitted('change');
    expect(events, 'no change was emitted').toBeDefined();

    return events!.at(-1)![0] as Filter[];
}

function addButton(wrapper: VueWrapper) {
    return wrapper.findAll('button').find((button) => button.text() === 'Add a filter');
}

const ON_STATUS: Filter = { field: 'status', operator: 'in', values: ['approved'] };
const ON_LANGUAGE: Filter = { field: 'locale', operator: 'in', values: ['en'] };

describe('ExploreFilters — adding and removing', () => {
    it('says that nothing is filtered when the list is empty', () => {
        const wrapper = filtersOf([]);

        expect(wrapper.text()).toContain('No filters: every row in the range is counted.');
        wrapper.unmount();
    });

    it('adds a filter on the first FILTERABLE field with the first comparison that applies to it', async () => {
        const wrapper = filtersOf([ON_LANGUAGE]);

        await addButton(wrapper)!.trigger('click');

        // One longer, the existing row untouched, and not on `submitted_at` — the first field, which the
        // date range already filters.
        expect(emitted(wrapper)).toEqual([ON_LANGUAGE, { field: 'status', operator: 'in', values: [] }]);
        wrapper.unmount();
    });

    it('starts a number on “is at least”, because “is any of” does not apply to one', async () => {
        const wrapper = filtersOf([], { fields: [FIELDS[3], FIELDS[1]] });

        await addButton(wrapper)!.trigger('click');

        expect(emitted(wrapper)).toEqual([{ field: 'completeness', operator: 'gte', values: [] }]);
        wrapper.unmount();
    });

    it('removes the row whose button was pressed and no other', async () => {
        const wrapper = filtersOf([ON_STATUS, ON_LANGUAGE, { field: 'completeness', operator: 'gte', values: ['50'] }]);

        await wrapper.get('button[aria-label="Remove filter 2"]').trigger('click');

        expect(emitted(wrapper)).toEqual([ON_STATUS, { field: 'completeness', operator: 'gte', values: ['50'] }]);
        wrapper.unmount();
    });

    it('takes the add button away at the limit, so the ninth filter is never a refusal', () => {
        // `ExplorerQuery::MAX_FILTERS` arrives as `max`.
        const below = filtersOf([ON_STATUS], { max: 2 });
        const at = filtersOf([ON_STATUS, ON_LANGUAGE], { max: 2 });

        expect(addButton(below)).toBeDefined();
        expect(addButton(at)).toBeUndefined();
        below.unmount();
        at.unmount();
    });

    it('offers no add button on a source with nothing but dates to filter by', () => {
        const wrapper = filtersOf([], { fields: [FIELDS[0], FIELDS[5]] });

        expect(addButton(wrapper)).toBeUndefined();
        wrapper.unmount();
    });

    it('never offers a moment or a calendar date as the field to filter on', () => {
        const wrapper = filtersOf([ON_STATUS]);
        const offered = wrapper
            .get('select[aria-label="Filter 1: field"]')
            .findAll('option')
            .map((option) => option.attributes('value'));

        expect(offered).toEqual(['status', 'locale', 'completeness', 'answer:services']);
        expect(offered).not.toContain('submitted_at');
        expect(offered).not.toContain('answer:visit_date');
        wrapper.unmount();
    });

    it('offers each field only the comparisons the server accepts for it', () => {
        const wrapper = filtersOf([ON_STATUS, { field: 'completeness', operator: 'gte', values: ['50'] }]);
        const comparisons = (row: number): string[] =>
            wrapper
                .get(`select[aria-label="Filter ${row}: comparison"]`)
                .findAll('option')
                .map((option) => option.text());

        expect(comparisons(1)).toEqual(['is any of', 'is none of', 'is empty', 'is not empty']);
        expect(comparisons(2)).toEqual(['is at least', 'is at most', 'is empty', 'is not empty']);
        wrapper.unmount();
    });
});

describe('ExploreFilters — changing a row', () => {
    it('resets the comparison and the value when the field changes, since neither means anything on another field', async () => {
        const wrapper = filtersOf([ON_STATUS, ON_LANGUAGE]);

        await wrapper.get('select[aria-label="Filter 1: field"]').setValue('completeness');

        // "is any of: approved" on a percentage would be refused; the row restarts on the new field.
        expect(emitted(wrapper)).toEqual([{ field: 'completeness', operator: 'gte', values: [] }, ON_LANGUAGE]);
        wrapper.unmount();
    });

    it('keeps the chosen values when “is any of” becomes “is none of”', async () => {
        // The reader is inverting the filter, not starting over: making them re-tick five options is a
        // tax on the commonest change there is.
        const wrapper = filtersOf([{ field: 'status', operator: 'in', values: ['approved', 'returned'] }]);

        await wrapper.get('select[aria-label="Filter 1: comparison"]').setValue('not_in');

        expect(emitted(wrapper)).toEqual([{ field: 'status', operator: 'not_in', values: ['approved', 'returned'] }]);
        wrapper.unmount();
    });

    it('keeps the number when “is at least” becomes “is at most”', async () => {
        const wrapper = filtersOf([{ field: 'completeness', operator: 'gte', values: ['50'] }]);

        await wrapper.get('select[aria-label="Filter 1: comparison"]').setValue('lte');

        expect(emitted(wrapper)).toEqual([{ field: 'completeness', operator: 'lte', values: ['50'] }]);
        wrapper.unmount();
    });

    it('clears the values when the comparison becomes “is empty”, which takes none', async () => {
        // Left behind, they would be sent — and come back from a saved chart as a filter that says
        // "is empty" while carrying a list.
        const wrapper = filtersOf([ON_STATUS]);

        await wrapper.get('select[aria-label="Filter 1: comparison"]').setValue('is_empty');

        expect(emitted(wrapper)).toEqual([{ field: 'status', operator: 'is_empty', values: [] }]);
        wrapper.unmount();
    });

    it('shows no value control at all for a comparison that takes none', () => {
        const wrapper = filtersOf([{ field: 'status', operator: 'not_empty', values: [] }]);

        expect(wrapper.find('[role="group"]').exists()).toBe(false);
        expect(wrapper.find('input').exists()).toBe(false);
        wrapper.unmount();
    });
});

describe('ExploreFilters — the three kinds of value', () => {
    it('offers a pick-list where the server knows the field’s values, ticked to match the filter', () => {
        const wrapper = filtersOf([ON_STATUS]);
        const boxes = wrapper.get('[role="group"][aria-label="Filter 1: values"]').findAll('input[type="checkbox"]');

        expect(wrapper.findAll('.mds-checkbox__label').map((label) => label.text())).toEqual(['Submitted', 'Approved', 'Returned']);
        expect(boxes.map((box) => (box.element as HTMLInputElement).checked)).toEqual([false, true, false]);
        wrapper.unmount();
    });

    it('adds an option’s value when it is ticked', async () => {
        const wrapper = filtersOf([ON_STATUS]);
        const boxes = wrapper.findAll('input[type="checkbox"]');

        await boxes[2].setValue(true);

        expect(emitted(wrapper)).toEqual([{ field: 'status', operator: 'in', values: ['approved', 'returned'] }]);
        wrapper.unmount();
    });

    it('removes an option’s value when it is unticked', async () => {
        const wrapper = filtersOf([{ field: 'status', operator: 'in', values: ['submitted', 'approved'] }]);
        const boxes = wrapper.findAll('input[type="checkbox"]');

        await boxes[0].setValue(false);

        expect(emitted(wrapper)).toEqual([{ field: 'status', operator: 'in', values: ['approved'] }]);
        wrapper.unmount();
    });

    it('never lists a value twice, however the tick arrives', async () => {
        // A box the browser reports as ticked while the model already holds its value.
        const wrapper = filtersOf([ON_STATUS]);

        // Found by its label, not by position: the fixture's option order is not what this case is about.
        const approved = wrapper.findAllComponents(MdsCheckbox).find((box) => box.props('label') === 'Approved');
        expect(approved, 'no checkbox labelled Approved').toBeDefined();
        approved?.vm.$emit('update:modelValue', true);
        await wrapper.vm.$nextTick();

        expect(emitted(wrapper)[0].values).toEqual(['approved']);
        wrapper.unmount();
    });

    it('reads free text as a comma-separated list, trimmed, with the empty pieces dropped', async () => {
        const wrapper = filtersOf([{ field: 'locale', operator: 'in', values: [] }]);

        await wrapper.get('input[aria-label="Filter 1: values"]').setValue('north, south ,, ');

        expect(emitted(wrapper)).toEqual([{ field: 'locale', operator: 'in', values: ['north', 'south'] }]);
        wrapper.unmount();
    });

    it('shows the values of a free-text filter back as the list the reader typed', () => {
        const wrapper = filtersOf([{ field: 'locale', operator: 'in', values: ['en', 'fil'] }]);

        expect((wrapper.get('input[aria-label="Filter 1: values"]').element as HTMLInputElement).value).toBe('en, fil');
        wrapper.unmount();
    });

    it('sends a number as ONE string value, which is the shape the server reads', async () => {
        const wrapper = filtersOf([{ field: 'completeness', operator: 'gte', values: [] }]);
        const input = wrapper.get('input[type="number"][aria-label="Filter 1: value"]');

        await input.setValue('50');

        expect(emitted(wrapper)).toEqual([{ field: 'completeness', operator: 'gte', values: ['50'] }]);
        wrapper.unmount();
    });

    it('sends no value at all when the number is cleared, rather than "0" or "null"', async () => {
        const wrapper = filtersOf([{ field: 'completeness', operator: 'gte', values: ['50'] }]);

        await wrapper.get('input[type="number"]').setValue('');

        expect(emitted(wrapper)).toEqual([{ field: 'completeness', operator: 'gte', values: [] }]);
        wrapper.unmount();
    });

    it('changes only the row that was edited', async () => {
        const wrapper = filtersOf([ON_STATUS, { field: 'locale', operator: 'in', values: ['en'] }]);

        await wrapper.get('input[aria-label="Filter 2: values"]').setValue('fil');

        expect(emitted(wrapper)).toEqual([ON_STATUS, { field: 'locale', operator: 'in', values: ['fil'] }]);
        wrapper.unmount();
    });
});

// ── ExploreTile ─────────────────────────────────────────────────────────────────────────────────────────

function tileOf(chart: SavedChart): VueWrapper {
    return mount(ExploreTile, { props: { chart } });
}

function badges(wrapper: VueWrapper): string[] {
    return wrapper.findAll('.mds-badge').map((badge) => badge.text());
}

describe('ExploreTile', () => {
    it('fetches its own result, by its chart’s id, once, when it mounts', async () => {
        mocks.fetchChartResult.mockResolvedValue({ result: result(), refusal: null });

        const wrapper = tileOf(savedChart({ id: 'c7' }));
        await flushPromises();

        expect(mocks.fetchChartResult).toHaveBeenCalledTimes(1);
        expect(mocks.fetchChartResult).toHaveBeenCalledWith('c7');
        wrapper.unmount();
    });

    it('paints at once, with a skeleton, and draws the chart when the answer arrives', async () => {
        // The board holds declarations and no data, so it can paint before any chart has run.
        mocks.fetchChartResult.mockResolvedValue({ result: result(), refusal: null });

        const wrapper = tileOf(savedChart());

        expect(wrapper.find('.mds-skeleton').exists()).toBe(true);
        expect(wrapper.text()).toContain(TITLE);

        await flushPromises();

        expect(wrapper.find('.mds-skeleton').exists()).toBe(false);
        expect(wrapper.find('.mds-bar').exists()).toBe(true);
        expect(wrapper.get('.explore-result').attributes('aria-busy')).toBe('false');
        wrapper.unmount();
    });

    it('names the chart as a link to the builder for that chart', async () => {
        mocks.fetchChartResult.mockResolvedValue({ result: result(), refusal: null });

        const wrapper = tileOf(savedChart({ id: 'c7' }));
        await flushPromises();

        const link = wrapper.get('h2 a');
        expect(link.text()).toBe(TITLE);
        expect(link.attributes('href')).toBe('/explore/charts/c7');
        wrapper.unmount();
    });

    it('badges the reader’s OWN chart “Shared” only when they have shared it', async () => {
        mocks.fetchChartResult.mockResolvedValue({ result: result(), refusal: null });

        const shared = tileOf(savedChart({ is_shared: true }));
        const kept = tileOf(savedChart({ is_shared: false }));
        await flushPromises();

        expect(badges(shared)).toEqual(['Shared']);
        expect(badges(kept)).toEqual([]);
        shared.unmount();
        kept.unmount();
    });

    it('badges a colleague’s chart with their name, and not with “Shared”', async () => {
        // Every chart on the board that is not the reader's own is shared by definition; saying so
        // would be noise, and who to ask about it is not.
        mocks.fetchChartResult.mockResolvedValue({ result: result(), refusal: null });

        const wrapper = tileOf(savedChart({ is_mine: false, is_shared: true, owner: 'Ana Reyes', can: { update: false, delete: false } }));
        await flushPromises();

        expect(badges(wrapper)).toEqual(['By Ana Reyes']);
        wrapper.unmount();
    });

    it('shows the refusal’s sentence where the chart would have been', async () => {
        // A chart shared over data its viewer may not read shows that viewer a sentence — and the tile is
        // still named and still a link, so the rest of the board is unaffected.
        mocks.fetchChartResult.mockResolvedValue({
            result: null,
            refusal: { reason: 'source_forbidden', message: 'You do not have access to members, which this chart reads.' },
        });

        const wrapper = tileOf(savedChart({ is_mine: false, owner: 'Ana Reyes' }));
        await flushPromises();

        expect(wrapper.text()).toContain('You do not have access to members, which this chart reads.');
        expect(wrapper.find('.explore-chart').exists()).toBe(false);
        expect(wrapper.find('.mds-skeleton').exists()).toBe(false);
        expect(wrapper.get('h2 a').text()).toBe(TITLE);
        wrapper.unmount();
    });

    it('keeps a tile’s notes off the board, where the builder is one click away', async () => {
        mocks.fetchChartResult.mockResolvedValue({ result: result({}, { hidden_groups: 4 }), refusal: null });

        const wrapper = tileOf(savedChart());
        await flushPromises();

        expect(wrapper.find('.explore-result__notes').exists()).toBe(false);
        expect(wrapper.find('.explore-result__range').exists()).toBe(true);
        wrapper.unmount();
    });
});

// ── ExploreSaveModal ────────────────────────────────────────────────────────────────────────────────────

function modalOf(props: { mode: 'create' | 'copy' | 'details'; chart?: SavedChart | null; definition?: Definition }): VueWrapper {
    return mount(ExploreSaveModal, {
        props: { open: true, chart: null, definition: definition(), ...props },
        // MdsModal teleports to <body>; without this the wrapper sees nothing at all.
        global: { stubs: { teleport: true } },
    });
}

function nameInput(wrapper: VueWrapper): HTMLInputElement {
    return wrapper.get('#explore-save-name').element as HTMLInputElement;
}

function sharing(wrapper: VueWrapper): HTMLInputElement {
    return wrapper.get('input[role="switch"]').element as HTMLInputElement;
}

describe('ExploreSaveModal — a new chart', () => {
    it('posts the name, the switch and the declaration on screen, without the version key', async () => {
        // `v` on the way in — a declaration that started life as a saved chart's — and never on the way
        // out: the server is the single author of the stored shape's version.
        const onScreen: Definition = { v: 1, ...definition({ chart: 'donut', limit: 5 }) };
        const wrapper = modalOf({ mode: 'create', definition: onScreen });

        await wrapper.get('#explore-save-name').setValue('Responses by channel, last 90 days');
        await wrapper.get('input[role="switch"]').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(mocks.sent.method).toBe('post');
        expect(mocks.sent.url).toBe('/explore/charts');
        expect(mocks.sent.payload).toEqual({
            name: 'Responses by channel, last 90 days',
            is_shared: true,
            definition: definition({ chart: 'donut', limit: 5 }),
        });
        expect(mocks.sent.payload!.definition).not.toHaveProperty('v');
        wrapper.unmount();
    });

    it('starts blank and unshared, under its own title', () => {
        const wrapper = modalOf({ mode: 'create' });

        expect(wrapper.get('.mds-modal__title').text()).toBe('Save this chart');
        expect(nameInput(wrapper).value).toBe('');
        expect(sharing(wrapper).checked).toBe(false);
        wrapper.unmount();
    });

    it('will not submit a blank name, and a name of spaces is blank', async () => {
        const wrapper = modalOf({ mode: 'create' });
        const submit = wrapper.get('button[type="submit"]');

        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.get('#explore-save-name').setValue('   ');
        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.get('#explore-save-name').setValue('Responses by channel');
        expect(submit.attributes('disabled')).toBeUndefined();
        wrapper.unmount();
    });
});

describe('ExploreSaveModal — name and sharing of a saved chart', () => {
    const SAVED = savedChart({ id: 'c7', name: 'Field team intake', is_shared: true });

    it('opens on the chart’s own name and its own sharing', () => {
        const wrapper = modalOf({ mode: 'details', chart: SAVED });

        expect(wrapper.get('.mds-modal__title').text()).toBe('Name and sharing');
        expect(nameInput(wrapper).value).toBe('Field team intake');
        expect(sharing(wrapper).checked).toBe(true);
        wrapper.unmount();
    });

    it('PATCHes the chart with the name and the switch and NOTHING else — never the declaration', async () => {
        // ⭐ THE CASE THIS MODAL'S THREE MODES EXIST FOR. The declaration handed to the modal here is NOT
        // the one the chart stores: the reader has changed the chart type and added a filter and has not
        // pressed "Save changes". Renaming must not save that for them.
        const twiddled = definition({ chart: 'donut', filters: [{ field: 'status', operator: 'in', values: ['approved'] }] });
        const wrapper = modalOf({ mode: 'details', chart: SAVED, definition: twiddled });

        await wrapper.get('#explore-save-name').setValue('Field team intake (Q3)');
        await wrapper.get('input[role="switch"]').setValue(false);
        await wrapper.get('form').trigger('submit');

        expect(mocks.sent.method).toBe('patch');
        expect(mocks.sent.url).toBe('/explore/charts/c7');
        expect(mocks.sent.payload).not.toHaveProperty('definition');
        expect(Object.keys(mocks.sent.payload!).sort()).toEqual(['is_shared', 'name']);
        expect(mocks.sent.payload).toEqual({ name: 'Field team intake (Q3)', is_shared: false });
        wrapper.unmount();
    });
});

describe('ExploreSaveModal — a copy of a colleague’s chart', () => {
    const THEIRS = savedChart({ id: 'c9', name: 'Field team intake', is_shared: true, is_mine: false, owner: 'Ana Reyes' });

    it('opens named “<name> (copy)” and UNSHARED, whatever the original was', () => {
        // A copy of a shared chart that began shared would put a second, near-identical tile on every
        // colleague's board the moment the reader pressed Save.
        const wrapper = modalOf({ mode: 'copy', chart: THEIRS });

        expect(wrapper.get('.mds-modal__title').text()).toBe('Save a copy');
        expect(nameInput(wrapper).value).toBe('Field team intake (copy)');
        expect(sharing(wrapper).checked).toBe(false);
        wrapper.unmount();
    });

    it('POSTs a new chart holding the declaration on screen, and does not touch the original', async () => {
        const changed = definition({ chart: 'column' });
        const wrapper = modalOf({ mode: 'copy', chart: THEIRS, definition: changed });

        await wrapper.get('form').trigger('submit');

        expect(mocks.sent.method).toBe('post');
        // Not `/explore/charts/c9`: the original is its author's.
        expect(mocks.sent.url).toBe('/explore/charts');
        expect(mocks.sent.payload).toEqual({ name: 'Field team intake (copy)', is_shared: false, definition: changed });
        wrapper.unmount();
    });
});
