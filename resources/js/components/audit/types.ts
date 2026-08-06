/**
 * The audit-log page's prop contract (Increment I2), mirrored from `App\Services\Audit\AuditLogPresenter`.
 *
 * Lives beside the components rather than inside `Pages/audit/Index.vue` for the reason
 * `components/analytics/types.ts` does: the page TEST imports these types to build its prop factories, so
 * a fixture that drifts from the real shape fails type-check instead of passing a test against a shape the
 * server never sends.
 */

/** A `{value,label}` catalog entry — filter dropdowns everywhere in this app use this shape. */
export type Option = { value: string; label: string };

/**
 * One changed key, already stringified server-side by `App\Support\Audit\AuditDiff`.
 *
 * `null` means the key was ABSENT from that side's payload — deliberately distinct from the em-dash the
 * server renders for a key that is present and holds null.
 *
 * `redacted` means both `old` and `new` carry the placeholder because the value was never stored
 * (audit-compliance-logging-spec §2.1). The renderer must not present either side as a real value.
 */
export type AuditChange = {
    key: string;
    old: string | null;
    new: string | null;
    redacted: boolean;
};

/**
 * What the row is ABOUT.
 *
 * `label` and `url` are legitimately null and the page renders that state without apology: the ledger is
 * append-only but its targets are not (a revoked grant is hard-deleted and this row is the only record it
 * existed), and half the aliases have no addressable page at all. Resolution is the server's job — only it
 * knows whether the target still exists.
 */
export type AuditTarget = {
    type: string;
    type_label: string;
    id: string;
    label: string | null;
    url: string | null;
};

export type AuditRow = {
    id: string;
    created_at: string | null;
    event: string;
    /** From `AuditEvent::label()` — the SERVER owns the wording, so the badge, the filter and the CSV agree. */
    event_label: string;
    target: AuditTarget;
    /** A name, or "System" (no actor), or "Unknown user" (an actor whose row is no longer visible). */
    actor: string;
    is_system: boolean;
    ip_address: string | null;
    changes: AuditChange[];
    redacted_fields: string[];
};

export type AuditMeta = { current_page: number; last_page: number; total: number; per_page: number };

export type AuditFilters = {
    events: Option[];
    auditable_types: Option[];
    actors: Option[];
    applied: {
        auditable_type: string | null;
        event: string | null;
        user_id: string | null;
        from: string | null;
        to: string | null;
    };
};

/**
 * Server-computed, never inferred on the client. `no_rows` = this tenant has no ledger yet; `no_matches` =
 * the filters excluded everything. Guessing from `data.length === 0` breaks the moment the server defaults
 * or clamps anything, and would tell an owner holding thousands of rows that nothing was ever recorded.
 */
export type EmptyReason = 'no_rows' | 'no_matches' | null;

export type AuditPageProps = {
    data: AuditRow[];
    meta: AuditMeta;
    filters: AuditFilters;
    empty_reason: EmptyReason;
    can: { export: boolean };
};
