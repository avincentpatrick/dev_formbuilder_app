// The SINGLE enum → badge mapping (DSR §3.8 governing rule): the mapping from a domain status value
// to its badge colour + label is defined ONCE here and consumed by every screen that renders that
// status, so `active` is the same green everywhere (Members roster, admin tenant list, …). Covers
// `TenantUserStatus` (invited/active/suspended/declined/removed) and `TenantStatus` (active/suspended)
// — their shared values (active/suspended) intentionally resolve to the same descriptor.

export type BadgeVariant = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export interface StatusDescriptor {
    variant: BadgeVariant;
    label: string;
}

const STATUS: Record<string, StatusDescriptor> = {
    active: { variant: 'success', label: 'Active' },
    invited: { variant: 'info', label: 'Pending' },
    suspended: { variant: 'warning', label: 'Suspended' },
    declined: { variant: 'neutral', label: 'Declined' },
    removed: { variant: 'neutral', label: 'Removed' },
    // Form + form-version lifecycle (Increment D): FormStatus (draft/published/archived) and
    // FormVersionStatus (draft/published/superseded) — the shared `draft`/`published` values
    // intentionally resolve to one descriptor everywhere.
    draft: { variant: 'neutral', label: 'Draft' },
    published: { variant: 'success', label: 'Published' },
    superseded: { variant: 'info', label: 'Superseded' },
    archived: { variant: 'warning', label: 'Archived' },
    // Submission review lifecycle (Increment F7): SubmissionStatus. `draft`/`archived` are shared with the
    // form lifecycle above and intentionally reuse those descriptors.
    submitted: { variant: 'info', label: 'Submitted' },
    // `screened_out` (Increment I9a) is NEUTRAL, for the same reason `wont_fix`/`disabled`/`revoked` are:
    // it is a settled non-failure. Nothing went wrong — the respondent finalized a form that had no
    // questions left to show them — and red would read as "this response errored".
    screened_out: { variant: 'neutral', label: 'Screened out' },
    under_review: { variant: 'warning', label: 'Under review' },
    approved: { variant: 'success', label: 'Approved' },
    returned: { variant: 'danger', label: 'Returned' },
    // Webhook endpoint lifecycle (Increment H14): WebhookEndpointStatus (active/paused/disabled). `active`
    // reuses the shared success descriptor above. `paused` = circuit-breaker auto-pause (needs attention)
    // → amber; `disabled` = manually/platform off (intentionally inert) → neutral.
    paused: { variant: 'warning', label: 'Paused' },
    disabled: { variant: 'neutral', label: 'Disabled' },
    // Webhook delivery lifecycle (Increment H14): WebhookDeliveryStatus. `failed` still has retries pending
    // (transient) → amber; `dead_lettered` is terminal (exhausted / quota-refused) → red.
    pending: { variant: 'neutral', label: 'Pending' },
    delivering: { variant: 'info', label: 'Delivering' },
    succeeded: { variant: 'success', label: 'Succeeded' },
    failed: { variant: 'warning', label: 'Failed' },
    dead_lettered: { variant: 'danger', label: 'Dead-lettered' },
    // Native-connector grant lifecycle (Increment H15b): ConnectionStatus (active/refresh_failed/revoked).
    // `active` reuses the shared success descriptor. The other two are labelled by what the tenant must DO
    // rather than by the enum's own word — neither "Refresh failed" nor "Revoked" tells anyone what happens
    // next. `refresh_failed` is red because the grant died on its own and deliveries are silently stopping;
    // `revoked` is neutral because the tenant disconnected it on purpose and nothing is wrong.
    refresh_failed: { variant: 'danger', label: 'Reconnect needed' },
    revoked: { variant: 'neutral', label: 'Disconnected' },
    // Custom-domain lifecycle (Increment H22b / ADR-0012): pending → verified → live. `pending` reuses the
    // shared neutral descriptor above, exactly as `draft`/`archived` are shared.
    //
    // `verified` is labelled "Awaiting setup", NOT "Verified", and the difference is the whole point of the
    // H15b rule about labelling by what happens next: the tenant HAS finished its part, and a badge reading
    // "Verified" beside a hostname that serves nothing is the single most misleading thing this page could
    // say. INFO rather than success for the same reason — success would claim the job is done. ADR-0012 §D6
    // makes the remaining step an operator's, so the badge names the wait rather than the achievement.
    verified: { variant: 'info', label: 'Awaiting setup' },
    live: { variant: 'success', label: 'Live' },
    // In-app feedback triage (Increment I7a): FeedbackStatus (new/reviewed/resolved/wont_fix). The shape
    // mirrors the submission review lifecycle above, because it IS the same shape — arrived (info) →
    // in hand (warning) → closed well (success) — and two queues in one product should not colour the
    // same idea two ways. `wont_fix` is neutral for the reason `disabled`/`revoked` are: it is a
    // deliberate, settled decision, not a failure, and red would read as one. Note none of these four
    // words collide with an existing key, so nothing above changes meaning.
    new: { variant: 'info', label: 'New' },
    reviewed: { variant: 'warning', label: 'Reviewed' },
    resolved: { variant: 'success', label: 'Resolved' },
    wont_fix: { variant: 'neutral', label: "Won't fix" },
};

/** Resolve a status string to its badge {variant,label}. Unknown values fall back to a neutral pill
 *  labelled with the raw value (never throws, never renders an unlabelled colour-only badge). */
export function statusVariant(value: string): StatusDescriptor {
    return STATUS[value] ?? { variant: 'neutral', label: value };
}
