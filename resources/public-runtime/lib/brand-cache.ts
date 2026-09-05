/**
 * Offline cache invalidation for tenant branding (Increment H23b, ADR-0014 / H-map row 258).
 *
 * ── THE PROBLEM, PRECISELY ────────────────────────────────────────────────────────────────────────
 * The tenant's ramp rides the guest shell's HTML (a `<style id="tenant-brand">` block emitted by
 * `partials/brand-ramp.blade.php`), and the service worker caches that HTML `NetworkFirst` under
 * `guest-shell-html` (sw.ts). So the page a respondent is looking at RIGHT NOW self-heals: any
 * successful online navigation replaces the cache entry with a freshly-branded copy.
 *
 * What does not self-heal is every OTHER `/f/…` shell this device cached earlier — a second form —
 * which keeps rendering the superseded brand offline until its 7-day expiry. Those entries are only
 * rewritten when the respondent happens to navigate to them online, which on a field device may be
 * never.
 *
 * ⛔ ONE CLASS OF SHELL IS DELIBERATELY LEFT TO GO STALE: a resume link. See `isResumeShell()`.
 *
 * ── RE-PRIME, NEVER PURGE, AND THIS IS THE WHOLE DESIGN ───────────────────────────────────────────
 * `caches.delete(SHELL_CACHE)` is the obvious fix and it is the wrong one. A stale brand is
 * COSMETIC; a purged shell costs a fieldworker offline access to a form they deliberately primed,
 * which is a core product promise (PRD offline data collection). Trading the second for the first is
 * a bad trade in any brand's favour. So the stale entries are REFRESHED — fetched, re-`put`, and
 * their expiry clock renewed — and the cache is never emptied. Worst case the refresh fails and the
 * respondent sees last week's colours; they never lose the form.
 *
 * ⛔ "AND THEIR EXPIRY CLOCK RENEWED" IS AN M75 CORRECTION, NOT A RESTATEMENT. Until M75 this
 * paragraph said only "fetched and re-`put`", which was true of the bytes and false of the clock, and
 * that sentence is what a reader acted on. `sw.ts` expires this cache with Workbox's
 * `ExpirationPlugin`, which keeps its own timestamps in IndexedDB and updates them from ONE place —
 * `StrategyHandler.cachePut` → `cacheDidUpdate`. A raw `cache.put()` never reaches it, so a shell
 * refreshed on day six was still deleted on day seven as though it had not been. ⚠️ WORKBOX HAS TWO
 * CLOCKS AND ONLY ONE WAS BROKEN: read-freshness is decided from the cached response's own `Date`
 * header, which a raw `put` DOES renew, so the defect was invisible to anything that merely read an
 * entry back. It bit exactly the shells this module exists for — the ones nobody navigates to.
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

import { CacheExpiration } from 'workbox-expiration';

import type { MeridianDb } from './db';
import { SHELL_CACHE, SHELL_EXPIRATION } from './shell-cache';

/** The `app_state` key holding the ramp fingerprint the cached shells were last refreshed for. */
export const BRAND_VERSION_KEY = 'brand_version';

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
 * Is this cached shell a resume link, whose HTML carries a credential?
 *
 * ⛔ A resume shell's body carries `data-resume-token` (`public-runtime.blade.php`), and that token
 * is the whole credential for `GET /api/v1/public/drafts/{resumeToken}`, which answers with the
 * respondent's full answer map. The shell is cached like any other `/f/…` navigation — `sw.ts`
 * NetworkFirst, `SHELL_CACHE`, seven days — and `refreshCachedShells()` would otherwise renew it
 * from every later sweep, keeping a token-bearing document on disk indefinitely instead of letting
 * it age out. That renewal is what this predicate stops.
 *
 * ⛔ AND UNTIL M75 THIS PARAGRAPH WAS DESCRIBING SOMETHING THAT DID NOT HAPPEN. The sweep re-`put`
 * bytes through the raw Cache API, which never touched `ExpirationPlugin`'s IndexedDB timestamp, so a
 * skipped resume shell and a swept ordinary one aged out on exactly the same clock. **This predicate
 * was belt-and-braces; M75 made it load-bearing**, and that is the direction to be glad of — the
 * carve-out was written for a threat the code was not yet capable of.
 *
 * ⚠️ SKIPPED, NEVER PURGED — AND M75 ADDS A REAL QUALIFICATION TO THAT, MEASURED RATHER THAN
 * REASONED. Deleting the entry would cost a respondent offline access to a form they deliberately
 * primed, which is the trade this file exists to refuse, and nothing here deletes anything. But
 * `maxEntries` does: `workbox-expiration` enforces it by walking the `timestamp` index newest-first
 * and deleting past the 20th survivor. Now that a sweep renews every OTHER swept entry's timestamp
 * and deliberately not this one, a resume shell on a device holding more than twenty shells becomes
 * the FIRST eviction rather than a middling one. That is consistent with why the carve-out exists —
 * a credential-bearing document leaving sooner is the direction this file wants — but it is an
 * eviction this docblock did not previously imply, and `refreshCachedShells()` records the ordering
 * change in full.
 *
 * ⚠️ AND THE FAIL-OPEN BELOW IS NOW A DIFFERENT TRADE. Returning FALSE on an unparseable URL used to
 * cost a token-bearing shell nothing but rewritten bytes; it now costs it a renewed lifetime. The
 * argument for fail-open is still the stronger one — a predicate that throws swallows the whole
 * sweep — but it is no longer free, and whoever revisits it should weigh the new price rather than
 * the old one.
 *
 * ⚠️ TWO PREFIXES CARRY THIS AND THEY ARE NOT THE SAME ONE. Matched here is the guest ROUTE,
 * `/f/resume/{resumeToken}` (`routes/tenant.php`). The resume READ is a different path,
 * `/api/v1/public/drafts/{resumeToken}` (`routes/api.php`), and it escapes service-worker caching
 * only because its prefix is `drafts/` rather than `f/`. A rename there re-opens a defect this
 * function cannot see and this test file cannot reach.
 */
export function isResumeShell(url: string): boolean {
    try {
        return new URL(url).pathname.startsWith('/f/resume/');
    } catch {
        // Unparseable, so nothing can be concluded. Report FALSE rather than true: a predicate that
        // fails open swallows the whole sweep, which is a worse and much quieter defect than the one
        // being guarded — and the sweep is the thing this module exists to do.
        return false;
    }
}

/**
 * Re-fetch, re-`put` and re-date every cached guest shell except the current one and any resume link.
 *
 * Three rules that look like defensiveness and are not:
 *  - **Only a 200 is written.** A `cache.put` of a 404 (the form was unpublished) or a 500 would
 *    replace a working offline shell with an error page — strictly worse than the stale brand this
 *    function exists to fix.
 *  - **Each entry is independent.** One dead URL must not abort the sweep for the others, so every
 *    fetch is caught individually rather than the whole loop being wrapped.
 *  - **The clock is renewed with the bytes, and never separately from them.** `updateTimestamp()` sits
 *    inside the same `try` as the `put` and immediately after it, so the two either both happen or the
 *    entry is left exactly as it was.
 *
 * ── ⛔ WHY THE CLOCK NEEDS A SECOND CALL AT ALL (M75) ─────────────────────────────────────────────
 * `sw.ts` expires this cache with `ExpirationPlugin`, whose timestamps live in IndexedDB and are
 * written from exactly one place: `StrategyHandler.cachePut` → `cacheDidUpdate`. This module cannot
 * go through that path. It runs in the WINDOW, and a Workbox `Strategy` needs a `FetchEvent` to
 * handle — `Strategy.handleAll()` throws outside a worker. Nor can the fetch below be made to match
 * the route: `sw.ts` matches `request.mode === 'navigate'`, and `mode: 'navigate'` is not
 * constructible from script. So the strategy is unreachable from here in three independent ways, and
 * `CacheExpiration` — the same bookkeeping the plugin drives, minus the deletion — is the seam.
 *
 * ⛔ `expireEntries()` IS DELIBERATELY NOT CALLED. It deletes, and this module's axiom is re-prime,
 * never purge. `updateTimestamp()` writes one record and removes nothing.
 *
 * ⚠️ AND IT CHANGES `maxEntries` EVICTION ORDER, WHICH IS THE HONEST COST AND WAS MEASURED, NOT
 * ASSUMED. Workbox enforces `maxEntries` by walking the `timestamp` index newest-first and deleting
 * everything past the Nth survivor, so that index is a recency-of-USE order — `ExpirationPlugin`
 * stamps an entry on every read as well as every write. A sweep renews nearly every entry at once,
 * which replaces that order with sweep order (`cache.keys()`, i.e. insertion order) for one boot. The
 * writes are staggered rather than identical, because each one waits on its own network fetch — 8 ms
 * apart in the probe that established this — so the order is well-defined rather than a tie-break on
 * the primary key. It re-establishes itself as entries are read again. ⚠️ Two consequences worth
 * knowing: the resume shell, alone in not being renewed, sorts oldest (see `isResumeShell()`), and a
 * device holding more than twenty shells can lose one it used recently. ⚠️ THE PRIOR STATE WAS THE
 * ANOMALY: a fresh body with a stale timestamp is a combination Workbox's own model cannot produce,
 * so there was no correct ordering to preserve — only a different wrong one.
 *
 * ⚠️ A blocked IndexedDB (private mode) makes `updateTimestamp()` throw. The per-entry `catch` takes
 * it, the sweep continues, and that entry is left with renewed bytes and a stale clock — exactly the
 * pre-M75 behaviour, which is the right thing to degrade to and is not silently better than it looks.
 */
async function refreshCachedShells(cacheStorage: CacheStorage, doFetch: typeof fetch, currentUrl: string): Promise<void> {
    if (!(await cacheStorage.has(SHELL_CACHE))) {
        return;
    }

    const cache = await cacheStorage.open(SHELL_CACHE);
    const entries = await cache.keys();
    const expiration = new CacheExpiration(SHELL_CACHE, { ...SHELL_EXPIRATION });

    for (const request of entries) {
        if (request.url === currentUrl) {
            continue; // this document just came off the network — re-fetching it is pure waste
        }

        if (isResumeShell(request.url)) {
            continue; // token-bearing document; let it expire rather than renewing it — isResumeShell()
        }

        try {
            const response = await doFetch(request.url, { credentials: 'omit' });

            if (response.status === 200) {
                await cache.put(request, response);
                await expiration.updateTimestamp(request.url);
            }
        } catch {
            // Offline mid-sweep, that form is gone, or IndexedDB refused. Leave the existing entry alone.
        }
    }
}
