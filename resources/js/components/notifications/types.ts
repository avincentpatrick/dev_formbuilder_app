// The notification centre's prop contracts (Increment I4, PRD Feature #13b).
//
// They live in `components/<domain>/types.ts` for the reason `components/audit/types.ts` states: the
// component TESTS import these to build their fixtures, so a fixture that drifts from the real server
// shape fails type-check instead of passing a test against a payload the server never sends.

/**
 * The seven `App\Enums\NotificationType` cases. Used to TYPE THE VISUAL MAP's keys and the test
 * fixtures — deliberately NOT the wire type of `NotificationRow.type`; see below.
 */
export type NotificationTypeKey =
    | 'submission_received'
    | 'submission_returned'
    | 'submission_approved'
    | 'review_requested'
    | 'export_ready'
    | 'member_invited'
    | 'webhook_failed';

/** One row of `GET /notifications`. Every string here is SERVER-authored — see NotificationPresenter. */
export type NotificationRow = {
    id: string;
    /**
     * Deliberately `string`, not `NotificationTypeKey` — the `AuditRow.event` posture. The enum can gain
     * an eighth case server-first, and a union here would make that a browser compile error rather than
     * the neutral fallback `notificationVisual()` already guarantees.
     */
    type: string;
    /** `NotificationType::label()`, or "Export failed" for a failed export. Never re-derived here. */
    title: string;
    description: string;
    /**
     * Already joined with a LEADING SLASH server-side, and already checked against the real gate.
     * Legitimately null — an export whose payload carries no submission id has nowhere to point, and a
     * target that was deleted or that this reader may not open resolves to null rather than to a 403.
     * The row then renders as plain text; it never becomes `<a href="">`.
     */
    url: string | null;
    /** `NotificationType::actionLabel()` — the same words the email's button uses. */
    action_label: string;
    read_at: string | null;
    created_at: string;
};

export type NotificationFeed = { unread_count: number; items: NotificationRow[] };

/** One row of the /settings preferences card, from `NotificationPresenter::preferences()`. */
export type NotificationPreferenceRow = {
    type: string;
    label: string;
    in_app: boolean;
    email: boolean;
    /**
     * True for `export_ready` / `webhook_failed`: `NotificationType::honorsEmailPreference()` is false for
     * exactly those two, so their purpose-built branded email goes out regardless of anything stored. The
     * control renders CHECKED and DISABLED with a reason, never as a switch wired to nothing.
     */
    email_locked: boolean;
};
