import type { BadgeVariant, IconName } from '@meridian/design-system';

/**
 * Notification TYPE → the row's glyph and colour band (Increment I4).
 *
 * ── Variant and icon only. Every WORD comes from the server ─────────────────────────────────────────────
 * `NotificationType::label()`'s own docblock hands this file its job: "colour is presentation, owned by the
 * TS side (I4)" — and, like `AuditEvent`, the enum carries no companion `badgeVariant()`. The title, the
 * description and the action label are all built in `NotificationCopy`, because they branch on a `data`
 * payload whose six shapes only PHP authors. This file knows nothing about `data`.
 *
 * ── The colour is a coarse consequence band, not an identity ────────────────────────────────────────────
 * Seven types do not need seven colours and must not have them: the row's TITLE identifies the event, so
 * colour is never the sole channel (WCAG 1.4.1) and the tint sits on an `aria-hidden` chip. Four bands,
 * each a different question the reader is being asked:
 *
 *   info    — something happened that you should know about   (received, invited)
 *   success — a thing you wanted finished, finished           (approved, export ready)
 *   warning — YOU OWE SOMEONE AN ACTION                       (review requested, returned)
 *   danger  — something broke and is losing data now          (webhook paused)
 *
 * `danger` for `webhook_failed` follows `status-variant.ts`'s own reasoning for `refresh_failed`: the
 * endpoint died on its own and deliveries are silently stopping. Do not "fix" the two-types-per-band
 * collisions by inventing variants — `BadgeVariant` is closed at five, and the collisions are the design.
 *
 * ── Glyphs, each chosen against `icons.ts` rather than wished for ───────────────────────────────────────
 *   inbox     — a response ARRIVED. Literally a tray, and distinct from `submissions` (the checklist),
 *               which is spent on the nav item.
 *   user-plus — the set's only "add a person" mark.
 *   check     — the terminal good outcome.
 *   download  — the same glyph the Export CSV/XLSX buttons wear on /audit-log and /analytics, so the
 *               notification that a file is ready matches the button that asked for it.
 *   search    — the repo's existing "examine this record" mark (audit/Index.vue chose it over
 *               `external-link` for exactly that reason). Reviewing is examining.
 *   undo      — an arrow curving back. "Returned for changes" is the record being sent back to you.
 *   alert     — the warning triangle, the set's failure mark.
 *
 * `type-visual.test.ts` asserts every glyph named here exists in the shared registry, because a typo
 * renders an empty 24×24 box that no other gate would see.
 */
export const NOTIFICATION_VISUAL: Record<string, { icon: IconName; variant: BadgeVariant }> = {
    submission_received: { icon: 'inbox', variant: 'info' },
    member_invited: { icon: 'user-plus', variant: 'info' },
    submission_approved: { icon: 'check', variant: 'success' },
    export_ready: { icon: 'download', variant: 'success' },
    review_requested: { icon: 'search', variant: 'warning' },
    submission_returned: { icon: 'undo', variant: 'warning' },
    webhook_failed: { icon: 'alert', variant: 'danger' },
};

/**
 * Never throws; an unrecognised type reads as "a notification" rather than as something it is not.
 *
 * This is why `NotificationRow.type` is typed `string` and not a seven-case union: the enum can gain a
 * case server-first, and a row arriving with one must render quietly rather than crash the shell that is
 * mounted on every page.
 */
export function notificationVisual(type: string): { icon: IconName; variant: BadgeVariant } {
    return NOTIFICATION_VISUAL[type] ?? { icon: 'bell', variant: 'neutral' };
}
