/**
 * How one outbox row is described to the respondent (Increment I10d) — the four states
 * `docs/PRD.md:223` requires plus the conflict state `docs/ux/form-filling-ux-flow.md` §7.1 adds.
 *
 * ── WHY THIS IS LOCAL AND NOT THE DESIGN SYSTEM'S `statusVariant()` ─────────────────────────────────────
 * It borrows that helper's TYPES so the badges speak one visual vocabulary, but not its table, for a
 * concrete reason rather than a preference: the key `pending` is already taken there by the webhook-delivery
 * lifecycle, where it means "Pending". Here the same key must read "Queued". Adding a colliding entry, or a
 * domain-prefixed one, would be the first such precedent in a file whose whole premise is a single global key
 * space. This enum is client-only, has exactly one consumer, and never leaves the guest bundle.
 *
 * ── THE COPY DEVIATES FROM THE SPEC IN ONE PLACE, DELIBERATELY ─────────────────────────────────────────
 * §7.1 gives Failed the words "Couldn't send — we'll keep trying". But `outbox.ts` is explicit that a
 * `needs_attention` row is **never auto-retried** — it got there by a 422 or by exhausting five attempts.
 * Printing "we'll keep trying" over an engine that has stopped trying tells the respondent something false,
 * and they would wait instead of acting. The spec sentence itself names TWO mechanisms ("a transient failure
 * on an individual sync attempt, or the needs-attention banner state"), so the split below is a faithful
 * reading rather than a departure: a `pending` row WITH attempts really is still retrying and keeps the
 * spec's words; a `needs_attention` row is not, and says so.
 */

import type { BadgeVariant, StatusDescriptor } from '@meridian/design-system';
import type { OutboxRow, OutboxStatus } from './db';
import { deriveReference } from './reference-number';

export interface OutboxItemView {
    uuid: string;
    slug: string;
    /**
     * The server-issued handle (`7K4M-2QXB`) — Increment J2e. Present only once the row has SETTLED, and
     * null on any row synced by a build that predates J2e, which every reader must tolerate.
     */
    reference: string | null;
    /**
     * A device-local label (`MER-XXXXXX`) for telling two queued rows apart — Increment J2e.
     *
     * ⚠️ IT IS NOT A REFERENCE AND MUST NEVER BE CALLED ONE IN THE UI. It is derived from the client uuid and
     * stored NOWHERE on the server, so a respondent who writes it down and quotes it to the tenant gets
     * nothing back. The copy beside it says so.
     */
    queueTag: string;
    variant: BadgeVariant;
    /** The badge word — Queued / Syncing / Retrying / Failed / Needs review / Sent. */
    label: string;
    /** The sentence under it. */
    detail: string;
    canRetry: boolean;
    canReview: boolean;
    canDiscard: boolean;
    isSyncing: boolean;
    createdAt: string;
}

/** Every `OutboxStatus`, so a new case cannot be added without landing here. */
const BASE: Record<OutboxStatus, StatusDescriptor> = {
    pending: { variant: 'neutral', label: 'Queued' },
    synced: { variant: 'success', label: 'Sent' },
    conflict: { variant: 'info', label: 'Needs review' },
    needs_attention: { variant: 'warning', label: 'Failed' },
};

/**
 * Project one row into what the list renders.
 *
 * `syncing` is passed in rather than read off the row: it is a property of an attempt happening in THIS tab,
 * not a durable fact about the submission — see `useSyncOutbox`, which explains why persisting it would be a
 * bug rather than a convenience.
 *
 * `reviewableHere` is the caller's answer to "is this row's form the one currently open", because a conflict
 * can only be resolved through the share-token client bound to that form.
 */
export function describeRow(row: OutboxRow, syncing: boolean, reviewableHere: boolean): OutboxItemView {
    const base = BASE[row.status];

    // `conflict` is INFO, not danger, following the design system's own reasoning for `verified`: label by
    // what the user must DO next, and do not use red for a state one guided step recovers from.
    const view: OutboxItemView = {
        uuid: row.client_submission_uuid,
        slug: row.slug,
        // ⚠️ REWRITTEN IN J2e, AND THE OLD RULE WAS RIGHT UNDER ITS OWN PREMISE. It read: always derive from
        // the client uuid, for every status, because switching to a server-side id would make a code the
        // respondent may already have written down CHANGE as the row transitions.
        //
        // The premise is gone rather than the reasoning being wrong. Nothing now TELLS the respondent the
        // local code is their reference: it is labelled a queue tag, with copy saying a reference is issued
        // once the response is sent. So the tag still never changes — it simply stopped making a promise the
        // server could not keep. The real handle arrives on `server_reference` when the row settles.
        //
        // Restoring the old single field would silently reintroduce a code that is unfindable by the tenant
        // it is quoted to, which is the defect J2e exists to remove.
        reference: row.server_reference,
        queueTag: deriveReference(row.client_submission_uuid),
        variant: base.variant,
        label: base.label,
        detail: '',
        canRetry: false,
        canReview: false,
        canDiscard: false,
        isSyncing: syncing,
        createdAt: row.created_at,
    };

    if (syncing && row.status === 'pending') {
        return { ...view, variant: 'info', label: 'Syncing', detail: 'Sending…' };
    }

    switch (row.status) {
        case 'pending':
            return row.attempts > 0
                ? {
                      ...view,
                      variant: 'warning',
                      label: 'Retrying',
                      detail: 'Couldn’t send yet — we’ll keep trying.',
                      // §7.3's "Retry now" is specified to BYPASS the exponential backoff, and the backoff state is
                      // exactly this one. Offering it only on `needs_attention` would put the action
                      // everywhere except the place the spec named it for.
                      canRetry: true,
                  }
                : { ...view, detail: 'Saved on this device — will send when you’re back online.' };

        case 'needs_attention':
            return {
                ...view,
                detail: 'Couldn’t send — tap Retry now to try again.',
                canRetry: true,
                canDiscard: true,
            };

        case 'conflict':
            return {
                ...view,
                detail: reviewableHere
                    ? 'We found a conflict — the form changed after this was saved.'
                    : 'Needs review — open this form to resolve it.',
                canReview: reviewableHere,
                canDiscard: reviewableHere,
            };

        case 'synced':
            // The fallback is not defensive padding: a row synced by a build older than J2e has no
            // `server_reference`, and inventing one from the client uuid is exactly the unfindable code this
            // increment removed. Saying less is the honest option.
            return {
                ...view,
                detail: view.reference === null ? 'Sent' : `Sent — reference ${view.reference}`,
            };
    }
}
