// The `/explore` wire shapes (M236, ADR-0025), derived from the PHP `@return` annotations of
// `ExplorerPresenter` and `ExplorerQueryRunner`. Every nullable below is nullable on the server too: a
// value an "Other" bucket cannot carry arrives as `null`, and typing it `number` would render "NaN".

export type ChartType = 'bar' | 'column' | 'stacked' | 'line' | 'area' | 'donut' | 'table' | 'number';
export type Aggregate = 'count' | 'count_distinct' | 'sum' | 'avg' | 'min' | 'max' | 'median';
export type Bucket = 'day' | 'week' | 'month' | 'quarter' | 'year' | 'weekday' | 'hour';
export type FieldType = 'category' | 'boolean' | 'multi_category' | 'number' | 'time' | 'date';
export type FilterOperator = 'in' | 'not_in' | 'gte' | 'lte' | 'is_empty' | 'not_empty';
export type Sort = 'value_desc' | 'value_asc' | 'label';

export type Dimension = {
    field: string;
    bucket: Bucket | null;
};

export type Measure = {
    aggregate: Aggregate;
    field: string | null;
};

export type Filter = {
    field: string;
    operator: FilterOperator;
    values: string[];
};

/**
 * `ExplorerQuery::toArray()` verbatim — what the builder edits, what a saved chart stores.
 *
 * A `type`, like the three above it, and not an `interface`: Inertia's request body is typed by an index
 * signature, which a type alias satisfies structurally and an interface does not.
 */
export type Definition = {
    /** READ ONLY on the client: the server is the single author of the stored shape's version. */
    v?: number;
    source: string;
    form_id: string | null;
    chart: ChartType;
    dimensions: Dimension[];
    measures: Measure[];
    filters: Filter[];
    from: string;
    to: string;
    timezone: string;
    sort: Sort;
    limit: number;
};

export interface Option {
    value: string;
    label: string;
}

export interface Field {
    key: string;
    label: string;
    type: FieldType;
    /** The heading the field sits under in a picker. */
    group: string;
    /** The values a filter on this field can pick from, where the server knows them. */
    options: Option[] | null;
}

export interface Source {
    key: string;
    label: string;
    description: string;
    row_noun: string;
    requires_form: boolean;
    default_range_days: number;
    /** The key of the field the chart's date range applies to. */
    range_field: string;
    /** For a source that needs a form: only the fields every form has. The questions follow by fetch. */
    fields: Field[];
}

export interface ChartOption extends Option {
    value: ChartType;
    min_dimensions: number;
    max_dimensions: number;
    /** The most values with one group-by, and with two. */
    max_measures: number;
    max_measures_split: number;
}

export interface BuilderOptions {
    charts: ChartOption[];
    aggregates: (Option & { value: Aggregate; needs_field: boolean })[];
    buckets: (Option & { value: Bucket })[];
    operators: (Option & { value: FilterOperator; takes_values: boolean })[];
    sorts: (Option & { value: Sort })[];
    timezones: string[];
    limits: {
        max_dimensions: number;
        max_measures: number;
        max_filters: number;
        max_range_days: number;
        max_limit: number;
        default_limit: number;
    };
}

export interface SavedChart {
    id: string;
    name: string;
    definition: Definition;
    is_shared: boolean;
    is_mine: boolean;
    /** The author's name, for a chart that is not the reader's own. */
    owner: string | null;
    source_label: string | null;
    can: { update: boolean; delete: boolean };
    updated_at: string | null;
}

export interface ResultCategory {
    key: string;
    label: string;
    /** The folded "Other (N)" bucket — painted neutral, never a recycled hue. */
    other: boolean;
}

export interface ResultSeries {
    key: string;
    label: string;
    other: boolean;
    /** One per category. `null` is "no value", which is not zero. */
    values: (number | null)[];
}

export interface Result {
    chart: ChartType;
    dimensions: { key: string; label: string; type: FieldType; bucket: Bucket | null }[];
    measures: { key: string; label: string; aggregate: Aggregate; additive: boolean }[];
    categories: ResultCategory[];
    series: ResultSeries[];
    totals: {
        rows: number;
        values: (number | null)[];
        /** For a value taken over a field: how many rows had that field. `null` for a row count. */
        coverage: (number | null)[];
    };
    meta: {
        source: string;
        source_label: string;
        row_noun: string;
        form: { id: string; title: string } | null;
        from: string;
        to: string;
        timezone: string;
        range_label: string;
        groups: number;
        hidden_groups: number;
        hidden_series: number;
        capped: boolean;
        notes: string[];
    };
}

export interface Refusal {
    reason: string;
    message: string;
}

/** Exactly one of the two is non-null. */
export interface RunPayload {
    result: Result | null;
    refusal: Refusal | null;
}

export interface FieldsPayload {
    fields: Field[];
    notes: string[];
}
