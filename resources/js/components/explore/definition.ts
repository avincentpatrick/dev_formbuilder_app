// Pure helpers over an explorer declaration (M236). No Vue, no fetch, no clock: `today` is always passed in,
// so every function here is a table of inputs and outputs under test.
//
// The server is the authority on what a declaration may hold (`ExplorerQuery`). These helpers exist so the
// builder never SENDS one the server would refuse for a reason the reader did not cause — switching to a
// donut with two group-bys chosen, changing the form under a chart that names another form's question.

import type { Aggregate, ChartOption, Definition, Field, FieldType, FilterOperator, Source } from './types';

const DAY_MS = 86_400_000;

/** A local calendar date as `YYYY-MM-DD`. Never `toISOString()`, which is the UTC date. */
export function isoDate(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** The inclusive range of `days` days ending on `today`. */
export function rangeEnding(today: string, days: number): { from: string; to: string } {
    const end = new Date(`${today}T00:00:00Z`);
    const start = new Date(end.getTime() - (Math.max(1, days) - 1) * DAY_MS);

    return { from: start.toISOString().slice(0, 10), to: today };
}

/** Inclusive length of a range in days, or null when either end is not a date. */
export function spanInDays(from: string, to: string): number | null {
    const start = Date.parse(`${from}T00:00:00Z`);
    const end = Date.parse(`${to}T00:00:00Z`);

    return Number.isNaN(start) || Number.isNaN(end) ? null : Math.round((end - start) / DAY_MS) + 1;
}

export function isTemporal(type: FieldType): boolean {
    return type === 'time' || type === 'date';
}

export function isGroupable(field: Field): boolean {
    return field.type !== 'number';
}

/** The fields a value of this kind can be taken over. A row count takes none. */
export function fieldsForAggregate(aggregate: Aggregate, fields: Field[]): Field[] {
    if (aggregate === 'count') return [];

    if (aggregate === 'count_distinct') {
        return fields.filter((f) => f.type === 'category' || f.type === 'boolean' || f.type === 'number');
    }

    return fields.filter((f) => f.type === 'number');
}

/** Mirrors `ExplorerFilterOperator::appliesTo()`. */
export function operatorApplies(operator: FilterOperator, type: FieldType): boolean {
    if (operator === 'in' || operator === 'not_in') {
        return type === 'category' || type === 'boolean' || type === 'multi_category';
    }

    if (operator === 'gte' || operator === 'lte') return type === 'number';

    return !isTemporal(type);
}

export function isFilterable(field: Field): boolean {
    return !isTemporal(field.type);
}

/** Select groups for a field picker, in the order the server sent the fields, keeping only `keep`. */
export function fieldGroups(
    fields: Field[],
    keep: (field: Field) => boolean,
): { label: string; options: { value: string; label: string }[] }[] {
    const groups: { label: string; options: { value: string; label: string }[] }[] = [];

    for (const field of fields) {
        if (!keep(field)) continue;

        const label = field.group === '' ? 'Fields' : field.group;
        let group = groups.find((g) => g.label === label);

        if (group === undefined) {
            group = { label, options: [] };
            groups.push(group);
        }

        group.options.push({ value: field.key, label: field.label });
    }

    return groups;
}

/** The chart a reader sees first on a source: its rows counted by its first groupable, non-date field. */
export function newDefinition(source: Source, timezone: string, today: string): Definition {
    const first = source.fields.find((f) => isGroupable(f) && !isTemporal(f.type) && f.type !== 'multi_category');

    return {
        source: source.key,
        form_id: null,
        chart: first === undefined ? 'number' : 'bar',
        dimensions: first === undefined ? [] : [{ field: first.key, bucket: null }],
        measures: [{ aggregate: 'count', field: null }],
        filters: [],
        ...rangeEnding(today, source.default_range_days),
        timezone,
        sort: 'value_desc',
        limit: 10,
    };
}

/**
 * Drop whatever names a field that is not in `fields` — after the source or the form under a chart changed.
 * A value that loses its field falls back to a row count, so the chart keeps something to draw.
 */
export function dropUnknownFields(definition: Definition, fields: Field[]): Definition {
    const known = new Set(fields.map((f) => f.key));
    const measures = definition.measures.map((m) =>
        m.field !== null && !known.has(m.field) ? { aggregate: 'count' as const, field: null } : m,
    );

    return {
        ...definition,
        dimensions: definition.dimensions.filter((d) => known.has(d.field)),
        measures: measures.filter((m, i) => measures.findIndex((o) => o.aggregate === m.aggregate && o.field === m.field) === i),
        filters: definition.filters.filter((f) => known.has(f.field)),
    };
}

/**
 * Make a declaration fit a chart type: trim the group-bys and values it cannot draw, and give it a group-by
 * when it needs one and has none.
 */
export function fitToChart(definition: Definition, chart: ChartOption, fields: Field[]): Definition {
    let dimensions = definition.dimensions.slice(0, chart.max_dimensions);

    if (dimensions.length < chart.min_dimensions) {
        const first = fields.find((f) => isGroupable(f) && !isTemporal(f.type) && f.type !== 'multi_category');

        dimensions = first === undefined ? dimensions : [{ field: first.key, bucket: null }];
    }

    const maxMeasures = dimensions.length === 2 ? chart.max_measures_split : chart.max_measures;

    return {
        ...definition,
        chart: chart.value,
        dimensions,
        measures: definition.measures.slice(0, Math.max(1, maxMeasures)),
        // Five colours, so a donut shows five slices and folds the rest.
        limit: chart.value === 'donut' ? Math.min(definition.limit, 5) : definition.limit,
    };
}

/**
 * Why a declaration is not ready to run, in the reader's words — or null when it is. Checked before a
 * request is sent, so a half-built chart shows a prompt instead of a refusal the reader has to decode.
 */
export function incompleteReason(definition: Definition, source: Source | undefined, fields: Field[]): string | null {
    if (source === undefined) return 'Choose a data source.';
    if (source.requires_form && definition.form_id === null) return 'Choose the form whose answers to chart.';

    const byKey = new Map(fields.map((f) => [f.key, f]));

    for (const dimension of definition.dimensions) {
        const field = byKey.get(dimension.field);

        if (field === undefined) return 'Choose what to group by.';
        if (isTemporal(field.type) && dimension.bucket === null) return `Choose how to group ${field.label}.`;
    }

    for (const measure of definition.measures) {
        if (measure.aggregate !== 'count' && (measure.field === null || !byKey.has(measure.field))) {
            return 'Choose the field for each value.';
        }
    }

    for (const filter of definition.filters) {
        if ((filter.operator === 'is_empty' || filter.operator === 'not_empty') === false && filter.values.length === 0) {
            return 'Give each filter a value, or remove it.';
        }
    }

    if (spanInDays(definition.from, definition.to) === null) return 'Choose a date range.';

    return null;
}

/** A value with every object's keys in one order, so two documents can be compared as text. */
function canonical(value: unknown): unknown {
    if (Array.isArray(value)) return value.map(canonical);

    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value as Record<string, unknown>)
                .sort(([a], [b]) => a.localeCompare(b))
                .map(([key, entry]) => [key, canonical(entry)]),
        );
    }

    return value;
}

/**
 * Whether two declarations say the same thing.
 *
 * NOT `JSON.stringify(a) === JSON.stringify(b)`: a saved chart comes back from a `jsonb` column, which
 * returns an object's keys in its own order, so the same declaration reads `{field, bucket}` in the builder
 * and `{bucket, field}` from the server — and "Save changes" would stay lit forever on a chart that has
 * just been saved.
 */
export function sameDefinition(a: Definition, b: Definition): boolean {
    return JSON.stringify(canonical(toPayload(a))) === JSON.stringify(canonical(toPayload(b)));
}

/** The body the server reads: the declaration without the version key, which it alone writes. */
export function toPayload(definition: Definition): Definition {
    const { v: _version, ...rest } = definition;

    return rest;
}
