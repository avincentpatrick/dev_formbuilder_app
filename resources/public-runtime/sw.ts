/// <reference lib="webworker" />
/**
 * The guest PWA service worker (Increment G8b — injectManifest). It replaces G8a's declarative `generateSW`
 * worker: the same three runtime caches are re-expressed in Workbox code (behavioural parity is the bar; the
 * e2e offline spec guards it), and — the reason for injectManifest — it OWNS a Background-Sync `sync` handler
 * that drains the Dexie outbox with no tab open (Chrome/Android). The reliable cross-platform path stays the
 * app-driven driver (reconnect / foreground / manual "Sync now"), so correctness never depends on this event.
 *
 * Re-served from GET /sw.js (ServiceWorkerController) and registered `{ scope: '/f/' }` from register-sw.ts, so
 * it controls guest form pages only and never the Inertia admin app on the same origin. Rollup bundles Workbox
 * + Dexie into one self-contained sw.js (no importScripts), so no CSP change over G8a.
 */

import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { NetworkFirst, StaleWhileRevalidate } from 'workbox-strategies';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';
import { clientsClaim } from 'workbox-core';
import { openDb } from './lib/db';
import { replayOutbox } from './lib/replay';
import { SHELL_CACHE, SHELL_EXPIRATION } from './lib/shell-cache';

declare const self: ServiceWorkerGlobalScope & {
    __WB_MANIFEST: Array<string | { url: string; revision: string | null }>;
};

/** The Background-Sync `sync` event is not in the standard lib typings. */
interface SyncEvent extends ExtendableEvent {
    readonly tag: string;
}

const DAY = 60 * 60 * 24;

// Precache NOTHING (globPatterns:[] in the config) — the admin + public bundles share opaque vendor chunks
// that can't be split by glob; the runtime caches below pull in only what a /f/* page actually requests, so
// the admin bundle is never cached regardless of chunk naming. precacheAndRoute still wires cleanup + routing.
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// The hashed public-runtime JS/CSS (and shared chunks) it pulls in.
//
// ⛔ THE STATUS FILTER IS NOT DECORATION, AND IT WAS THE LAST OF THE THREE ROUTES TO GET ONE (M77).
// Workbox prepends its own `cacheOkAndOpaquePlugin` to any strategy that supplies no
// CacheableResponsePlugin, and that default admits `status === 0` — an OPAQUE response — BY DESIGN.
// The two routes below have always filtered for 200; this one did not, and the row that found the
// /f/* instance framed itself as "the one route whose sibling filters for 200", which was a 1-of-2
// reading of a 1-of-3 fact.
//
// ⚠️ WRITTEN AS A PRECONDITION RATHER THAN AN ALL-CLEAR, WHICH IS THE POINT OF THE COMMENT.
// Status 0 is NOT reachable here today: `/build/` is same-origin and hashed, and this route's own
// matcher requires `sameOrigin`, so an opaque response would need a cross-origin redirect for a
// same-origin URL. What would change that is an edge CDN or WAF in front of /build/, an `ASSET_URL`
// pointing the bundle at another origin, or a canonicalising redirect — none of which exist in this
// deployment. The filter costs nothing (the production delta is exactly {0}) and removes the need
// for the next reader to re-derive that survey, which has now been done twice.
registerRoute(
    ({ url, sameOrigin }) => sameOrigin && url.pathname.startsWith('/build/'),
    new StaleWhileRevalidate({
        cacheName: 'guest-shell-assets',
        plugins: [new ExpirationPlugin({ maxEntries: 80, maxAgeSeconds: 30 * DAY })],
    }),
);

// The pinned form schema (choice lists inline) so a loaded form renders offline. GET only — the submission
// POST to the same prefix is untouched (it flows through the outbox, not the cache).
registerRoute(
    ({ url, sameOrigin }) => sameOrigin && url.pathname.startsWith('/api/v1/public/f/'),
    new NetworkFirst({
        cacheName: 'guest-schema',
        networkTimeoutSeconds: 5,
        plugins: [
            new CacheableResponsePlugin({ statuses: [200] }),
            new ExpirationPlugin({ maxEntries: 20, maxAgeSeconds: 7 * DAY }),
        ],
    }),
    'GET',
);

// The shell HTML for /f/* navigations (serves the cached shell offline).
//
// ⚠️ M75 — THE NAME AND THE EXPIRY COME FROM `lib/shell-cache.ts` RATHER THAN FROM LITERALS HERE, and
// that is the one route where it matters: `lib/brand-cache.ts` writes into this cache from the WINDOW
// and renews this same clock, so a literal here would be one of two copies of a fact.
registerRoute(
    ({ request, url, sameOrigin }) => request.mode === 'navigate' && sameOrigin && url.pathname.startsWith('/f/'),
    new NetworkFirst({
        cacheName: SHELL_CACHE,
        networkTimeoutSeconds: 5,
        plugins: [new CacheableResponsePlugin({ statuses: [200] }), new ExpirationPlugin({ ...SHELL_EXPIRATION })],
    }),
);

self.skipWaiting();
clientsClaim();

// True Background Sync (Increment G8b): the browser fires this on reconnect even with no tab open. We drain the
// SAME Dexie outbox the app uses, via the SAME shared replay function — idempotency (client_submission_uuid)
// makes a concurrent tab replay safe. Registered from the app as `reg.sync.register('outbox-sync')`.
self.addEventListener('sync', (event) => {
    const sync = event as SyncEvent;
    if (sync.tag === 'outbox-sync') {
        sync.waitUntil(replayOutbox(openDb(), self.fetch.bind(self)).then(() => undefined));
    }
});

// Let an open tab nudge the worker (e.g. right after enqueue) without waiting for the browser's sync trigger.
self.addEventListener('message', (event) => {
    if ((event as ExtendableMessageEvent).data === 'replay-outbox') {
        event.waitUntil(replayOutbox(openDb(), self.fetch.bind(self)).then(() => undefined));
    }
});
