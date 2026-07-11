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
    under_review: { variant: 'warning', label: 'Under review' },
    approved: { variant: 'success', label: 'Approved' },
    returned: { variant: 'danger', label: 'Returned' },
};

/** Resolve a status string to its badge {variant,label}. Unknown values fall back to a neutral pill
 *  labelled with the raw value (never throws, never renders an unlabelled colour-only badge). */
export function statusVariant(value: string): StatusDescriptor {
    return STATUS[value] ?? { variant: 'neutral', label: value };
}
