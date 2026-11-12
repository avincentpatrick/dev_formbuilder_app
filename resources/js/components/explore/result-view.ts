// From an explorer result to what each chart primitive takes (M236). Pure, so the mapping is under test and
// the components that draw it hold no arithmetic.
//
// One rule runs through all of it: `null` is "no value" and is never drawn as zero without saying so. A bar
// or a slice with no value is left out of the plot; a line needs a number at every point, so a period with
// nothing to average is drawn at 0 and `substituted` tells the page to say that it did.

import type { BarDatum, ChartSeries, ColumnSeries } from '@meridian/design-system';
import type { Result } from './types';

const DASH = '—';

/** At most two decimals, grouped, in the reader's locale. `null` is a dash, never "0". */
export function formatNumber(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return DASH;

    return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

/** A horizontal bar per category, from the first series. */
export function toBarData(result: Result): BarDatum[] {
    const values = result.series[0]?.values ?? [];
    const data: BarDatum[] = [];

    result.categories.forEach((category, index) => {
        const value = values[index];

        if (value !== null && value !== undefined) {
            data.push({ key: category.key, label: category.label, value, neutral: category.other });
        }
    });

    return data;
}

export function toColumns(result: Result): { categories: string[]; series: ColumnSeries[] } {
    return {
        categories: result.categories.map((c) => c.label),
        series: result.series.map((s) => ({ key: s.key, label: s.label, values: s.values, neutral: s.other })),
    };
}

/**
 * Line and area series. A period where EVERY series is empty is dropped; a single missing value among
 * present ones is drawn at 0, and `substituted` reports that it happened.
 */
export function toLines(result: Result): { series: ChartSeries[]; substituted: boolean } {
    const keep = result.categories
        .map((_, index) => index)
        .filter((index) => result.series.some((s) => s.values[index] !== null && s.values[index] !== undefined));

    let substituted = false;

    const series = result.series.map((s) => ({
        key: s.key,
        label: s.label,
        points: keep.map((index) => {
            const value = s.values[index];

            if (value === null || value === undefined) substituted = true;

            return { label: result.categories[index].label, value: value ?? 0 };
        }),
    }));

    return { series, substituted };
}

/** Donut slices: the positive values of the first series, with the total they are shares of. */
export function toDonut(result: Result): { data: BarDatum[]; total: number } {
    const data = toBarData(result).filter((d) => d.value > 0);

    return { data, total: data.reduce((sum, d) => sum + d.value, 0) };
}

export interface TableView {
    /** The first header names the group-by; the rest name the series. */
    headers: string[];
    rows: { key: string; label: string; cells: string[] }[];
}

/** The result as a plain table — the form every chart can fall back to, and the Table chart itself. */
export function toTable(result: Result): TableView {
    if (result.categories.length === 0) {
        return {
            headers: ['', ...result.measures.map((m) => m.label)],
            rows: [{ key: 'total', label: 'Total', cells: result.totals.values.map(formatNumber) }],
        };
    }

    const first = result.dimensions[0]?.label ?? '';
    const second = result.dimensions[1]?.label;

    return {
        headers: [second === undefined ? first : `${first} / ${second}`, ...result.series.map((s) => s.label)],
        rows: result.categories.map((category, index) => ({
            key: category.key,
            label: category.label,
            cells: result.series.map((s) => formatNumber(s.values[index])),
        })),
    };
}

/** One tile per value, for the Number chart. */
export function toTiles(result: Result): { key: string; label: string; value: string; caption: string | undefined }[] {
    return result.measures.map((measure, index) => {
        const coverage = result.totals.coverage[index];

        return {
            key: measure.key,
            label: measure.label,
            value: formatNumber(result.totals.values[index]),
            caption:
                coverage === null || coverage === undefined
                    ? undefined
                    : `From ${formatNumber(coverage)} of ${formatNumber(result.totals.rows)} ${result.meta.row_noun}`,
        };
    });
}

/**
 * What the page says under a chart: how much of the data each value could read, what was folded away, and
 * whatever the source itself declared it left out.
 */
export function disclosures(result: Result): string[] {
    const notes: string[] = [];

    result.measures.forEach((measure, index) => {
        const coverage = result.totals.coverage[index];

        if (coverage !== null && coverage !== undefined && coverage < result.totals.rows) {
            notes.push(
                `${measure.label}: ${formatNumber(coverage)} of ${formatNumber(result.totals.rows)} ${result.meta.row_noun} have a value. The rest are left out of it.`,
            );
        }
    });

    if (result.meta.hidden_groups > 0) {
        const additive = result.measures.every((m) => m.additive);
        const foldedGroups =
            result.meta.hidden_groups === 1 ? '1 smaller group is' : `${formatNumber(result.meta.hidden_groups)} smaller groups are`;

        notes.push(
            additive
                ? `${foldedGroups} added together as “Other”.`
                : `${foldedGroups} folded into “Other”, which has no value for an average, a median, a minimum or a maximum.`,
        );
    }

    if (result.meta.hidden_series > 0 && !result.series.some((s) => s.other)) {
        notes.push(`${formatNumber(result.meta.hidden_series)} more series are not shown: a chart has five colours.`);
    }

    return [...notes, ...result.meta.notes];
}

/** "Submitted 1 Mar 2026 – 31 Mar 2026 · Asia/Manila · 374 responses". */
export function rangeSentence(result: Result): string {
    const day = (iso: string): string =>
        new Date(`${iso}T00:00:00Z`).toLocaleDateString(undefined, {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone: 'UTC',
        });

    return `${result.meta.range_label} ${day(result.meta.from)} – ${day(result.meta.to)} · ${result.meta.timezone} · ${formatNumber(result.totals.rows)} ${result.meta.row_noun}`;
}
