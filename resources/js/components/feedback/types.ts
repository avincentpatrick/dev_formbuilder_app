/**
 * Prop contracts for the two feedback surfaces (Increment I7a, PRD Feature #11) — the tenant's read-only
 * list and the platform support console. Kept beside the components rather than inline in the pages for
 * the reason `components/audit/types.ts` is: the Vitest suites import these, so a fixture that drifts from
 * what the presenter actually ships fails type-check instead of passing a test against a fiction.
 *
 * ⚠️ **`type` ALIASES, NOT `interface`, AND THE DIFFERENCE IS LOAD-BEARING HERE.** `MdsDataTable` is
 * generic over `Row extends Record<string, unknown>`. TypeScript grants an *implicit index signature* to a
 * type alias but never to an interface, so an interface-shaped row is not assignable to the table's
 * generic and every `#cell-*` cast fails too. `components/audit/types.ts` uses aliases for exactly this
 * reason; converting these to interfaces breaks the build, not the style.
 */

export type FilterOption = { value: string; label: string };

export type PageMeta = { current_page: number; last_page: number; total: number; per_page: number };

export type EmptyReason = 'no_rows' | 'no_matches' | null;

/** One row on the tenant's own list. No transitions — the lifecycle belongs to the platform console. */
export type FeedbackRow = {
    id: string;
    route: string;
    remarks: string;
    status: string;
    /** From `FeedbackStatus::label()` — the SERVER owns the wording, so badge and filter agree. */
    status_label: string;
    submitted_at: string;
    resolved_at: string | null;
    /** Null when the reporter is no longer a visible member — NOT the same as "the system did it". */
    reporter: string | null;
    has_screenshot: boolean;
};

export type FeedbackPageProps = {
    data: FeedbackRow[];
    meta: PageMeta;
    filters: {
        statuses: FilterOption[];
        /** `q` is the CLAMPED string the server ran, not the raw input — see `SearchTerms::raw()` (J1e). */
        applied: { status: string | null; q: string | null };
    };
    empty_reason: EmptyReason;
};

export type FeedbackPerson = { name: string; email: string };

/** One row in the platform console: everything above, plus who it came from and what may happen next. */
export type ConsoleFeedbackRow = {
    id: string;
    tenant_id: string;
    tenant_name: string;
    route: string;
    remarks: string;
    browser_info: Record<string, unknown>;
    status: string;
    status_label: string;
    submitted_at: string;
    resolved_at: string | null;
    reporter: FeedbackPerson | null;
    resolver: FeedbackPerson | null;
    has_screenshot: boolean;
    /** Straight from FeedbackStatus::allowedTransitions() — the same source the server guards with. */
    transitions: FilterOption[];
};

export type ConsoleFeedbackPageProps = {
    data: ConsoleFeedbackRow[];
    meta: PageMeta;
    filters: {
        statuses: FilterOption[];
        tenants: FilterOption[];
        applied: { status: string | null; tenant_id: string | null };
    };
    empty_reason: EmptyReason;
};
