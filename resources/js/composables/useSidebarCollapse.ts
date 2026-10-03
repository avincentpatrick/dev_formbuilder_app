/**
 * Whether the app sidebar is collapsed to its icon rail on a wide screen (M128, `R-33c7fd56`).
 *
 * ── REMEMBERED IN THE BROWSER, PER DEVICE — THE USER'S DECISION (`D68` = B) ──────────────────────────
 * A collapse is a screen-size preference, closer to the mobile drawer than to a theme: a laptop wants it
 * and a wide monitor does not. So it lives in `localStorage` rather than on the server's
 * `user_ui_preferences` row — no migration, no request per toggle that could cancel a builder publish in
 * flight, and per browser context, which is what lets an e2e spec click it without leaking into every
 * later spec. Storage is per ORIGIN, and each workspace is its own host, so it is also per workspace.
 *
 * ⚠️ THE FIRST STORED CLIENT PREFERENCE IN `resources/js`. The discipline is copied from
 * `resources/public-runtime/lib/respondent-session.ts`, which has run it for respondents since G8:
 * every access is inside `try`, because storage can be blocked outright (a SecurityError on the getter
 * itself), full, or partitioned. When it fails the sidebar simply starts expanded and the toggle still
 * works for the page view; a preference is never worth an error.
 *
 * There is no server-side rendering here (`resources/js/app.ts` uses `createApp`), so reading during setup
 * cannot disagree with a server's first paint.
 */
import { ref, type Ref } from 'vue';

/** Namespaced like the respondent runtime's keys, so an origin's storage stays legible. */
export const SIDEBAR_COLLAPSED_KEY = 'meridian:sidebar-collapsed';

function defaultStorage(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.localStorage;
    } catch {
        return null; // reading the property itself can throw where storage is blocked
    }
}

function read(storage: Storage | null): boolean {
    if (storage === null) return false;
    try {
        return storage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function write(storage: Storage | null, collapsed: boolean): void {
    if (storage === null) return;
    try {
        if (collapsed) storage.setItem(SIDEBAR_COLLAPSED_KEY, '1');
        else storage.removeItem(SIDEBAR_COLLAPSED_KEY);
    } catch {
        // Full or denied: the choice holds for this page view and is not remembered. Nothing to tell anyone.
    }
}

/**
 * @param storage injectable for tests, like `respondent-session.ts`; production reads `window.localStorage`.
 */
export function useSidebarCollapse(storage: Storage | null = defaultStorage()): {
    collapsed: Ref<boolean>;
    toggle: () => void;
} {
    const collapsed = ref(read(storage));

    function toggle(): void {
        collapsed.value = !collapsed.value;
        write(storage, collapsed.value);
    }

    return { collapsed, toggle };
}
