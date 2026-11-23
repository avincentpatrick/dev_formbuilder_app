// The `/explore` wire shapes (M236, ADR-0025), derived from the PHP `@return` annotations of
// `ExplorerPresenter` and `ExplorerQueryRunner`. Every nullable below is nullable on the server too: a
// value an "Other" bucket cannot carry arrives as `null`, and typing it `number` would render "NaN".

export type ChartType = 'bar' | 'column' | 'stacked' | 'line' | 'area' | 'donut' | 'table' | 'number' | 'agreement';
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
    /** M247 (`D231`): the grid a "Rows of a grid" chart counts. Absent or null for every other source. */
    grid?: string | null;
    /** M250 (`D234`): what an agreement list counts. Absent or null for every other chart. */
    agreement?: AgreementSpec | null;
};

/** M250 (`D234`): `ExplorerAgreement::toArray()` — the questions on the list and how agreement is counted. */
export type AgreementSpec = {
    /** Picks: `answer:{key}`, `grid:{key}:{column}` (every row of a table's column) or `grid:{key}` (a Likert grid). */
    items: string[];
    /** Answers that are not a view, such as "No view or opinion". */
    leave_out: string[];
    /** Answers that count together as agreeing, where an item offers one; empty means each item's most common answer. */
    agreeing: string[];
    threshold: number;
    min_views: number;
};

/** M250: one thing an author may tick — a choice question, a table's choice column, or a Likert grid. */
export interface AgreementPick {
    value: string;
    /** The question's wording, or for a table the column's. */
    label: string;
    question: string;
    column: string | null;
    section: string;
    kind: 'question' | 'table_column' | 'scale_table';
    multiple: boolean;
    /** How many items it puts on the list: 0 for a question (itself), the rows for a table. */
    rows: number;
    options: Option[];
}

export type AgreementStatus = 'short_list' | 'discuss' | 'too_few';

export interface AgreementLine {
    key: string;
    pick: string;
    /** The question's wording, or a table row's label. */
    label: string;
    question: string;
    column: string | null;
    section: string;
    position: number;
    multiple: boolean;
    responses: number;
    views: number;
    left_out: number;
    answers: { value: string; label: string; responses: number; left_out: boolean; counted: boolean }[];
    basis: 'agreeing' | 'most_common';
    counted: Option[];
    agreeing: number;
    /** Rounded down to one decimal; null when nobody gave a view. */
    share: number | null;
    status: AgreementStatus;
}

export interface AgreementResult {
    threshold: number;
    min_views: number;
    leave_out: Option[];
    agreeing: Option[];
    summary: { items: number; short_list: number; discuss: number; too_few: number };
    items: AgreementLine[];
}

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
    /** M247 (`D231`): a chart on this source names one of the form's grids too. */
    requires_grid?: boolean;
    /** M250 (`D234`): the chart types this source can fill; absent means every one but the agreement list. */
    charts?: ChartType[];
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
    /** M250 (`D234`): an agreement list's bounds and the values a new one opens with. */
    agreement?: {
        max_picks: number;
        max_items: number;
        max_marks: number;
        min_threshold: number;
        max_threshold: number;
        default_threshold: number;
        max_min_views: number;
        default_min_views: number;
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
    /** M250: `export` — a saved agreement list this reader may download (`submissions.export` on its form). */
    can: { update: boolean; delete: boolean; export?: boolean };
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
    /** M250 (`D234`): an agreement list's lines; its chart has no categories or series. */
    agreement?: AgreementResult;
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
    /** M247: on a source that reads one grid, the form's grids it may chart. */
    grids?: Option[];
    /** M250 (`D234`): on the agreement source, what an author may put on the list, in the form's order. */
    items?: AgreementPick[];
}
