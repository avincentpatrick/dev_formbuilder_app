/**
 * Offline cache invalidation for tenant branding (Increment H23b, ADR-0014 / H-map row 258).
 *
 * ── THE PROBLEM, PRECISELY ────────────────────────────────────────────────────────────────────────
 * The tenant's ramp rides the guest shell's HTML (a `<style id="tenant-brand">` block emitted by
 * `partials/brand-ramp.blade.php`), and the service worker caches that HTML `NetworkFirst` under
 * `guest-shell-html` (sw.ts). So the page a respondent is looking at RIGHT NOW self-heals: any
 * successful online navigation replaces the cache entry with a freshly-branded copy.
 *
 * What does not self-heal is every OTHER `/f/…` shell this device cached earlier — a second form, a
 * resume link — which keeps rendering the superseded brand offline until its 7-day expiry. Those
 * entries are only rewritten when the respondent happens to navigate to them online, which on a
 * field device may be never.
 *
 * ── RE-PRIME, NEVER PURGE, AND THIS IS THE WHOLE DESIGN ───────────────────────────────────────────
 * `caches.delete('guest-shell-html')` is the obvious fix and it is the wrong one. A stale brand is
 * COSMETIC; a purged shell costs a fieldworker offline access to a form they deliberately primed,
 * which is a core product promise (PRD offline data collection). Trading the second for the first is
 * a bad trade in any brand's favour. So the stale entries are REFRESHED — fetched and re-`put` — and
 * the cache is never emptied. Worst case the refresh fails and the respondent sees last week's
 * colours; they never lose the form.
 *
 * ── WHY THE FINGERPRINT IS NOT ADVANCED WHEN OFFLINE ──────────────────────────────────────────────
 * The stored fingerprint means "the cached shells have been refreshed for THIS ramp". Writing it
 * without having refreshed anything would be a lie that permanently suppresses the retry: the next
 * boot would compare equal and skip. So an offline boot returns `deferred`, changes nothing, and the
 * next online boot picks it up.
 *
 * Best-effort throughout, on the register-sw.ts precedent: no failure here may ever be visible to a
 * respondent filling a form. `caches` / `fetch` / `navigator` are injected so this is unit-testable
 * without a service worker.
 */

import type { MeridianDb } from './db';

/** The `app_state` key holding the ramp fingerprint the cached shells were last refreshed for. */
export const BRAND_VERSION_KEY = 'brand_version';

/** Must match the `cacheName` of sw.ts's `/f/*` navigation route — the only cache carrying brand. */
export const SHELL_CACHE = 'guest-shell-html';

export type BrandSyncOutcome =
    /** The cached shells already match this ramp (the overwhelmingly common path). */
    | 'unchanged'
    /** The brand moved but we are offline — nothing written, so the next online boot retries. */
    | 'deferred'
    /** The brand moved and the other cached shells were re-fetched. */
    | 'refreshed'
    /** The Cache Storage API is absent (no service worker / private mode), or a step threw. */
    | 'unavailable';

export interface BrandCacheDeps {
    /** The fingerprint embedded in the document currently being rendered (`data-brand-version`). */
    brandVersion: string;
    db: MeridianDb;
    /** The URL of the shell in front of the respondent — already fresh, so it is skipped. */
    currentUrl: string;
    caches?: CacheStorage;
    fetch?: typeof fetch;
    navigator?: Navigator;
}

/**
 * Bring previously-cached guest shells up to date with the tenant's current brand.
 *
 * Fire-and-forget from `main.ts` AFTER mount — this must never sit in front of the first paint of a
 * form. Returns its outcome for the tests rather than for a caller to branch on.
 */
export async function syncBrandedShellCache(deps: BrandCacheDeps): Promise<BrandSyncOutcome> {
    const { brandVersion, db, currentUrl } = deps;
    const cacheStorage = deps.caches ?? (typeof caches !== 'undefined' ? caches : undefined);
    const doFetch = deps.fetch ?? (typeof fetch !== 'undefined' ? fetch : undefined);
    const nav = deps.navigator ?? (typeof navigator !== 'undefined' ? navigator : undefined);

    if (cacheStorage === undefined || doFetch === undefined) {
        return 'unavailable';
    }

    try {
        const stored = await db.app_state.get(BRAND_VERSION_KEY);

        if (stored?.value === brandVersion) {
            return 'unchanged';
        }

        // `navigator.onLine === false` is the only trustworthy half of that flag (true merely means a
        // network interface exists), which is exactly the half we need: it tells us not to bother.
        if (nav?.onLine === false) {
            return 'deferred';
        }

        await refreshCachedShells(cacheStorage, doFetch, currentUrl);
        await db.app_state.put({ key: BRAND_VERSION_KEY, value: brandVersion });

        return 'refreshed';
    } catch {
        // A refusal to open Cache Storage, a blocked IndexedDB, a network that died mid-sweep. The
        // fingerprint is deliberately NOT advanced on this path either, so the next boot retries.
        return 'unavailable';
    }
}

/**
 * Re-fetch and re-`put` every cached guest shell except the current one.
 *
 * Two rules that look like defensiveness and are not:
 *  - **Only a 200 is written.** A `cache.put` of a 404 (the form was unpublished) or a 500 would
 *    replace a working offline shell with an error page — strictly worse than the stale brand this
 *    function exists to fix.
 *  - **Each entry is independent.** One dead URL must not abort the sweep for the others, so every
 *    fetch is caught individually rather than the whole loop being wrapped.
 */
async function refreshCachedShells(cacheStorage: CacheStorage, doFetch: typeof fetch, currentUrl: string): Promise<void> {
    if (!(await cacheStorage.has(SHELL_CACHE))) {
        return;
    }

    const cache = await cacheStorage.open(SHELL_CACHE);
    const entries = await cache.keys();

    for (const request of entries) {
        if (request.url === currentUrl) {
            continue; // this document just came off the network — re-fetching it is pure waste
        }

        try {
            const response = await doFetch(request.url, { credentials: 'omit' });

            if (response.status === 200) {
                await cache.put(request, response);
            }
        } catch {
            // Offline mid-sweep, or that form is gone. Leave the existing entry alone.
        }
    }
}
