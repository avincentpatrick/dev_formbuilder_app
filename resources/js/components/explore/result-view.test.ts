import { afterEach, describe, expect, it } from 'vitest';
import {
    disclosures,
    formatNumber,
    rangeSentence,
    toBarData,
    toColumns,
    toDonut,
    toLines,
    toTable,
    toTiles,
} from './result-view';
import type { Result, ResultCategory, ResultSeries } from './types';

/**
 * M236 — from an explorer result to what each chart primitive is handed.
 *
 * One rule runs through this module and through every case below: **`null` is "no value", and it is never
 * drawn as zero without saying so.** The server sends `null` on purpose — an "Other" bucket of fifty
 * averages has no average, a month with no responses has no median — and each of the three careless
 * renderings states something false:
 *   · a bar of height 0 says "the average was nothing";
 *   · the text "0" or "NaN" in a table says the same, or says the page is broken;
 *   · a line dipping to the axis says the figure collapsed that month.
 * So a bar or slice with no value is left OUT, a table cell is a dash, and a line — which needs a number
 * at every point — is drawn at 0 and reports that it was.
 */

const DASH = '—';

// A NON-UTC zone in the fixture: `rangeSentence` prints the query's zone and must not APPLY it — nor the
// reader's — to dates the server already cut.
const TZ = 'Asia/Manila';

const REAL_TZ = process.env.TZ;

afterEach(() => {
    process.env.TZ = REAL_TZ;
});

/** Run `fn` as if the reader's machine were in `zone`. Node re-reads `process.env.TZ` for each new Intl. */
function withViewerZone<T>(zone: string, fn: () => T): T {
    process.env.TZ = zone;

    return fn();
}

function category(key: string, label: string, other = false): ResultCategory {
    return { key, label, other };
}

function series(key: string, label: string, values: (number | null)[], other = false): ResultSeries {
    return { key, label, other, values };
}

// What the shaper sends for the folded bucket: `ExplorerResultShaper::OTHER_KEY` and "Other (N)".
const OTHER = category('__other__', 'Other (3)', true);

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

/** Average completeness by month — the non-additive value, where the server sends real nulls. */
function monthly(values: (number | null)[][], labels = ['Jan 2026', 'Feb 2026', 'Mar 2026']): Result {
    return result({
        chart: 'line',
        dimensions: [{ key: 'submitted_at', label: 'Submitted', type: 'time', bucket: 'month' }],
        measures: [{ key: 'm0', label: 'Average of Completeness (%)', aggregate: 'avg', additive: false }],
        categories: labels.map((label, index) => category(`2026-0${index + 1}-01`, label)),
        series: values.map((row, index) => series(`s${index}`, index === 0 ? 'Guest link' : 'Manual entry', row)),
    });
}

describe('formatNumber', () => {
    it('renders every kind of “no value” as a dash, never as 0 and never as NaN', () => {
        for (const nothing of [null, undefined, Number.NaN, Number.POSITIVE_INFINITY, Number.NEGATIVE_INFINITY]) {
            const text = formatNumber(nothing);

            expect(text, String(nothing)).toBe(DASH);
            expect(text).not.toBe('0');
            expect(text).not.toContain('NaN');
        }
    });

    it('keeps a real zero a zero, because nothing happening is a number', () => {
        expect(formatNumber(0)).toBe((0).toLocaleString());
        expect(formatNumber(0)).not.toBe(DASH);
    });

    it('leaves an integer as it is', () => {
        // Below a thousand on purpose: the grouping separator belongs to the reader's locale.
        expect(formatNumber(374)).toBe((374).toLocaleString());
        expect(formatNumber(-12)).toBe((-12).toLocaleString());
    });

    it('shows at most two decimals, and no trailing ones it did not need', () => {
        // An average arrives as 43.83333333333333. Compared against `toLocaleString` so the decimal mark
        // is the runner's own.
        expect(formatNumber(43.83333333333333)).toBe((43.83).toLocaleString());
        expect(formatNumber(2.5)).toBe((2.5).toLocaleString());
        expect(formatNumber(99.999)).toBe((100).toLocaleString());
    });
});

describe('toBarData', () => {
    it('makes one bar per category from the first series', () => {
        expect(toBarData(result())).toEqual([
            { key: 'guest', label: 'Guest link', value: 210, neutral: false },
            { key: 'manual', label: 'Manual entry', value: 120, neutral: false },
            { key: 'offline_sync', label: 'Offline sync', value: 44, neutral: false },
        ]);
    });

    it('leaves OUT a category with no value rather than drawing it as a bar of zero', () => {
        // The case the whole module is written around. Three smaller channels folded into "Other" have
        // no average between them; a zero-height bar labelled "Other (3)" says their average was nothing.
        const bars = toBarData(
            result({
                measures: [{ key: 'm0', label: 'Average of Completeness (%)', aggregate: 'avg', additive: false }],
                categories: [category('guest', 'Guest link'), category('manual', 'Manual entry'), OTHER],
                series: [series('m0', 'Average of Completeness (%)', [82.5, 61, null])],
            }),
        );

        expect(bars.map((bar) => bar.label)).toEqual(['Guest link', 'Manual entry']);
        expect(bars.some((bar) => bar.value === 0)).toBe(false);
    });

    it('keeps a real zero as a bar, because a channel nobody used is a fact about the channel', () => {
        const bars = toBarData(result({ series: [series('m0', 'Count of responses', [210, 0, 44])] }));

        expect(bars.map((bar) => bar.value)).toEqual([210, 0, 44]);
    });

    it('marks the folded “Other” bar neutral and no other', () => {
        // Neutral is how the primitive knows not to spend a hue on a remainder.
        const bars = toBarData(
            result({
                categories: [category('guest', 'Guest link'), category('manual', 'Manual entry'), OTHER],
                series: [series('m0', 'Count of responses', [210, 120, 44])],
            }),
        );

        expect(bars.map((bar) => bar.neutral)).toEqual([false, false, true]);
        expect(bars.at(-1)).toEqual({ key: '__other__', label: 'Other (3)', value: 44, neutral: true });
    });

    it('is empty, not an error, for a result with no series', () => {
        expect(toBarData(result({ series: [] }))).toEqual([]);
    });
});

describe('toColumns', () => {
    it('passes a null through untouched, for the column primitive to leave that slot empty', () => {
        // Unlike a bar list, a column chart has an x-axis: the band stays and the column is absent. The
        // primitive owns that, so the mapping must not have turned the null into anything first.
        const columns = toColumns(
            result({
                chart: 'column',
                series: [series('approved', 'Approved', [12, null, 3]), series('returned', 'Returned', [null, null, 0])],
            }),
        );

        expect(columns.categories).toEqual(['Guest link', 'Manual entry', 'Offline sync']);
        expect(columns.series.map((s) => s.values)).toEqual([
            [12, null, 3],
            [null, null, 0],
        ]);
    });

    it('marks a folded “Other” SERIES neutral, which is a different thing from an Other category', () => {
        const columns = toColumns(
            result({
                chart: 'stacked',
                series: [series('approved', 'Approved', [12, 4, 3]), series('__other__', 'Other (2)', [5, 1, 0], true)],
            }),
        );

        expect(columns.series.map((s) => [s.label, s.neutral])).toEqual([
            ['Approved', false],
            ['Other (2)', true],
        ]);
    });
});

describe('toLines', () => {
    it('plots every period when every period has a value, and reports nothing substituted', () => {
        const lines = toLines(monthly([[80, 70, 90]]));

        expect(lines.series).toEqual([
            {
                key: 's0',
                label: 'Guest link',
                points: [
                    { label: 'Jan 2026', value: 80 },
                    { label: 'Feb 2026', value: 70 },
                    { label: 'Mar 2026', value: 90 },
                ],
            },
        ]);
        expect(lines.substituted).toBe(false);
    });

    it('drops a period in which EVERY series is empty, and does not call that a substitution', () => {
        // February had no responses at all. There is nothing to draw at any height, so the period goes —
        // and since no zero was invented, there is nothing to disclose.
        const lines = toLines(
            monthly([
                [80, null, 90],
                [60, null, 75],
            ]),
        );

        expect(lines.series.map((s) => s.points.map((p) => p.label))).toEqual([
            ['Jan 2026', 'Mar 2026'],
            ['Jan 2026', 'Mar 2026'],
        ]);
        expect(lines.substituted).toBe(false);
    });

    it('draws a single missing value among present ones at 0 and SAYS that it did', () => {
        // A line needs a number at every point. Guest link had responses in February and Manual entry had
        // none: the period stays for the first, so the second gets a 0 — and `substituted` is what makes
        // the page print the sentence that keeps that 0 from being read as a measurement.
        const lines = toLines(
            monthly([
                [80, 70, 90],
                [60, null, 75],
            ]),
        );

        expect(lines.series[1].points).toEqual([
            { label: 'Jan 2026', value: 60 },
            { label: 'Feb 2026', value: 0 },
            { label: 'Mar 2026', value: 75 },
        ]);
        expect(lines.substituted).toBe(true);
    });

    it('does not report a real zero as a substitution', () => {
        expect(toLines(monthly([[80, 0, 90]])).substituted).toBe(false);
    });

    it('keeps every series aligned by index after dropping a period', () => {
        // The primitive pairs points across series BY POSITION. Dropping February from one series and not
        // the other would put March's figure of one under February's label of the other.
        const lines = toLines(
            monthly(
                [
                    [80, null, 90, null],
                    [null, null, 75, 40],
                ],
                ['Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026'],
            ),
        );

        const labels = lines.series.map((s) => s.points.map((p) => p.label));
        expect(labels[0]).toEqual(['Jan 2026', 'Mar 2026', 'Apr 2026']);
        expect(labels[1]).toEqual(labels[0]);
        expect(lines.series.map((s) => s.points.map((p) => p.value))).toEqual([
            [80, 90, 0],
            [0, 75, 40],
        ]);
        expect(lines.substituted).toBe(true);
    });
});

describe('toDonut', () => {
    it('slices the positive values only, and totals exactly those', () => {
        // A share of a whole has no meaning for nothing, for zero or for a negative: none is a slice, and
        // none may be in the total the slices are shares OF.
        const donut = toDonut(
            result({
                chart: 'donut',
                categories: [
                    category('guest', 'Guest link'),
                    category('manual', 'Manual entry'),
                    category('api_import', 'API import'),
                    category('offline_sync', 'Offline sync'),
                    OTHER,
                ],
                series: [series('m0', 'Count of responses', [210, 0, -5, 44, null])],
            }),
        );

        expect(donut.data.map((slice) => slice.label)).toEqual(['Guest link', 'Offline sync']);
        expect(donut.total).toBe(254);
    });

    it('keeps the folded “Other” slice neutral', () => {
        const donut = toDonut(
            result({
                chart: 'donut',
                categories: [category('guest', 'Guest link'), OTHER],
                series: [series('m0', 'Count of responses', [210, 164])],
            }),
        );

        expect(donut.data.map((slice) => slice.neutral)).toEqual([false, true]);
        expect(donut.total).toBe(374);
    });
});

describe('toTable', () => {
    it('is one “Total” row of the totals when the chart has no group-by', () => {
        const table = toTable(
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

        expect(table.headers).toEqual(['', 'Count of responses', 'Average of Completeness (%)']);
        expect(table.rows).toEqual([{ key: 'total', label: 'Total', cells: [formatNumber(374), formatNumber(43.8)] }]);
    });

    it('heads the first column with the group-by’s label and the rest with the series', () => {
        const table = toTable(result({ chart: 'table' }));

        expect(table.headers).toEqual(['Channel', 'Count of responses']);
        expect(table.rows).toEqual([
            { key: 'guest', label: 'Guest link', cells: ['210'] },
            { key: 'manual', label: 'Manual entry', cells: ['120'] },
            { key: 'offline_sync', label: 'Offline sync', cells: ['44'] },
        ]);
    });

    it('heads the first column “A / B” when the chart is split by a second field', () => {
        const table = toTable(
            result({
                chart: 'table',
                dimensions: [
                    { key: 'source', label: 'Channel', type: 'category', bucket: null },
                    { key: 'status', label: 'Status', type: 'category', bucket: null },
                ],
                series: [series('approved', 'Approved', [12, 4, 3]), series('returned', 'Returned', [1, 0, 2])],
            }),
        );

        expect(table.headers).toEqual(['Channel / Status', 'Approved', 'Returned']);
        expect(table.rows[0].cells).toEqual(['12', '1']);
    });

    it('prints a cell with no value as a dash, and a zero as a zero', () => {
        // The table is where a reader goes to check the chart, so it must not launder a gap into a number.
        const table = toTable(
            result({
                chart: 'table',
                categories: [category('guest', 'Guest link'), OTHER],
                series: [series('m0', 'Average of Completeness (%)', [0, null])],
            }),
        );

        expect(table.rows.map((row) => row.cells)).toEqual([['0'], [DASH]]);
    });

    it('prints a series shorter than the categories as dashes, never as “undefined”', () => {
        const table = toTable(result({ chart: 'table', series: [series('m0', 'Count of responses', [210])] }));

        expect(table.rows.map((row) => row.cells[0])).toEqual(['210', DASH, DASH]);
    });
});

describe('toTiles', () => {
    const numbers = result({
        chart: 'number',
        dimensions: [],
        measures: [
            { key: 'm0', label: 'Count of responses', aggregate: 'count', additive: true },
            { key: 'm1', label: 'Average of Completeness (%)', aggregate: 'avg', additive: false },
        ],
        categories: [],
        series: [],
        totals: { rows: 374, values: [374, 43.8], coverage: [null, 300] },
    });

    it('makes one tile per value, in the order the reader listed them', () => {
        expect(toTiles(numbers).map((tile) => [tile.key, tile.label, tile.value])).toEqual([
            ['m0', 'Count of responses', formatNumber(374)],
            ['m1', 'Average of Completeness (%)', formatNumber(43.8)],
        ]);
    });

    it('captions a value taken over a field with how many rows had that field', () => {
        // An average of 43.8 over 300 of 374 responses is a different claim from one over all 374, and
        // the tile is the only place this chart type can say so.
        expect(toTiles(numbers)[1].caption).toBe('From 300 of 374 responses');
    });

    it('gives a row count no caption, because it is not taken over anything', () => {
        expect(toTiles(numbers)[0].caption).toBeUndefined();
    });

    it('shows a value the server could not compute as a dash', () => {
        const none = toTiles({ ...numbers, totals: { rows: 374, values: [374, null], coverage: [null, 0] } });

        expect(none[1].value).toBe(DASH);
        expect(none[1].caption).toBe('From 0 of 374 responses');
    });
});

describe('disclosures', () => {
    it('says nothing when the chart read everything and folded nothing', () => {
        expect(disclosures(result())).toEqual([]);
    });

    it('states a value’s coverage only when it could not read every row', () => {
        const partial = result({
            measures: [
                { key: 'm0', label: 'Count of responses', aggregate: 'count', additive: true },
                { key: 'm1', label: 'Average of Completeness (%)', aggregate: 'avg', additive: false },
                { key: 'm2', label: 'Maximum of Completeness (%)', aggregate: 'max', additive: false },
            ],
            // A row count has no coverage; the average missed 74 rows; the maximum happened to read all.
            totals: { rows: 374, values: [374, 43.8, 100], coverage: [null, 300, 374] },
        });

        expect(disclosures(partial)).toEqual([
            'Average of Completeness (%): 300 of 374 responses have a value. The rest are left out of it.',
        ]);
    });

    it('says the folded groups were ADDED when every value can be added', () => {
        const notes = disclosures(result({}, { hidden_groups: 4 }));

        expect(notes).toEqual(['4 smaller groups are added together as “Other”.']);
    });

    it('says the folded groups have NO value when any value is an average, a median or an extreme', () => {
        // The sentence that explains the missing bar: the same fold, and the opposite claim about it.
        const notes = disclosures(
            result(
                {
                    measures: [
                        { key: 'm0', label: 'Count of responses', aggregate: 'count', additive: true },
                        { key: 'm1', label: 'Average of Completeness (%)', aggregate: 'avg', additive: false },
                    ],
                    totals: { rows: 374, values: [374, 43.8], coverage: [null, 374] },
                },
                { hidden_groups: 4 },
            ),
        );

        expect(notes).toEqual([
            '4 smaller groups are folded into “Other”, which has no value for an average, a median, a minimum or a maximum.',
        ]);
        expect(notes.join(' ')).not.toContain('added together');
    });

    it('says series were left off only when none of the series shown is itself the folded “Other”', () => {
        const split = {
            chart: 'column' as const,
            series: [series('approved', 'Approved', [12, 4, 3]), series('returned', 'Returned', [1, 0, 2])],
        };

        // Not shown and not folded: the reader has to be told they exist.
        expect(disclosures(result(split, { hidden_series: 3 }))).toEqual([
            '3 more series are not shown: a chart has five colours.',
        ]);

        // Folded into an "Other (3)" series that IS on the chart: nothing is missing, so nothing is said.
        const folded = { ...split, series: [...split.series, series('__other__', 'Other (3)', [5, 1, 0], true)] };
        expect(disclosures(result(folded, { hidden_series: 3 }))).toEqual([]);
    });

    it('puts the server’s own notes last and keeps them word for word', () => {
        const serverNotes = [
            'Services received takes several choices at once, so each of the responses is counted once for every choice it made.',
            '2 questions marked personal or sensitive are not offered here.',
        ];
        const notes = disclosures(
            result({ totals: { rows: 374, values: [374], coverage: [300] } }, { hidden_groups: 4, notes: serverNotes }),
        );

        expect(notes).toHaveLength(4);
        expect(notes.slice(-2)).toEqual(serverNotes);
        expect(notes[0]).toContain('300 of 374 responses have a value');
        expect(notes[1]).toContain('smaller groups');
    });

    it('singularises the fold sentence when exactly one group was folded', () => {
        // A chart one group over its limit once read "1 smaller groups are added together".
        const [additive] = disclosures(result({}, { hidden_groups: 1 }));
        expect(additive).toBe('1 smaller group is added together as “Other”.');

        const [many] = disclosures(result({}, { hidden_groups: 3 }));
        expect(many).toBe('3 smaller groups are added together as “Other”.');
    });
});

describe('rangeSentence', () => {
    /** A calendar date as the reader's locale writes it — the same options the sentence uses. */
    function written(year: number, month: number, day: number): string {
        return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(undefined, {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone: 'UTC',
        });
    }

    it('names what the range is over, both ends, the zone, and how many rows it read', () => {
        const sentence = rangeSentence(result());

        expect(sentence.startsWith('Submitted ')).toBe(true);
        expect(sentence).toContain(`${written(2026, 3, 1)} – ${written(2026, 3, 31)}`);
        expect(sentence).toContain('Asia/Manila');
        expect(sentence).toContain('374 responses');
    });

    it('uses the source’s own word for a row', () => {
        expect(rangeSentence(result({}, { row_noun: 'members', range_label: 'Joined' }))).toContain('374 members');
        expect(rangeSentence(result({}, { row_noun: 'members', range_label: 'Joined' })).startsWith('Joined ')).toBe(true);
    });

    it('prints the range’s OWN days to a reader west of Greenwich', () => {
        // `from` is a calendar date the server already cut in the query's zone. UTC midnight on 1 March is
        // the evening of 28 February in Los Angeles, and a sentence that formatted in the reader's zone
        // would describe a range one day earlier than the chart beneath it.
        const sentence = withViewerZone('America/Los_Angeles', () => rangeSentence(result()));

        expect(sentence).toContain(`${written(2026, 3, 1)} – ${written(2026, 3, 31)}`);
        expect(sentence).not.toContain(written(2026, 2, 28));
        expect(sentence).not.toContain(written(2026, 3, 30));
    });

    it('prints the same days to a reader far east of Greenwich', () => {
        const sentence = withViewerZone('Pacific/Auckland', () => rangeSentence(result()));

        expect(sentence).toContain(`${written(2026, 3, 1)} – ${written(2026, 3, 31)}`);
        expect(sentence).not.toContain(written(2026, 4, 1));
    });

    it('NAMES the query zone rather than applying it', () => {
        // Honolulu is ten hours behind UTC: applying the query's zone to the two dates would move both.
        const sentence = rangeSentence(result({}, { timezone: 'Pacific/Honolulu' }));

        expect(sentence).toContain('Pacific/Honolulu');
        expect(sentence).toContain(`${written(2026, 3, 1)} – ${written(2026, 3, 31)}`);
    });
});
