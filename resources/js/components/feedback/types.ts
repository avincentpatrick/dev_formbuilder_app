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

/**
 * What a tester may do with a report (M179, `D151`) — computed by the SERVER from the member graph and the
 * policy, so the buttons and the server's guard cannot disagree. `confirm` is "It works", `reopen` is
 * "Still a problem".
 */
export type FeedbackAction = { verb: 'confirm' | 'reopen'; label: string };

/** One message in a report's thread (M179). `author` is "Support team", a member's name, or "Former member". */
export type FeedbackComment = {
    id: string;
    author: string;
    author_kind: 'member' | 'support';
    /** Set on the message a tester's move wrote: `confirmed` (It works) or `reopened` (Still a problem). */
    action: 'confirmed' | 'reopened' | null;
    body: string | null;
    created_at: string;
    /** Named, never shown — ADR-0015 §D9 serves a feedback screenshot under `feedback.view` only. */
    has_screenshot: boolean;
};

/** What every tenant-side row carries since M179: where it came from, our note, the thread, and the viewer's moves. */
export type FeedbackThreadFields = {
    /** `button` (the Feedback button) or `relayed` (a comment the team loaded, every member sees it). */
    source: 'button' | 'relayed';
    /** Where a relayed report came from, as its card says it ("Your items, Oct 16"). */
    source_label: string | null;
    /** The code the testers know a relayed report by (`U4`, `R1-7a`). */
    reference: string | null;
    /** What a relayed report is called; a button report has none and shows its page. */
    title: string | null;
    /** The support team's note (M175) — on the Owner/Admin list too since M179. */
    resolution_note: string | null;
    confirmed_at: string | null;
    /** Who pressed It works; null when nobody has, or when they have since left. */
    confirmer: string | null;
    comments: FeedbackComment[];
    actions: FeedbackAction[];
    can_comment: boolean;
};

/** One row on the tenant's own list. The SUPPORT lifecycle belongs to the console; the tester's moves are `actions`. */
export type FeedbackRow = FeedbackThreadFields & {
    id: string;
    route: string;
    /** The full address (M175) — rendered as a link, so the server accepts an http(s) address only. */
    page_url: string | null;
    page_title: string | null;
    /** `bug` / `suggestion` / `question` (M175); null on a report sent before the kind existed. */
    type: string | null;
    type_label: string | null;
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
        types: FilterOption[];
        /** Where a report came from — the Feedback button, or relayed (M179). */
        sources: FilterOption[];
        /** `q` is the CLAMPED string the server ran, not the raw input — see `SearchTerms::raw()` (J1e). */
        applied: { status: string | null; type: string | null; source: string | null; q: string | null };
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
    page_url: string | null;
    page_title: string | null;
    type: string | null;
    type_label: string | null;
    remarks: string;
    browser_info: Record<string, unknown>;
    status: string;
    status_label: string;
    submitted_at: string;
    resolved_at: string | null;
    /** The support team's reply to the reporter (M175); null until one is written. */
    resolution_note?: string | null;
    reporter: FeedbackPerson | null;
    /** The reporter's role in words when they sent it (M175); null on older reports. */
    reporter_role_label?: string | null;
    resolver: FeedbackPerson | null;
    has_screenshot: boolean;
    /** Straight from FeedbackStatus::allowedTransitions(Support) — the same source the server guards with. */
    transitions: FilterOption[];
    /** M179 — where it came from, the code the testers know it by, who confirmed it, and the thread. */
    source?: 'button' | 'relayed';
    source_label?: string | null;
    reference?: string | null;
    title?: string | null;
    confirmer?: FeedbackPerson | null;
    comments?: ConsoleFeedbackComment[];
};

/** One message in a report's thread, as the console reads it (M179): `author` is null on a support reply. */
export type ConsoleFeedbackComment = {
    id: string;
    author_kind: 'member' | 'support';
    author: FeedbackPerson | null;
    action: 'confirmed' | 'reopened' | null;
    body: string | null;
    created_at: string;
    has_screenshot: boolean;
};

/**
 * One of the signed-in member's OWN reports — "My reports" (M175, `R-9bfd832e`). The screenshot is named by
 * `has_screenshot` and never linked: ADR-0015 §D9 serves it under `feedback.view` or not at all.
 */
export type MyFeedbackRow = FeedbackThreadFields & {
    id: string;
    type: string | null;
    type_label: string | null;
    route: string;
    page_url: string | null;
    page_title: string | null;
    remarks: string;
    status: string;
    status_label: string;
    submitted_at: string;
    resolved_at: string | null;
    resolution_note: string | null;
    has_screenshot: boolean;
};

export type MyFeedbackPageProps = {
    data: MyFeedbackRow[];
    meta: PageMeta;
    empty_reason: EmptyReason;
    /** M179 (`D150`) — every relayed report of the workspace; the tab shows only while `open` is above zero. */
    testing_round: { open: number; data: MyFeedbackRow[] };
    /** The tab the page opens on — `testing` when asked for (`?view=testing`) or when the member sent nothing. */
    view: 'mine' | 'testing';
};

export type ConsoleFeedbackPageProps = {
    data: ConsoleFeedbackRow[];
    meta: PageMeta;
    filters: {
        statuses: FilterOption[];
        tenants: FilterOption[];
        types: FilterOption[];
        applied: { status: string | null; tenant_id: string | null; type: string | null };
    };
    empty_reason: EmptyReason;
};
