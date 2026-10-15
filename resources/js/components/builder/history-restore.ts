/**
 * M172 (`R-81756e95`) — whether the builder page now mounting was brought back by the browser's Back or Forward button.
 *
 * Inertia restores a history entry from the props it CACHED when the page was first visited; it never asks the server.
 * The builder's own writes are fetch requests that never touch that cache, so a builder reached by Back showed the form
 * as it was first loaded — measured on the trunk: no question at all, though one had been added and saved — and after a
 * publish it held the old draft's ids as well. Such a page re-visits the server as it mounts (`Builder.vue`).
 *
 * ⛔ NOT INERTIA'S HISTORY ENCRYPTION, WHICH WAS TRIED FIRST AND BROKE THE BUILDER. An encrypted entry whose key a
 * publish cleared does make Inertia re-visit on Back — but encryption needs `crypto.subtle`, which only a secure context
 * has. On a plain-http host that is not localhost (CI's `acme.meridian.test`, or any http deployment) Inertia threw
 * "Unable to encrypt history" and no visit to the builder completed at all. This check needs nothing from the browser.
 *
 * The URL is remembered at `popstate` and forgotten at the start of any ordinary visit, so a Back that lands on another
 * page never makes a later, ordinary visit to the builder reload for nothing.
 */
import { router } from '@inertiajs/vue3';

let restoredUrl: string | null = null;

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => {
        restoredUrl = window.location.href;
    });
    router.on('start', () => {
        restoredUrl = null;
    });
}

/** True once, for the page Back or Forward just restored at this URL. */
export function takeHistoryRestore(): boolean {
    const restored = restoredUrl !== null && typeof window !== 'undefined' && restoredUrl === window.location.href;
    restoredUrl = null;

    return restored;
}
