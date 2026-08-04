// The `/analytics` wire shapes (Increment H24b2), derived LITERALLY from AnalyticsPresenter's PHP
// `@return` annotations. Every nullable below is nullable on the server too, and that is not pedantry:
// typing `change: number` where the server sends `null` type-checks cleanly and renders "NaN%" in
// production, which is precisely the class of defect `vue-tsc` is in CI to prevent.

export type Axis = 'form' | 'source' | 'status' | 'scope_node' | 'locale';
export type Granularity = 'day' | 'week' | 'month';
export type Selection = 'all' | 'forms' | 'scope_node';

/** AnalyticsQuery::toArray() verbatim — the same shape a saved view stores in `definition`. */
export interface AppliedQuery {
    /**
     * The declaration's schema version. READ ONLY on the client: `toQueryParams()` never echoes it back,
     * because AnalyticsQuery is the single author of it server-side and a second writer is how a stored
     * definition comes to carry a version nothing wrote.
     */
    v: number;
    from: string;
    to: string;
    timezone: string;
    granularity: Granularity;
    selection: Selection;
    form_ids: string[];
    scope_node_id: string | null;
    axis: Axis;
    statuses: string[];
    sources: string[];
    locales: string[];
    top_n: number;
}

export interface Option {
    value: string;
    label: string;
}

export interface ScopeNodeOption extends Option {
    depth: number;
    is_active: boolean;
}

export interface FilterOptions {
    forms: Option[];
    scope_nodes: ScopeNodeOption[];
    locales: Option[];
    axes: Option[];
    granularities: Option[];
    selections: Option[];
    statuses: Option[];
    sources: Option[];
    timezones: string[];
    limits: {
        max_range_days: number;
        max_form_ids: number;
        max_top_n: number;
        default_top_n: number;
        default_range_days: number;
    };
}

export interface SeriesPoint {
    bucket: string;
    count: number;
}

export interface BreakdownRow {
    key: string | null;
    label: string;
    count: number;
}

export interface Breakdown {
    axis: Axis;
    rows: BreakdownRow[];
    /** `null` means nothing overflowed the top-N — NOT that the Other bucket is empty. */
    other: { count: number; categories: number } | null;
    /** Always present, even at 0, and never inside `rows`. */
    unassigned: number;
    unassigned_label: string;
    has_unassigned_bucket: boolean;
}

/**
 * ADR-0011 §D5. THREE terminal states, never two:
 *   · `suppressed: true` — the range reaches past draft retention, so a rate would rise every night as
 *     the reaper deletes its own denominator.
 *   · `suppressed: false, denominator: 0` — no drafts were explicitly saved at all.
 *   · a real denominator — where `median_seconds` may STILL be null, because `percentile_cont` over an
 *     empty set returns NULL when nothing has converted yet. That third case is why the two tiles cannot
 *     share one sentence; see `draft-metrics.ts`.
 */
export interface DraftMetrics {
    suppressed: boolean;
    reason: string | null;
    denominator: number;
    converted: number | null;
    conversion_rate: number | null;
    median_seconds: number | null;
}

export interface Report {
    range: { from: string; to: string; timezone: string; granularity: Granularity };
    prior_range: { from: string; to: string };
    /** `change === null` means the prior period held NO rows — undefined, not zero and not +100%. */
    total: { current: number; prior: number; change: number | null };
    series: SeriesPoint[];
    breakdown: Breakdown;
    drafts: DraftMetrics;
    forms_accepting: number;
    week_starts_on: 'monday';
}

/** AnswerValueAggregator::questions() verbatim. The picker ENCODES these refusals rather than deriving them. */
export interface QuestionRow {
    key: string;
    label: string;
    field_type: string;
    indexed_data_type: string | null;
    reportable: boolean;
    refusal: string | null;
    refusal_label: string | null;
    first_indexed_version: number | null;
    type_changed_at_version: number | null;
}

/** AnswerValueAggregator::aggregate(), plus the `label` the presenter resolves. */
export interface QuestionAggregate {
    key: string;
    label: string;
    refused: boolean;
    reason: string | null;
    message: string | null;
    indexed_data_type: string | null;
    first_indexed_version: number | null;
    type_changed_at_version: number | null;
    /** §D3(iii): the projector drops rows silently WITHIN a flagged field, so coverage is always disclosed. */
    coverage: { indexed: number; countable: number };
    /** False for `boolean` only — `value_boolean` carries no B-tree. A perf disclosure, not a refusal. */
    indexed_column: boolean;
    rows: { key: string | null; count: number }[] | null;
    other: { count: number; categories: number } | null;
    summary: { count: number; min: number | null; max: number | null; average: number | null; median: number | null } | null;
}

export interface SavedView {
    id: string;
    name: string;
    definition: Record<string, unknown>;
    refused: boolean;
    reason: string | null;
    message: string | null;
    /** Ids in the definition that no longer resolve — a disclosure, not a refusal. */
    stale_form_ids: string[];
    is_applied: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface SelectionRefusal {
    reason: string;
    message: string;
}

export interface Summary {
    exports: { used: number; limit: number | null };
}
