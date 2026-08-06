import type { BadgeVariant } from '@meridian/design-system';

/**
 * Audit EVENT → badge variant (Increment I2).
 *
 * ── A deliberate deviation from DSR §3.8's single-map rule, and the deviation is the point ─────────────
 * `status-variant.ts` maps a domain STATUS — the state a thing is *in* — to a pill. An `AuditEvent` is a
 * verb: what someone *did*, once, in the past. Reuse is not mechanically available anyway
 * (`statusVariant('deleted')` returns a neutral pill labelled `deleted`), so the real choice was to EXTEND
 * that map or to own this one, and extending is affirmatively harmful:
 *
 *  • **`archived` proves it.** As a form STATUS, `archived` is `warning` — an archived form is inert and
 *    that is a condition to notice. As an audit EVENT, "someone archived something last March" is an
 *    ordinary historical fact, and painting every such row amber spends the attention channel on nothing,
 *    diluting the amber that DOES mean something here (`exported`, `permission_changed`).
 *  • Adding `created`/`updated` to a map every screen reads would let a future status enum silently
 *    inherit an audit-event colour — `status-variant.ts` is explicit that shared values intentionally
 *    resolve to one descriptor, which is a feature for statuses and a landmine across namespaces.
 *
 * ── Variant only. The LABEL comes from the server ─────────────────────────────────────────────────────
 * `AuditEvent::label()` in PHP is the single source, because the badge, the filter dropdown and the
 * CSV/XLSX export must all print one string. Colour is presentation the export has no opinion about.
 *
 * ── The colour is a coarse consequence band, not an identity ───────────────────────────────────────────
 * Eight events do not fit five variants and are not meant to: the LABEL identifies the event, so colour is
 * never the sole channel (WCAG 1.4.1). The bands are: neutral = routine, success/info = additive and
 * benign, warning = consequential and worth a compliance reader's eye. Do not "fix" the collisions by
 * inventing new variants.
 */
export const EVENT_VARIANT: Record<string, BadgeVariant> = {
    created: 'success',
    // The overwhelming majority of rows. Must stay quiet, or the page is a rainbow and nothing stands out.
    updated: 'neutral',
    deleted: 'danger',
    restored: 'info',
    published: 'success',
    // ← NOT `warning`, unlike the form-status map. This line is the reason this file exists.
    archived: 'neutral',
    // Data left the system.
    exported: 'warning',
    // Someone's authority changed.
    permission_changed: 'warning',
};

/** Never throws; an unrecognised event falls back to the routine band. */
export function auditEventVariant(event: string): BadgeVariant {
    return EVENT_VARIANT[event] ?? 'neutral';
}
