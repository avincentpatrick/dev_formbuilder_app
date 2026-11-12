import { afterEach, describe, expect, it } from 'vitest';
import {
    dropUnknownFields,
    fieldGroups,
    fieldsForAggregate,
    fitToChart,
    incompleteReason,
    isFilterable,
    isGroupable,
    isoDate,
    isTemporal,
    newDefinition,
    operatorApplies,
    rangeEnding,
    sameDefinition,
    spanInDays,
    toPayload,
} from './definition';
import type { Aggregate, ChartOption, ChartType, Definition, Field, FieldType, FilterOperator, Source } from './types';

/**
 * M235 — the pure helpers under the chart builder.
 *
 * The server is the authority on what a declaration may hold; these helpers exist so the builder never SENDS
 * one the server would refuse for a reason the reader did not cause. Three of them are mirrors of PHP and
 * are pinned here as tables for that reason — a mirror that drifts offers the reader a control the server
 * then refuses:
 *   · `operatorApplies`     ← `ExplorerFilterOperator::appliesTo()`
 *   · `isGroupable`/`isTemporal` ← `ExplorerFieldType::isGroupable()` / `isTemporal()`
 *   · the chart fixtures    ← `ExplorerChartType::minDimensions()` / `maxDimensions()` / `maxMeasures()`
 *
 * And one exists because of a storage detail no reader should ever meet: `sameDefinition` compares two
 * documents whose keys arrive in different orders, because a `jsonb` column returns them in its own.
 */

// A NON-UTC zone throughout, as on /analytics: a declaration that silently fell back to UTC would be
// indistinguishable from a correct one under a UTC fixture.
const TZ = 'Asia/Manila';

const REAL_TZ = process.env.TZ;

afterEach(() => {
    process.env.TZ = REAL_TZ;
});

/** Run `fn` as if the reader's machine were in `zone`. Node re-reads `process.env.TZ` on assignment. */
function withViewerZone<T>(zone: string, fn: () => T): T {
    process.env.TZ = zone;

    return fn();
}

function field(key: string, type: FieldType, overrides: Partial<Field> = {}): Field {
    return { key, label: key, type, group: 'Response', options: null, ...overrides };
}

// `ResponsesSource::fields()` in the order the server sends them, plus the question kinds only the answers
// source has — a multi-choice, a yes/no and a calendar date — so every field type is in play.
const STATUS = field('status', 'category', {
    label: 'Status',
    options: [
        { value: 'submitted', label: 'Submitted' },
        { value: 'approved', label: 'Approved' },
    ],
});
const CHANNEL = field('source', 'category', { label: 'Channel' });
const SUBMITTED = field('submitted_at', 'time', { label: 'Submitted', group: 'Dates' });
const COMPLETENESS = field('completeness', 'number', { label: 'Completeness (%)', group: 'Numbers' });
const SERVICES = field('answer:services', 'multi_category', { label: 'Services received', group: 'Questions' });
const CONSENT = field('answer:consent', 'boolean', { label: 'Consent given', group: 'Questions' });
const VISIT_DATE = field('answer:visit_date', 'date', { label: 'Date of visit', group: 'Questions' });

/** One field of every type. */
const EVERY_TYPE: Field[] = [STATUS, CONSENT, SERVICES, COMPLETENESS, SUBMITTED, VISIT_DATE];

function source(overrides: Partial<Source> = {}): Source {
    return {
        key: 'responses',
        label: 'Responses',
        description: 'Every response to every form you can open: which form, how it arrived, where it is in review.',
        row_noun: 'responses',
        requires_form: false,
        default_range_days: 90,
        range_field: 'submitted_at',
        fields: [STATUS, CHANNEL, SUBMITTED, COMPLETENESS, SERVICES],
        ...overrides,
    };
}

// `ExplorerChartType` verbatim: label(), minDimensions(), maxDimensions(), maxMeasures(1), maxMeasures(2).
const CHARTS: ChartOption[] = [
    { value: 'bar', label: 'Bars', min_dimensions: 1, max_dimensions: 1, max_measures: 1, max_measures_split: 1 },
    { value: 'column', label: 'Columns', min_dimensions: 1, max_dimensions: 2, max_measures: 3, max_measures_split: 1 },
    { value: 'stacked', label: 'Stacked columns', min_dimensions: 1, max_dimensions: 2, max_measures: 3, max_measures_split: 1 },
    { value: 'line', label: 'Line', min_dimensions: 1, max_dimensions: 2, max_measures: 3, max_measures_split: 1 },
    { value: 'area', label: 'Area', min_dimensions: 1, max_dimensions: 2, max_measures: 3, max_measures_split: 1 },
    { value: 'donut', label: 'Donut', min_dimensions: 1, max_dimensions: 1, max_measures: 1, max_measures_split: 1 },
    { value: 'table', label: 'Table', min_dimensions: 0, max_dimensions: 2, max_measures: 3, max_measures_split: 3 },
    { value: 'number', label: 'Number', min_dimensions: 0, max_dimensions: 0, max_measures: 3, max_measures_split: 3 },
];

function chart(type: ChartType): ChartOption {
    const found = CHARTS.find((c) => c.value === type);
    expect(found, `no chart option "${type}"`).toBeDefined();

    return found!;
}

function definition(overrides: Partial<Definition> = {}): Definition {
    return {
        source: 'responses',
        form_id: null,
        chart: 'bar',
        dimensions: [{ field: 'status', bucket: null }],
        measures: [{ aggregate: 'count', field: null }],
        filters: [],
        from: '2026-05-06',
        to: '2026-08-03',
        timezone: TZ,
        sort: 'value_desc',
        limit: 10,
        ...overrides,
    };
}

describe('isoDate', () => {
    it('reads the LOCAL calendar date, whichever zone the runner is in', () => {
        // Built from local components, so the expectation is the same sentence in every zone: half past
        // eleven at night on the 31st is the 31st to the person looking at the clock.
        expect(isoDate(new Date(2026, 0, 31, 23, 30))).toBe('2026-01-31');
    });

    it('does not hand a reader west of Greenwich tomorrow’s date late in the evening', () => {
        // The bug `toISOString()` would be: at 23:30 in New York it is already the 1st in UTC, and a
        // "last 90 days" range that ends tomorrow asks the server for a day that has not happened.
        withViewerZone('America/New_York', () => {
            const late = new Date(2026, 0, 31, 23, 30);

            expect(late.toISOString().slice(0, 10)).toBe('2026-02-01');
            expect(isoDate(late)).toBe('2026-01-31');
        });
    });

    it('does not hand a reader east of Greenwich yesterday’s date early in the morning', () => {
        // The same defect in the other hemisphere, where it LOSES today: half past midnight in Manila is
        // still the 31st in UTC, so a chart "ending today" would stop a day short all morning.
        withViewerZone('Asia/Manila', () => {
            const early = new Date(2026, 1, 1, 0, 30);

            expect(early.toISOString().slice(0, 10)).toBe('2026-01-31');
            expect(isoDate(early)).toBe('2026-02-01');
        });
    });

    it('pads a single-digit month and day, because the server reads exactly YYYY-MM-DD', () => {
        expect(isoDate(new Date(2026, 2, 5, 12, 0))).toBe('2026-03-05');
    });
});

describe('rangeEnding', () => {
    it('makes a one-day range of a single day', () => {
        expect(rangeEnding('2026-03-31', 1)).toEqual({ from: '2026-03-31', to: '2026-03-31' });
    });

    it('counts BOTH ends, so thirty days ending on the 31st begin on the 2nd', () => {
        // The off-by-one that reads fine on screen: beginning on the 1st would be thirty-one days under
        // a button that says thirty.
        expect(rangeEnding('2026-03-31', 30)).toEqual({ from: '2026-03-02', to: '2026-03-31' });
    });

    it('crosses a month and a year boundary', () => {
        expect(rangeEnding('2026-03-05', 10).from).toBe('2026-02-24');
        expect(rangeEnding('2026-01-05', 10).from).toBe('2025-12-27');
    });

    it('lands on the leap day in a leap year and on the 28th otherwise', () => {
        expect(rangeEnding('2024-03-01', 2).from).toBe('2024-02-29');
        expect(rangeEnding('2026-03-01', 2).from).toBe('2026-02-28');
    });

    it('treats a length below one as one day rather than a range that ends before it starts', () => {
        expect(rangeEnding('2026-03-31', 0)).toEqual({ from: '2026-03-31', to: '2026-03-31' });
        expect(rangeEnding('2026-03-31', -7)).toEqual({ from: '2026-03-31', to: '2026-03-31' });
    });

    it('does not drift by a day for a reader far from Greenwich, in either direction', () => {
        // `today` is already a calendar date; the arithmetic on it must not pass through the runner's zone.
        const expected = { from: '2026-05-06', to: '2026-08-03' };

        expect(withViewerZone('America/Los_Angeles', () => rangeEnding('2026-08-03', 90))).toEqual(expected);
        expect(withViewerZone('Pacific/Auckland', () => rangeEnding('2026-08-03', 90))).toEqual(expected);
    });
});

describe('spanInDays', () => {
    it('counts both ends of the range', () => {
        expect(spanInDays('2026-03-01', '2026-03-31')).toBe(31);
        expect(spanInDays('2026-03-31', '2026-03-31')).toBe(1);
    });

    it('is the inverse of rangeEnding at every length the builder offers', () => {
        // 30, 90 and 365 are the quick ranges; 1096 is `ExplorerQuery::MAX_RANGE_DAYS`.
        for (const days of [1, 30, 90, 365, 1096]) {
            const { from, to } = rangeEnding('2026-08-03', days);

            expect(spanInDays(from, to), `${days} days`).toBe(days);
        }
    });

    it('counts whole days across a daylight-saving change', () => {
        // March 2026 holds New York's spring-forward. A day there is 23 hours long, and arithmetic in the
        // reader's zone would make this month thirty days and a fraction.
        expect(withViewerZone('America/New_York', () => spanInDays('2026-03-01', '2026-03-31'))).toBe(31);
    });

    it('is null when either end is not a date, so a cleared date input is not a zero-length range', () => {
        expect(spanInDays('', '2026-03-31')).toBeNull();
        expect(spanInDays('2026-03-01', '')).toBeNull();
        expect(spanInDays('soon', 'later')).toBeNull();
    });
});

describe('the field-type predicates', () => {
    it('calls a moment and a calendar date temporal, and nothing else', () => {
        // Mirrors `ExplorerFieldType::isTemporal()`.
        const expected: Record<FieldType, boolean> = {
            category: false,
            boolean: false,
            multi_category: false,
            number: false,
            time: true,
            date: true,
        };

        for (const [type, temporal] of Object.entries(expected) as [FieldType, boolean][]) {
            expect(isTemporal(type), type).toBe(temporal);
        }
    });

    it('lets every field be a group-by except a number', () => {
        // Mirrors `ExplorerFieldType::isGroupable()`: a number is summed and compared, and grouping by one
        // makes a group per distinct value.
        expect(EVERY_TYPE.filter(isGroupable).map((f) => f.type)).toEqual([
            'category',
            'boolean',
            'multi_category',
            'time',
            'date',
        ]);
    });

    it('lets every field be filtered except a date, which the range already filters', () => {
        expect(EVERY_TYPE.filter(isFilterable).map((f) => f.type)).toEqual([
            'category',
            'boolean',
            'multi_category',
            'number',
        ]);
    });
});

describe('fieldsForAggregate', () => {
    it('offers no field for a row count, which counts rows', () => {
        expect(fieldsForAggregate('count', EVERY_TYPE)).toEqual([]);
    });

    it('offers a distinct count the fields whose values can be told apart: categories, yes/no and numbers', () => {
        // Not a multi-choice (one answer holds several values) and not a date.
        expect(fieldsForAggregate('count_distinct', EVERY_TYPE).map((f) => f.key)).toEqual([
            'status',
            'answer:consent',
            'completeness',
        ]);
    });

    it('offers every arithmetic value numbers and nothing else', () => {
        const arithmetic: Aggregate[] = ['sum', 'avg', 'min', 'max', 'median'];

        for (const aggregate of arithmetic) {
            expect(fieldsForAggregate(aggregate, EVERY_TYPE).map((f) => f.key), aggregate).toEqual(['completeness']);
        }
    });

    it('offers nothing on a source with no number, which is how the builder knows to disable a sum', () => {
        expect(fieldsForAggregate('sum', [STATUS, SUBMITTED])).toEqual([]);
    });
});

describe('operatorApplies', () => {
    // ⚠️ `ExplorerFilterOperator::appliesTo()`, cell for cell. The server refuses a filter this table does
    // not allow, so a `true` here that PHP calls `false` is a control that always ends in a refusal — and a
    // `false` that PHP calls `true` is a filter no reader can ever build.
    const TRUTH: Record<FilterOperator, Record<FieldType, boolean>> = {
        in: { category: true, boolean: true, multi_category: true, number: false, time: false, date: false },
        not_in: { category: true, boolean: true, multi_category: true, number: false, time: false, date: false },
        gte: { category: false, boolean: false, multi_category: false, number: true, time: false, date: false },
        lte: { category: false, boolean: false, multi_category: false, number: true, time: false, date: false },
        is_empty: { category: true, boolean: true, multi_category: true, number: true, time: false, date: false },
        not_empty: { category: true, boolean: true, multi_category: true, number: true, time: false, date: false },
    };

    it.each(Object.keys(TRUTH) as FilterOperator[])('answers for “%s” on every field type exactly as the PHP enum does', (operator) => {
        for (const [type, applies] of Object.entries(TRUTH[operator]) as [FieldType, boolean][]) {
            expect(operatorApplies(operator, type), `${operator} on ${type}`).toBe(applies);
        }
    });

    it('leaves a date with no comparison at all, which is why a date is never offered as a filter', () => {
        // The two halves have to agree: a field `isFilterable` lets through with no operator that applies
        // would render a filter row whose comparison select is empty.
        for (const candidate of EVERY_TYPE) {
            const some = (Object.keys(TRUTH) as FilterOperator[]).some((operator) => operatorApplies(operator, candidate.type));

            expect(some, candidate.type).toBe(isFilterable(candidate));
        }
    });
});

describe('fieldGroups', () => {
    it('groups the fields under their headings, in the order the server sent both', () => {
        // Order is the server's to choose — "Response" before "Dates" before "Numbers" — and sorting here
        // would move a form's questions away from the order the form asks them in.
        expect(fieldGroups([STATUS, SUBMITTED, CHANNEL, COMPLETENESS], () => true)).toEqual([
            {
                label: 'Response',
                options: [
                    { value: 'status', label: 'Status' },
                    { value: 'source', label: 'Channel' },
                ],
            },
            { label: 'Dates', options: [{ value: 'submitted_at', label: 'Submitted' }] },
            { label: 'Numbers', options: [{ value: 'completeness', label: 'Completeness (%)' }] },
        ]);
    });

    it('names a group with no heading “Fields”, because an optgroup with an empty label reads as a gap', () => {
        const groups = fieldGroups([field('role', 'category', { label: 'Role', group: '' })], () => true);

        expect(groups).toEqual([{ label: 'Fields', options: [{ value: 'role', label: 'Role' }] }]);
    });

    it('keeps only the fields the predicate lets through', () => {
        const groups = fieldGroups([STATUS, SUBMITTED, COMPLETENESS], isGroupable);

        expect(groups.flatMap((g) => g.options.map((o) => o.value))).toEqual(['status', 'submitted_at']);
    });

    it('returns no group at all for a heading whose every field was filtered out', () => {
        // "Numbers" holds only a number, which is not groupable. An empty optgroup is a heading over nothing.
        expect(fieldGroups([STATUS, COMPLETENESS], isGroupable).map((g) => g.label)).toEqual(['Response']);
        expect(fieldGroups([COMPLETENESS], isGroupable)).toEqual([]);
    });
});

describe('newDefinition', () => {
    it('counts the rows by the first groupable field that is neither a date nor a multi-choice', () => {
        // The three before it are each a worse first chart: a number cannot be grouped, a date needs a
        // period chosen, and a multi-choice counts a row once per option.
        const made = newDefinition(source({ fields: [COMPLETENESS, SUBMITTED, SERVICES, STATUS, CHANNEL] }), TZ, '2026-08-03');

        expect(made.chart).toBe('bar');
        expect(made.dimensions).toEqual([{ field: 'status', bucket: null }]);
        expect(made.measures).toEqual([{ aggregate: 'count', field: null }]);
    });

    it('is a single number on a source with nothing to group by', () => {
        const made = newDefinition(source({ fields: [COMPLETENESS, SUBMITTED, SERVICES] }), TZ, '2026-08-03');

        expect(made.chart).toBe('number');
        expect(made.dimensions).toEqual([]);
        expect(made.measures).toEqual([{ aggregate: 'count', field: null }]);
    });

    it('covers the source’s own default range, ending today and counting today', () => {
        const ninety = newDefinition(source(), TZ, '2026-08-03');
        const thirty = newDefinition(source({ default_range_days: 30 }), TZ, '2026-08-03');

        expect([ninety.from, ninety.to]).toEqual(['2026-05-06', '2026-08-03']);
        expect(spanInDays(ninety.from, ninety.to)).toBe(90);
        expect([thirty.from, thirty.to]).toEqual(['2026-07-05', '2026-08-03']);
    });

    it('carries the reader’s time zone and the source, and starts with no form and no filters', () => {
        const made = newDefinition(source({ key: 'answers', requires_form: true }), TZ, '2026-08-03');

        expect(made).toMatchObject({ source: 'answers', form_id: null, filters: [], timezone: TZ, sort: 'value_desc', limit: 10 });
        // The version key is the server's alone to write.
        expect(made).not.toHaveProperty('v');
    });
});

describe('dropUnknownFields', () => {
    const QUESTIONS: Field[] = [STATUS, SUBMITTED, field('answer:households', 'number', { label: 'Households reached' })];

    it('drops a group-by and a filter that name a field the new form does not have', () => {
        const next = dropUnknownFields(
            definition({
                dimensions: [
                    { field: 'answer:district', bucket: null },
                    { field: 'status', bucket: null },
                ],
                filters: [
                    { field: 'answer:district', operator: 'in', values: ['Malate'] },
                    { field: 'status', operator: 'in', values: ['approved'] },
                ],
            }),
            QUESTIONS,
        );

        expect(next.dimensions).toEqual([{ field: 'status', bucket: null }]);
        expect(next.filters).toEqual([{ field: 'status', operator: 'in', values: ['approved'] }]);
    });

    it('turns a value that lost its field into a row count, so the chart keeps something to draw', () => {
        const next = dropUnknownFields(
            definition({
                measures: [
                    { aggregate: 'avg', field: 'answer:age' },
                    { aggregate: 'sum', field: 'answer:households' },
                ],
            }),
            QUESTIONS,
        );

        expect(next.measures).toEqual([
            { aggregate: 'count', field: null },
            { aggregate: 'sum', field: 'answer:households' },
        ]);
    });

    it('collapses the duplicate row counts that fallback produces', () => {
        // Two values over two lost questions would otherwise become "Count" twice — one series drawn twice
        // over itself under two identical legend entries.
        const next = dropUnknownFields(
            definition({
                measures: [
                    { aggregate: 'count', field: null },
                    { aggregate: 'avg', field: 'answer:age' },
                    { aggregate: 'max', field: 'answer:weight' },
                ],
            }),
            QUESTIONS,
        );

        expect(next.measures).toEqual([{ aggregate: 'count', field: null }]);
    });

    it('leaves a declaration alone when the form has everything it names', () => {
        const before = definition({
            dimensions: [{ field: 'submitted_at', bucket: 'month' }],
            measures: [
                { aggregate: 'count', field: null },
                { aggregate: 'sum', field: 'answer:households' },
            ],
            filters: [{ field: 'status', operator: 'not_empty', values: [] }],
        });

        expect(dropUnknownFields(before, QUESTIONS)).toEqual(before);
    });

    it('does not touch the chart type, the range or anything else the form does not decide', () => {
        const before = definition({ chart: 'donut', limit: 5, sort: 'label', dimensions: [{ field: 'answer:district', bucket: null }] });
        const next = dropUnknownFields(before, QUESTIONS);

        expect({ ...next, dimensions: before.dimensions }).toEqual(before);
    });
});

describe('fitToChart', () => {
    const FIELDS = [COMPLETENESS, SUBMITTED, SERVICES, STATUS, CHANNEL];

    it('trims a column chart with two group-bys to what a donut can draw, and to five slices', () => {
        const next = fitToChart(
            definition({
                chart: 'column',
                dimensions: [
                    { field: 'status', bucket: null },
                    { field: 'source', bucket: null },
                ],
                measures: [
                    { aggregate: 'count', field: null },
                    { aggregate: 'avg', field: 'completeness' },
                ],
                limit: 10,
            }),
            chart('donut'),
            FIELDS,
        );

        expect(next.chart).toBe('donut');
        // The FIRST of each is the one that survives: it is the one the reader chose first.
        expect(next.dimensions).toEqual([{ field: 'status', bucket: null }]);
        expect(next.measures).toEqual([{ aggregate: 'count', field: null }]);
        // Five colours, so five slices.
        expect(next.limit).toBe(5);
    });

    it('caps a donut’s slices at five without raising a smaller limit, and leaves other charts’ limits alone', () => {
        expect(fitToChart(definition({ limit: 3 }), chart('donut'), FIELDS).limit).toBe(3);
        expect(fitToChart(definition({ limit: 25 }), chart('column'), FIELDS).limit).toBe(25);
    });

    it('drops the group-by when a bar chart becomes a single number', () => {
        const next = fitToChart(definition(), chart('number'), FIELDS);

        expect(next.chart).toBe('number');
        expect(next.dimensions).toEqual([]);
        expect(next.measures).toEqual([{ aggregate: 'count', field: null }]);
    });

    it('gives a number a group-by when it becomes a bar chart, skipping numbers, dates and multi-choices', () => {
        // Without it the reader's next sight is a refusal — "bars needs something to group by" — for
        // picking a chart type from a list.
        const next = fitToChart(definition({ chart: 'number', dimensions: [] }), chart('bar'), FIELDS);

        expect(next.chart).toBe('bar');
        expect(next.dimensions).toEqual([{ field: 'status', bucket: null }]);
    });

    it('keeps the reader’s own group-by rather than replacing it with the default one', () => {
        const before = definition({ chart: 'bar', dimensions: [{ field: 'submitted_at', bucket: 'week' }] });

        expect(fitToChart(before, chart('line'), FIELDS).dimensions).toEqual([{ field: 'submitted_at', bucket: 'week' }]);
    });

    it('leaves a chart with no group-by empty-handed when the source has nothing to offer one', () => {
        const next = fitToChart(definition({ chart: 'number', dimensions: [] }), chart('bar'), [COMPLETENESS, SUBMITTED]);

        expect(next.dimensions).toEqual([]);
    });

    it('holds a chart split by a second field to the one value it can plot', () => {
        // A table shows three values under two group-bys; a column chart split by a second field shows
        // one, because the second field supplies the series.
        const next = fitToChart(
            definition({
                chart: 'table',
                dimensions: [
                    { field: 'status', bucket: null },
                    { field: 'source', bucket: null },
                ],
                measures: [
                    { aggregate: 'count', field: null },
                    { aggregate: 'avg', field: 'completeness' },
                    { aggregate: 'max', field: 'completeness' },
                ],
            }),
            chart('column'),
            FIELDS,
        );

        expect(next.dimensions).toHaveLength(2);
        expect(next.measures).toEqual([{ aggregate: 'count', field: null }]);
    });

    it('keeps all three values on a column chart with ONE group-by', () => {
        const measures: Definition['measures'] = [
            { aggregate: 'count', field: null },
            { aggregate: 'avg', field: 'completeness' },
            { aggregate: 'max', field: 'completeness' },
        ];

        expect(fitToChart(definition({ chart: 'table', measures }), chart('column'), FIELDS).measures).toEqual(measures);
    });

    it('never returns a chart with no value, even for a chart type that claims to take none', () => {
        // "At least one value" is the one rule no chart type relaxes on the server.
        const none: ChartOption = { ...chart('bar'), max_measures: 0, max_measures_split: 0 };

        expect(fitToChart(definition(), none, FIELDS).measures).toEqual([{ aggregate: 'count', field: null }]);
    });

    it('does not mutate the declaration it was given', () => {
        const before = definition({
            chart: 'column',
            dimensions: [
                { field: 'status', bucket: null },
                { field: 'source', bucket: null },
            ],
        });
        const copy = JSON.parse(JSON.stringify(before)) as Definition;

        fitToChart(before, chart('donut'), FIELDS);

        expect(before).toEqual(copy);
    });
});

describe('incompleteReason', () => {
    const RESPONSES = source();
    const ANSWERS = source({ key: 'answers', label: 'Answers to a form', requires_form: true });
    const FIELDS = RESPONSES.fields;

    it('asks for a data source when the declaration names one this reader is not offered', () => {
        expect(incompleteReason(definition(), undefined, [])).toBe('Choose a data source.');
    });

    it('asks for the form on a source that charts one form’s answers', () => {
        expect(incompleteReason(definition({ source: 'answers' }), ANSWERS, FIELDS)).toBe(
            'Choose the form whose answers to chart.',
        );
    });

    it('asks what to group by when a group-by names a field that is not there', () => {
        expect(incompleteReason(definition({ dimensions: [{ field: 'answer:district', bucket: null }] }), RESPONSES, FIELDS)).toBe(
            'Choose what to group by.',
        );
    });

    it('asks how to group a date that has no period, and names the date', () => {
        expect(incompleteReason(definition({ dimensions: [{ field: 'submitted_at', bucket: null }] }), RESPONSES, FIELDS)).toBe(
            'Choose how to group Submitted.',
        );
    });

    it('asks for a field when a value other than a row count has none, or names one that is not there', () => {
        const sentence = 'Choose the field for each value.';

        expect(incompleteReason(definition({ measures: [{ aggregate: 'avg', field: null }] }), RESPONSES, FIELDS)).toBe(sentence);
        expect(incompleteReason(definition({ measures: [{ aggregate: 'sum', field: 'answer:households' }] }), RESPONSES, FIELDS)).toBe(
            sentence,
        );
    });

    it('asks for a value on a filter whose comparison needs one', () => {
        const sentence = 'Give each filter a value, or remove it.';
        const needing: FilterOperator[] = ['in', 'not_in', 'gte', 'lte'];

        for (const operator of needing) {
            expect(
                incompleteReason(definition({ filters: [{ field: 'status', operator, values: [] }] }), RESPONSES, FIELDS),
                operator,
            ).toBe(sentence);
        }
    });

    it('asks for a date range when either end has been cleared', () => {
        expect(incompleteReason(definition({ from: '' }), RESPONSES, FIELDS)).toBe('Choose a date range.');
        expect(incompleteReason(definition({ to: '' }), RESPONSES, FIELDS)).toBe('Choose a date range.');
    });

    it('asks for the earliest thing missing first, so the prompts walk down the form', () => {
        // Everything is wrong here; the form comes first because nothing below it can be chosen without it.
        const everything = definition({
            source: 'answers',
            dimensions: [{ field: 'gone', bucket: null }],
            measures: [{ aggregate: 'avg', field: null }],
            filters: [{ field: 'status', operator: 'in', values: [] }],
            from: '',
        });

        expect(incompleteReason(everything, ANSWERS, FIELDS)).toBe('Choose the form whose answers to chart.');
    });

    it('is null for a complete declaration', () => {
        const complete = definition({
            chart: 'column',
            dimensions: [
                { field: 'submitted_at', bucket: 'month' },
                { field: 'status', bucket: null },
            ],
            measures: [{ aggregate: 'avg', field: 'completeness' }],
            filters: [{ field: 'source', operator: 'in', values: ['guest'] }],
        });

        expect(incompleteReason(complete, RESPONSES, FIELDS)).toBeNull();
        expect(incompleteReason(definition({ source: 'answers', form_id: 'f1' }), ANSWERS, FIELDS)).toBeNull();
    });

    it('does not ask an “is empty” filter for a value it has no use for', () => {
        // The builder hides the value control for these two, so asking would be a prompt with no way out.
        const emptiness: FilterOperator[] = ['is_empty', 'not_empty'];

        for (const operator of emptiness) {
            expect(
                incompleteReason(definition({ filters: [{ field: 'status', operator, values: [] }] }), RESPONSES, FIELDS),
                operator,
            ).toBeNull();
        }
    });
});

describe('sameDefinition', () => {
    const built = definition({
        chart: 'column',
        dimensions: [
            { field: 'submitted_at', bucket: 'month' },
            { field: 'status', bucket: null },
        ],
        measures: [{ aggregate: 'avg', field: 'completeness' }],
        filters: [{ field: 'source', operator: 'in', values: ['guest', 'manual'] }],
    });

    // The same declaration as PostgreSQL hands it back: `jsonb` stores an object's keys by length and then
    // by byte, not in the order they were written — at EVERY level, not only the top one.
    const stored: Definition = {
        v: 1,
        to: '2026-08-03',
        from: '2026-05-06',
        sort: 'value_desc',
        chart: 'column',
        limit: 10,
        source: 'responses',
        filters: [{ field: 'source', values: ['guest', 'manual'], operator: 'in' }],
        form_id: null,
        measures: [{ field: 'completeness', aggregate: 'avg' }],
        timezone: TZ,
        dimensions: [
            { bucket: 'month', field: 'submitted_at' },
            { bucket: null, field: 'status' },
        ],
    };

    it('calls a declaration equal to itself read back from a jsonb column, keys reordered at every level', () => {
        // The premise, asserted rather than assumed: compared as text these two differ — at the top level
        // and inside a group-by, a value and a filter — which is the comparison that kept "Save changes"
        // lit forever on a chart that had just been saved.
        expect(JSON.stringify(toPayload(stored))).not.toBe(JSON.stringify(built));
        expect(Object.keys(stored.dimensions[0])).toEqual(['bucket', 'field']);
        expect(Object.keys(built.dimensions[0])).toEqual(['field', 'bucket']);

        expect(sameDefinition(built, stored)).toBe(true);
        expect(sameDefinition(stored, built)).toBe(true);
    });

    it('ignores the version key, which only the stored side carries', () => {
        expect(sameDefinition(built, { ...built, v: 1 })).toBe(true);
        expect(sameDefinition({ ...built, v: 1 }, built)).toBe(true);
    });

    it('does NOT call two declarations equal when their group-bys are in the other order', () => {
        // Canonical KEYS, never canonical LISTS: the first group-by is the axis and the second is the
        // series, so swapping them is a different chart.
        const swapped = { ...built, dimensions: [built.dimensions[1], built.dimensions[0]] };

        expect(sameDefinition(built, swapped)).toBe(false);
    });

    it('does not call two declarations equal when their values are in the other order', () => {
        const two: Definition['measures'] = [
            { aggregate: 'count', field: null },
            { aggregate: 'avg', field: 'completeness' },
        ];

        expect(sameDefinition({ ...built, measures: two }, { ...built, measures: [two[1], two[0]] })).toBe(false);
    });

    it('sees every real change a reader can make', () => {
        const changes: [string, Definition][] = [
            ['the source', { ...built, source: 'answers' }],
            ['the form', { ...built, form_id: 'f1' }],
            ['the chart type', { ...built, chart: 'stacked' }],
            ['the date grouping', { ...built, dimensions: [{ field: 'submitted_at', bucket: 'week' }, built.dimensions[1]] }],
            ['a group-by removed', { ...built, dimensions: [built.dimensions[0]] }],
            ['what a value computes', { ...built, measures: [{ aggregate: 'median', field: 'completeness' }] }],
            ['the field a value is taken over', { ...built, measures: [{ aggregate: 'avg', field: 'other' }] }],
            ['a filter’s comparison', { ...built, filters: [{ ...built.filters[0], operator: 'not_in' }] }],
            ['a filter’s values', { ...built, filters: [{ ...built.filters[0], values: ['guest'] }] }],
            ['a filter removed', { ...built, filters: [] }],
            ['the start of the range', { ...built, from: '2026-05-07' }],
            ['the end of the range', { ...built, to: '2026-08-02' }],
            ['the time zone', { ...built, timezone: 'UTC' }],
            ['the order', { ...built, sort: 'label' }],
            ['the groups shown', { ...built, limit: 11 }],
        ];

        for (const [what, changed] of changes) {
            expect(sameDefinition(built, changed), what).toBe(false);
            // Against the reordered copy too: the canonical form must not be what hides a change.
            expect(sameDefinition(stored, changed), `${what}, against the stored order`).toBe(false);
        }
    });
});

describe('toPayload', () => {
    it('strips the version key and nothing else', () => {
        // `v` is never echoed: the server stamps the schema version, and a second author of it is how a
        // stored definition comes to carry a version nothing wrote.
        const body = definition({ filters: [{ field: 'status', operator: 'in', values: ['approved'] }] });
        const payload = toPayload({ ...body, v: 1 });

        expect(payload).not.toHaveProperty('v');
        expect(payload).toEqual(body);
    });

    it('returns a declaration that never had a version unchanged', () => {
        const body = definition();

        expect(toPayload(body)).toEqual(body);
    });

    it('does not mutate its argument, which is the builder’s live declaration', () => {
        const live: Definition = { ...definition(), v: 1 };

        toPayload(live);

        expect(live.v).toBe(1);
        expect(Object.keys(live)).toContain('v');
    });
});
