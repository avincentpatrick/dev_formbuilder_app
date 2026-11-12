import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toPayload } from '@/components/explore/definition';
import type {
    BuilderOptions,
    Definition,
    Field,
    FieldsPayload,
    Option,
    Result,
    RunPayload,
    SavedChart,
    Source,
} from '@/components/explore/types';

/**
 * M235 — the /explore chart builder (`Chart.vue`, formerly `Builder.vue`).
 *
 * The page computes nothing; what it owns is WHEN it asks and WHICH answer it keeps. So the cases below are
 * about the wire: how many `runQuery` calls a burst of edits produces, which declaration each carries, and
 * — the one that matters most — that an answer arriving late to a question nobody is asking any more never
 * reaches the screen. A chart showing the answer to the previous question looks exactly like a right one.
 *
 * `exploreClient` is mocked whole: each case decides when every answer arrives. Timers are fake for the
 * 250 ms debounce, and `Date` is pinned so a new chart's default range is a known pair of days.
 */

const mocks = vi.hoisted(() => ({
    runQuery: vi.fn<(definition: Definition) => Promise<RunPayload>>(),
    fetchFields: vi.fn<(source: string, formId: string) => Promise<{ payload: FieldsPayload | null; message: string | null }>>(),
    patch: vi.fn<(url: string, data: Record<string, unknown>, options: Record<string, unknown>) => void>(),
    del: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { patch: mocks.patch, delete: mocks.del },
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', props: ['title', 'icon'], template: '<header><h1>{{ title }}</h1><slot name="actions" /></header>' },
}));

vi.mock('@/components/explore/exploreClient', () => ({
    runQuery: mocks.runQuery,
    fetchFields: mocks.fetchFields,
    fetchChartResult: vi.fn(),
}));

// Both have their own spec (explore-components.test.ts); here they would only add a useForm to mock.
vi.mock('@/components/explore/ExploreSaveModal.vue', () => ({
    default: { name: 'ExploreSaveModal', props: ['open', 'mode', 'chart', 'definition'], template: '<div class="save-modal-stub" />' },
}));

vi.mock('@/components/explore/ExploreFilters.vue', () => ({
    default: { name: 'ExploreFilters', props: ['filters', 'fields', 'operators', 'max'], template: '<div class="filters-stub" />' },
}));

// Imported AFTER the mocks so the component resolves them.
const ChartPage = (await import('./Chart.vue')).default;

const TZ = 'Asia/Manila';

// ── Fixtures ────────────────────────────────────────────────────────────────────────────────────────────

function field(key: string, label: string, type: Field['type'], group = ''): Field {
    return { key, label, type, group, options: null };
}

const RESPONSES: Source = {
    key: 'responses',
    label: 'Responses',
    description: 'Every response submitted to a form.',
    row_noun: 'responses',
    requires_form: false,
    default_range_days: 30,
    range_field: 'submitted_at',
    fields: [
        field('channel', 'Channel', 'category'),
        field('status', 'Status', 'category'),
        field('submitted_at', 'Submitted', 'time'),
        field('completeness', 'Completeness (%)', 'number'),
    ],
};

const ANSWERS: Source = {
    key: 'answers',
    label: 'Answers',
    description: 'The answers to one form’s questions.',
    row_noun: 'responses',
    requires_form: true,
    default_range_days: 30,
    range_field: 'submitted_at',
    fields: [field('submitted_at', 'Submitted', 'time'), field('channel', 'Channel', 'category')],
};

const FORMS: Option[] = [
    { value: 'f1', label: 'Household visit' },
    { value: 'f2', label: 'Clinic intake' },
];

/** What `/explore/fields` answers per form: the common fields, then that form's own questions. */
const FORM_FIELDS: Record<string, Field[]> = {
    f1: [...ANSWERS.fields, field('q_district', 'District', 'category', 'Questions'), field('q_visit', 'Visit date', 'date', 'Questions')],
    f2: [...ANSWERS.fields, field('q_age', 'Age', 'number', 'Questions')],
};

function chartOption(value: BuilderOptions['charts'][number]['value'], label: string, min: number, max: number, measures: number) {
    return { value, label, min_dimensions: min, max_dimensions: max, max_measures: measures, max_measures_split: 1 };
}

const OPTIONS: BuilderOptions = {
    charts: [
        chartOption('bar', 'Bar', 1, 2, 3),
        chartOption('column', 'Column', 1, 2, 3),
        chartOption('line', 'Line', 1, 2, 3),
        chartOption('donut', 'Donut', 1, 1, 1),
        chartOption('table', 'Table', 0, 2, 5),
        chartOption('number', 'Number', 0, 0, 1),
    ],
    aggregates: [
        { value: 'count', label: 'Count', needs_field: false },
        { value: 'sum', label: 'Sum', needs_field: true },
        { value: 'avg', label: 'Average', needs_field: true },
    ],
    buckets: [
        { value: 'day', label: 'Day' },
        { value: 'week', label: 'Week' },
        { value: 'month', label: 'Month' },
        { value: 'hour', label: 'Hour of day' },
    ],
    operators: [
        { value: 'in', label: 'is any of', takes_values: true },
        { value: 'is_empty', label: 'is empty', takes_values: false },
    ],
    sorts: [
        { value: 'value_desc', label: 'Largest first' },
        { value: 'value_asc', label: 'Smallest first' },
        { value: 'label', label: 'A to Z' },
    ],
    timezones: ['UTC', TZ],
    limits: { max_dimensions: 2, max_measures: 3, max_filters: 5, max_range_days: 366, max_limit: 50, default_limit: 10 },
};

/** The zone `Chart.vue` picks for a new chart on THIS runner: its own when offered, UTC otherwise. */
const NEW_CHART_ZONE = OPTIONS.timezones.includes(Intl.DateTimeFormat().resolvedOptions().timeZone)
    ? Intl.DateTimeFormat().resolvedOptions().timeZone
    : 'UTC';

/** A one-group-by row count whose category labels name the answer, so a case can tell two apart. */
function result(...labels: string[]): Result {
    return {
        chart: 'bar',
        dimensions: [{ key: 'channel', label: 'Channel', type: 'category', bucket: null }],
        measures: [{ key: 'm0', label: 'Count of responses', aggregate: 'count', additive: true }],
        categories: labels.map((label, i) => ({ key: `k${i}`, label, other: false })),
        series: [{ key: 'm0', label: 'Count of responses', other: false, values: labels.map((_, i) => 10 * (i + 1)) }],
        totals: { rows: labels.reduce((sum, _, i) => sum + 10 * (i + 1), 0), values: [null], coverage: [null] },
        meta: {
            source: 'responses',
            source_label: 'Responses',
            row_noun: 'responses',
            form: null,
            from: '2026-03-02',
            to: '2026-03-31',
            timezone: TZ,
            range_label: 'Submitted',
            groups: labels.length,
            hidden_groups: 0,
            hidden_series: 0,
            capped: false,
            notes: [],
        },
    };
}

function drawn(...labels: string[]): RunPayload {
    return { result: result(...labels), refusal: null };
}

/**
 * A saved declaration in the key order a `jsonb` column hands it back — NOT the order the builder writes.
 * That difference is the whole reason `sameDefinition()` exists, so the fixture carries it on purpose.
 */
function storedDefinition(overrides: Partial<Definition> = {}): Definition {
    return {
        v: 1,
        chart: 'bar',
        dimensions: [{ bucket: null, field: 'channel' }],
        filters: [],
        form_id: null,
        from: '2026-03-01',
        limit: 10,
        measures: [{ field: null, aggregate: 'count' }],
        sort: 'value_desc',
        source: 'responses',
        timezone: TZ,
        to: '2026-03-31',
        ...overrides,
    };
}

function saved(overrides: Partial<SavedChart> = {}): SavedChart {
    return {
        id: 'c1',
        name: 'Responses by channel',
        definition: storedDefinition(),
        is_shared: false,
        is_mine: true,
        owner: null,
        source_label: 'Responses',
        can: { update: true, delete: true },
        updated_at: '2026-03-31T09:00:00+08:00',
        ...overrides,
    };
}

function render(props: { chart?: SavedChart | null; sources?: Source[]; forms?: Option[] } = {}): VueWrapper {
    return mount(ChartPage, {
        props: { chart: null, sources: [RESPONSES, ANSWERS], forms: FORMS, options: OPTIONS, ...props },
    });
}

/** A promise this case resolves by hand, so it decides which answer arrives first. */
function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });

    return { promise, resolve };
}

function button(wrapper: VueWrapper, label: string) {
    return wrapper.findAll('button').find((b) => b.text() === label);
}

function lastQuery(): Definition {
    const call = mocks.runQuery.mock.calls.at(-1);
    expect(call, 'runQuery was never called').toBeDefined();

    return call![0];
}

function optionLabels(wrapper: VueWrapper, selector: string): string[] {
    return wrapper.find(selector).findAll('option').map((o) => o.text());
}

/** Let the debounce elapse, then let the answer land. */
async function settle(): Promise<void> {
    vi.advanceTimersByTime(250);
    await flushPromises();
}

beforeEach(() => {
    // Only the clock the page uses: `flushPromises` schedules on setImmediate, which must stay real.
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
    vi.setSystemTime(new Date(2026, 2, 31, 12, 0, 0));
    mocks.runQuery.mockReset();
    mocks.fetchFields.mockReset();
    mocks.patch.mockReset();
    mocks.del.mockReset();
    mocks.runQuery.mockResolvedValue(drawn('Guest link', 'Manual entry'));
    mocks.fetchFields.mockImplementation((_source, formId) =>
        Promise.resolve({ payload: { fields: FORM_FIELDS[formId] ?? [], notes: [] }, message: null }),
    );
});

afterEach(() => {
    vi.useRealTimers();
});

// ── Running and drawing ─────────────────────────────────────────────────────────────────────────────────

describe('Chart builder — running the declaration', () => {
    it('runs the default declaration once on mount, without waiting for the debounce, and draws it', async () => {
        const wrapper = render();
        await flushPromises();

        expect(mocks.runQuery).toHaveBeenCalledTimes(1);
        expect(lastQuery()).toEqual({
            source: 'responses',
            form_id: null,
            chart: 'bar',
            dimensions: [{ field: 'channel', bucket: null }],
            measures: [{ aggregate: 'count', field: null }],
            filters: [],
            from: '2026-03-02',
            to: '2026-03-31',
            timezone: NEW_CHART_ZONE,
            sort: 'value_desc',
            limit: 10,
        });
        expect(wrapper.text()).toContain('Guest link');
        expect(wrapper.text()).toContain('Manual entry');

        // And nothing else is queued behind it.
        vi.advanceTimersByTime(1000);
        await flushPromises();
        expect(mocks.runQuery).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('turns several quick changes into exactly ONE further run, carrying the final declaration', async () => {
        const wrapper = render();
        await flushPromises();
        expect(mocks.runQuery).toHaveBeenCalledTimes(1);

        await wrapper.find('#explore-sort').setValue('label');
        vi.advanceTimersByTime(100);
        await wrapper.find('#explore-limit').setValue(7);
        vi.advanceTimersByTime(100);
        await wrapper.find('#explore-limit').setValue(4);

        // 249 ms after the LAST change: still nothing sent. The timer restarts on every change.
        vi.advanceTimersByTime(249);
        await flushPromises();
        expect(mocks.runQuery).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(1);
        await flushPromises();
        expect(mocks.runQuery).toHaveBeenCalledTimes(2);
        expect(lastQuery()).toMatchObject({ sort: 'label', limit: 4 });
        wrapper.unmount();
    });

    it('drops a stale answer: the FIRST request answering AFTER the second never reaches the screen', async () => {
        const wrapper = render();
        await flushPromises();

        const first = deferred<RunPayload>();
        const second = deferred<RunPayload>();
        mocks.runQuery.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);

        await wrapper.find('#explore-sort').setValue('value_asc');
        vi.advanceTimersByTime(250);
        await wrapper.find('#explore-sort').setValue('label');
        vi.advanceTimersByTime(250);
        await flushPromises();

        expect(mocks.runQuery).toHaveBeenCalledTimes(3);
        expect(mocks.runQuery.mock.calls[1][0].sort).toBe('value_asc');
        expect(mocks.runQuery.mock.calls[2][0].sort).toBe('label');

        // The newer question is answered first…
        second.resolve(drawn('Answer to the second question'));
        await flushPromises();
        expect(wrapper.text()).toContain('Answer to the second question');

        // …and the older one arrives late. It must not overwrite what the controls are asking now.
        first.resolve(drawn('Stale answer to the first question'));
        await flushPromises();
        expect(wrapper.text()).toContain('Answer to the second question');
        expect(wrapper.text()).not.toContain('Stale answer to the first question');
        expect(wrapper.find('.explore-result').attributes('aria-busy')).toBe('false');
        wrapper.unmount();
    });

    it('does not run an answers chart until a form is chosen, and says so instead', async () => {
        const wrapper = render({ sources: [ANSWERS, RESPONSES] });
        await flushPromises();
        await settle();

        expect(wrapper.text()).toContain('Choose the form whose answers to chart.');
        expect(mocks.runQuery).not.toHaveBeenCalled();
        expect(mocks.fetchFields).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('shows the same prompt, and sends nothing, on switching an open chart to the answers source', async () => {
        const wrapper = render();
        await flushPromises();
        expect(mocks.runQuery).toHaveBeenCalledTimes(1);

        await wrapper.find('#explore-source').setValue('answers');
        await settle();

        expect(wrapper.text()).toContain('Choose the form whose answers to chart.');
        expect(mocks.runQuery).toHaveBeenCalledTimes(1);
        // The previous source's chart is gone, not left under a prompt that contradicts it.
        expect(wrapper.text()).not.toContain('Guest link');
        wrapper.unmount();
    });

    it('a saved chart over a source this reader is not offered is still sent, and shows the server’s refusal', async () => {
        mocks.runQuery.mockResolvedValue({
            result: null,
            refusal: { reason: 'source_unavailable', message: 'This data source is not available to you.' },
        });
        const wrapper = render({
            chart: saved({ definition: storedDefinition({ source: 'retired_source' }) }),
            sources: [RESPONSES],
        });
        await flushPromises();

        expect(mocks.runQuery).toHaveBeenCalledTimes(1);
        expect(lastQuery().source).toBe('retired_source');
        expect(wrapper.text()).toContain('This chart cannot be drawn');
        expect(wrapper.text()).toContain('This data source is not available to you.');
        // "Choose a data source" would not be true: the chart HAS one, the reader just cannot read it.
        expect(wrapper.text()).not.toContain('Choose a data source.');
        wrapper.unmount();
    });
});

// ── The form under an answers chart ─────────────────────────────────────────────────────────────────────

describe('Chart builder — choosing a form', () => {
    it('fetches the form’s questions, offers them, and drops a group-by the next form lacks', async () => {
        const wrapper = render({ sources: [ANSWERS, RESPONSES] });
        await flushPromises();

        await wrapper.find('#explore-form').setValue('f1');
        await flushPromises();

        expect(mocks.fetchFields).toHaveBeenCalledWith('answers', 'f1');
        expect(optionLabels(wrapper, '#explore-group')).toContain('District');
        expect(wrapper.find('#explore-group optgroup[label="Questions"]').exists()).toBe(true);

        await settle();
        expect(mocks.runQuery).toHaveBeenCalledTimes(1);
        expect(lastQuery()).toMatchObject({ source: 'answers', form_id: 'f1' });

        await wrapper.find('#explore-group').setValue('q_district');
        await settle();
        expect(lastQuery().dimensions).toEqual([{ field: 'q_district', bucket: null }]);

        // A form without a District question: the group-by naming it goes, and is not sent.
        await wrapper.find('#explore-form').setValue('f2');
        await flushPromises();

        expect(mocks.fetchFields).toHaveBeenLastCalledWith('answers', 'f2');
        expect(optionLabels(wrapper, '#explore-group')).not.toContain('District');
        expect((wrapper.find('#explore-group').element as HTMLSelectElement).value).toBe('');

        await settle();
        expect(lastQuery().form_id).toBe('f2');
        expect(lastQuery().dimensions.map((d) => d.field)).not.toContain('q_district');
        wrapper.unmount();
    });
});

// ── Chart type and the controls it allows ───────────────────────────────────────────────────────────────

describe('Chart builder — what each chart type allows', () => {
    it('a number chart has no group-by controls, and sends no group-by', async () => {
        const wrapper = render();
        await flushPromises();
        expect(wrapper.find('#explore-group').exists()).toBe(true);

        await wrapper.find('#explore-chart').setValue('number');

        expect(wrapper.find('#explore-group').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Group by');
        expect(wrapper.find('#explore-limit').exists()).toBe(false);

        await settle();
        expect(lastQuery()).toMatchObject({ chart: 'number', dimensions: [] });
        wrapper.unmount();
    });

    it('a donut caps the groups shown at 5, both on switching and on typing more', async () => {
        const wrapper = render();
        await flushPromises();
        expect((wrapper.find('#explore-limit').element as HTMLInputElement).value).toBe('10');

        await wrapper.find('#explore-chart').setValue('donut');

        const limit = wrapper.find('#explore-limit');
        expect((limit.element as HTMLInputElement).value).toBe('5');
        expect(limit.attributes('max')).toBe('5');

        await settle();
        expect(lastQuery()).toMatchObject({ chart: 'donut', limit: 5 });

        await wrapper.find('#explore-limit').setValue(9);
        await settle();
        expect(lastQuery().limit).toBe(5);
        wrapper.unmount();
    });

    it('a date group-by asks how to group the dates, starting at month', async () => {
        const wrapper = render();
        await flushPromises();
        expect(wrapper.text()).not.toContain('Group dates by');

        await wrapper.find('#explore-group').setValue('submitted_at');

        expect(wrapper.text()).toContain('Group dates by');
        expect((wrapper.find('#explore-bucket').element as HTMLSelectElement).value).toBe('month');

        await settle();
        expect(lastQuery().dimensions).toEqual([{ field: 'submitted_at', bucket: 'month' }]);
        wrapper.unmount();
    });
});

// ── Saving ──────────────────────────────────────────────────────────────────────────────────────────────

describe('Chart builder — saving', () => {
    it('a new chart offers "Save chart", disabled until there is a result to save', async () => {
        const answer = deferred<RunPayload>();
        mocks.runQuery.mockReturnValueOnce(answer.promise);

        const wrapper = render();
        await flushPromises();

        expect(button(wrapper, 'Save chart')?.attributes('disabled')).toBeDefined();
        expect(button(wrapper, 'Save changes')).toBeUndefined();
        expect(button(wrapper, 'Delete')).toBeUndefined();

        answer.resolve(drawn('Guest link'));
        await flushPromises();

        expect(button(wrapper, 'Save chart')?.attributes('disabled')).toBeUndefined();
        wrapper.unmount();
    });

    it('the reader’s own chart: "Save changes" is disabled until the declaration actually differs', async () => {
        const wrapper = render({ chart: saved() });
        await flushPromises();

        expect(button(wrapper, 'Name and sharing')).toBeDefined();
        expect(button(wrapper, 'Delete')).toBeDefined();
        expect(button(wrapper, 'Save a copy')).toBeUndefined();
        expect(button(wrapper, 'Save changes')?.attributes('disabled')).toBeDefined();

        await wrapper.find('#explore-sort').setValue('label');
        expect(button(wrapper, 'Save changes')?.attributes('disabled')).toBeUndefined();

        await wrapper.find('#explore-sort').setValue('value_desc');
        expect(button(wrapper, 'Save changes')?.attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('…and stays disabled when the declaration differs from the stored one only in key ORDER', async () => {
        const chart = saved();
        const wrapper = render({ chart });
        await flushPromises();

        // Away and back: the builder rewrites the group-by as `{field, bucket}`; the store holds `{bucket, field}`.
        await wrapper.find('#explore-group').setValue('status');
        expect(button(wrapper, 'Save changes')?.attributes('disabled')).toBeUndefined();
        await wrapper.find('#explore-group').setValue('channel');
        await settle();

        // The precondition, so this case cannot pass by testing nothing: the two really differ as text.
        expect(JSON.stringify(lastQuery())).not.toBe(JSON.stringify(toPayload(chart.definition)));
        expect(lastQuery()).toEqual(toPayload(chart.definition));

        expect(button(wrapper, 'Save changes')?.attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('someone else’s shared chart offers "Save a copy" and neither Delete nor "Save changes"', async () => {
        const wrapper = render({
            chart: saved({ is_mine: false, is_shared: true, owner: 'Ana Reyes', can: { update: false, delete: false } }),
        });
        await flushPromises();

        expect(button(wrapper, 'Save a copy')).toBeDefined();
        expect(button(wrapper, 'Save a copy')?.attributes('disabled')).toBeUndefined();
        expect(button(wrapper, 'Delete')).toBeUndefined();
        expect(button(wrapper, 'Save changes')).toBeUndefined();
        expect(button(wrapper, 'Name and sharing')).toBeUndefined();
        expect(wrapper.text()).toContain('Shared by Ana Reyes');
        wrapper.unmount();
    });

    it('"Save changes" patches the chart with the declaration alone — no `v`, no `name`', async () => {
        const wrapper = render({ chart: saved() });
        await flushPromises();

        await wrapper.find('#explore-sort').setValue('label');
        await button(wrapper, 'Save changes')!.trigger('click');

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        const [url, body] = mocks.patch.mock.calls[0];
        expect(url).toBe('/explore/charts/c1');
        expect(Object.keys(body)).toEqual(['definition']);
        expect(body).not.toHaveProperty('name');
        // `v` is the server's to write; a client echo is how a stored shape comes to carry a version nothing wrote.
        expect(body.definition).not.toHaveProperty('v');
        expect(body.definition).toEqual(toPayload({ ...storedDefinition(), sort: 'label' }));
        wrapper.unmount();
    });
});
