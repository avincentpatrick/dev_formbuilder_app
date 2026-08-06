// The notification centre's transport (Increment I4). Fourth consumer of `builderClient`, after the
// builder itself, `scopesClient` and `integrationsClient` — and the first to WRITE through it outside the
// builder. Both of those facts are deliberate; see below.
//
// ── Why the writes are here and not `router.post` ──────────────────────────────────────────────────────
// scopesClient/integrationsClient are read-only because bootstrap/app.php keys its domain-exception branch
// on the `api/v1/*` PATH, so a DomainException raised on a web route arrives as a 302 that `fetch` follows,
// yields HTML for, and then chokes parsing. Neither route here raises one — they stamp a timestamp on a row
// the caller owns — and framework failures already negotiate to JSON (bootstrap/app.php's
// `shouldRenderJsonWhen` covers 401/403/404/419).
//
// The reason it MUST be a fetch is Inertia's own request stream: it is `maxConcurrent: 1,
// interruptible: true`, so a `router.post()` fired in the same tick as the row's <Link> navigation is
// SILENTLY ABORTED and the row stays unread — intermittently, depending on which visit wins. A 204 also
// spares the current page a full re-render (which `back()` would force) on every click.
//
// ── Every function here swallows every error, and that is the divergence from scopesClient ─────────────
// scopesClient re-throws anything that is not a BuilderRequestError, because its callers are click handlers
// with a user watching. These are called from a 60-second interval. A dropped connection makes `fetch`
// reject with a bare TypeError, and a tenant that cannot be identified produces a 302 to the central domain
// that `builderClient` then throws a SyntaxError on — either way, a re-throw is an unhandled promise
// rejection every sixty seconds for as long as the tab is broken. `null`/`false` means "ask again later",
// and the composable holds the last known feed rather than blanking the bell.

import { builderClient } from '@/components/builder/builderClient';
import type { NotificationFeed } from './types';

/** The bell's payload, or null if this attempt failed for any reason at all. */
export async function fetchNotificationFeed(): Promise<NotificationFeed | null> {
    try {
        const result = await builderClient.get<NotificationFeed>('/notifications');

        return result.conflict ? null : result.data;
    } catch {
        return null;
    }
}

/** Mark one row read. Returns whether the server agreed; the caller has already updated optimistically. */
export async function markNotificationRead(id: string): Promise<boolean> {
    try {
        await builderClient.post<null>(`/notifications/${id}/read`);

        return true;
    } catch {
        return false;
    }
}

/** Mark every unread row of mine read. */
export async function markAllNotificationsRead(): Promise<boolean> {
    try {
        await builderClient.post<null>('/notifications/read-all');

        return true;
    } catch {
        return false;
    }
}
