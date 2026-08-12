/**
 * The wire shape of one row on the forms list (JR3).
 *
 * Extracted from `forms/Index.vue` when the card grid split the page into three components that all
 * describe the same row. It mirrors `FormPresenter::present()` exactly — including the three blocks JR3
 * added, whose provenance is worth stating because two of the three read as free and only one was:
 *
 *   - `description` HAD always been on the wire and was rendered nowhere. Genuinely free.
 *   - `schedule` was NOT. `opens_at`/`closes_at`/`max_responses` are on the Eloquent model (the list
 *     does a bare `select *`) but the presenter never emitted them, so this needed a server change.
 *   - `stats` and `identity` are new on this page; the hub computed the same counts one form at a time.
 */
export type FormVersionRow = {
    id: string;
    version_number: number;
    status: string;
    published_at: string | null;
};

/** `App\Support\Forms\FormScheduleView::present()`. Shared with the guest runtime and the encode screen. */
export type FormScheduleBlock = {
    opens_at: string | null;
    closes_at: string | null;
    timezone: string;
    max_responses: number | null;
    /** `open` | `opens_soon` | `closed` | `capacity_reached` — richer than a boolean, and existing vocabulary. */
    acceptance: string;
    /** Cap headroom; null when the form is uncapped, which is also when no count was issued for it. */
    remaining: number | null;
};

export type FormStatsBlock = {
    responses: number;
    drafts: number;
    /** The driver's own timestamp string, matching what the form hub emits so the two stay comparable. */
    last_response_at: string | null;
};

export type FormRow = {
    id: string;
    title: string;
    description: string | null;
    status: string;
    scope_node_id: string | null;
    current_version: number | null;
    draft_version: number | null;
    updated_at: string | null;
    stats: FormStatsBlock;
    schedule: FormScheduleBlock;
    /** 1–6, the identity hue's index. Server-derived from the form id so it never moves. */
    identity: number;
    versions: FormVersionRow[];
    can: {
        edit: boolean;
        publish: boolean;
        delete: boolean;
        encode: boolean;
        template: boolean;
        analytics: boolean;
    };
};

/**
 * The six identity hues, spelled out in full.
 *
 * ⚠️ THIS ARRAY EXISTS BECAUSE A TEMPLATE LITERAL DEFEATS THE TOKEN GUARD, AND CI IS WHAT PROVED IT.
 * The first version built the reference as `var(--mds-form-identity-${row.identity})`.
 * `token-references.test.ts` scans every `.vue`/`.ts`/`.css` file for `var(--mds-…)` and asserts each
 * name it finds is actually defined — and its regex stops at the `$`, so what it extracted was
 * `--mds-form-identity-`, a token nobody defines. The suite went red in CI on a gate that had simply
 * never been run locally.
 *
 * Writing the six names out is not a workaround for the guard, it is what lets the guard DO ITS JOB:
 * a dynamically-assembled custom property is invisible to it, so a renamed or deleted token would be
 * caught for every other consumer in the app and silently missed here.
 */
export const FORM_IDENTITY_VARS = [
    'var(--mds-form-identity-1)',
    'var(--mds-form-identity-2)',
    'var(--mds-form-identity-3)',
    'var(--mds-form-identity-4)',
    'var(--mds-form-identity-5)',
    'var(--mds-form-identity-6)',
] as const;

/** The identity index is 1-based and server-derived; anything outside 1–6 falls back to the first hue. */
export function formIdentityVar(index: number): string {
    return FORM_IDENTITY_VARS[index - 1] ?? FORM_IDENTITY_VARS[0];
}

export type FormListFacet = {
    /** null is the "All" chip — deliberately not a magic string, since it maps to an absent `?state`. */
    value: string | null;
    label: string;
    count: number;
};
