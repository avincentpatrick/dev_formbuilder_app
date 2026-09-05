import { openDB } from 'idb';
import { describe, expect, it, vi } from 'vitest';
import { BRAND_VERSION_KEY, isResumeShell, syncBrandedShellCache } from '../lib/brand-cache';
import { openDb, type MeridianDb } from '../lib/db';
import { SHELL_CACHE } from '../lib/shell-cache';

/*
 * Increment H23b — the offline half of guest-runtime branding.
 *
 * The behaviour under test is narrow and the failure modes are all silent, which is why each one gets
 * its own case: this code runs fire-and-forget after mount, so nothing it does wrong ever reaches a
 * respondent as an error. The two that matter most are the two that DON'T write: an offline boot must
 * leave the fingerprint alone (or the retry is lost forever), and a non-200 must not be cached (or a
 * stale brand is "fixed" by replacing a working offline shell with an error page).
 */

let n = 0;
const freshDb = (): MeridianDb => openDb(`brand-cache-test-${(n += 1)}`);

/** A minimal in-memory CacheStorage holding one named cache of URL → Response. */
function fakeCaches(names: Record<string, string[]>): {
    caches: CacheStorage;
    put: ReturnType<typeof vi.fn>;
} {
    const put = vi.fn();
    const caches = {
        has: (name: string) => Promise.resolve(name in names),
        open: (name: string) =>
            Promise.resolve({
                keys: () => Promise.resolve((names[name] ?? []).map((url) => ({ url }) as Request)),
                put,
            } as unknown as Cache),
    } as unknown as CacheStorage;

    return { caches, put };
}

const respond = (status: number) => ({ status }) as Response;

const OTHER_SHELL = 'http://acme.test/f/other';
const CURRENT_SHELL = 'http://acme.test/f/intake';
const RESUME_SHELL = 'http://acme.test/f/resume/tok-abc123';

/**
 * Every URL Workbox's expiry bookkeeping holds a timestamp for, in `SHELL_CACHE`.
 *
 * ⛔ THIS REACHES INTO A VENDOR SCHEMA ON PURPOSE (M75). `workbox-expiration` keeps its clock in an
 * IndexedDB store this project never opens in production, and the whole defect the clock case exists
 * for was that a write LOOKED like it renewed the entry and did not. An assertion on an injected
 * seam would have passed against that defect too — the only thing that cannot is reading back what
 * the vendor actually stored. If Workbox renames the store, this reddens, which is precisely when
 * `refreshCachedShells()` would silently stop renewing anything.
 *
 * ⚠️ The store name is a CONSTANT inside `CacheTimestampsModel`, so unlike the Dexie databases above
 * it cannot be made unique per case. The clock case therefore uses URLs no other case uses, and
 * filters to them, rather than asserting over the whole store.
 */
async function stampedUrls(): Promise<string[]> {
    const db = await openDB('workbox-expiration', 1);

    if (!db.objectStoreNames.contains('cache-entries')) {
        db.close();

        return []; // nothing has written a timestamp yet — an empty set, not a failure
    }

    const rows = (await db.getAllFromIndex('cache-entries', 'cacheName', SHELL_CACHE)) as Array<{ url: string }>;
    db.close();

    return rows.map((row) => row.url).sort();
}

describe('syncBrandedShellCache', () => {
    it('does nothing when the cached shells already match this ramp', async () => {
        const db = freshDb();
        await db.app_state.put({ key: BRAND_VERSION_KEY, value: 'aaaaaaaaaaaa' });
        const { caches } = fakeCaches({ [SHELL_CACHE]: [OTHER_SHELL] });
        const doFetch = vi.fn();

        const outcome = await syncBrandedShellCache({
            brandVersion: 'aaaaaaaaaaaa',
            db,
            currentUrl: CURRENT_SHELL,
            caches,
            fetch: doFetch as unknown as typeof fetch,
        });

        expect(outcome).toBe('unchanged');
        expect(doFetch).not.toHaveBeenCalled();
        await db.delete();
    });

    it('re-fetches every OTHER cached shell when the brand has moved, and skips the current one', async () => {
        const db = freshDb();
        await db.app_state.put({ key: BRAND_VERSION_KEY, value: 'old000000000' });
        const { caches, put } = fakeCaches({ [SHELL_CACHE]: [CURRENT_SHELL, OTHER_SHELL] });
        const doFetch = vi.fn().mockResolvedValue(respond(200));

        const outcome = await syncBrandedShellCache({
            brandVersion: 'new000000000',
            db,
            currentUrl: CURRENT_SHELL,
            caches,
            fetch: doFetch as unknown as typeof fetch,
            navigator: { onLine: true } as Navigator,
        });

        expect(outcome).toBe('refreshed');
        // The current document came straight off the network — re-fetching it is pure waste.
        expect(doFetch).toHaveBeenCalledTimes(1);
        expect(doFetch).toHaveBeenCalledWith(OTHER_SHELL, { credentials: 'omit' });
        expect(put).toHaveBeenCalledTimes(1);
        // ⛔ M74 — THE COUNT ABOVE CANNOT SEE THIS FILE'S CENTRAL DEFECT, WHICH IS WHY THE KEY IS NOW
        // ASSERTED. `refreshCachedShells()` re-`put`s under the request object it took from
        // `cache.keys()`, and a mutant writing the right response to the WRONG key —
        // `cache.put(entries[0], response)` — kept all ten cases in this file green. Here `entries[0]`
        // is CURRENT_SHELL, the one entry the sweep is supposed to skip.
        expect(put).toHaveBeenCalledWith({ url: OTHER_SHELL }, { status: 200 });
        expect(await db.app_state.get(BRAND_VERSION_KEY)).toEqual({
            key: BRAND_VERSION_KEY,
            value: 'new000000000',
        });
        await db.delete();
    });

    it('leaves a resume link alone while still refreshing the ordinary shell beside it', async () => {
        // ⛔ A resume shell's HTML carries `data-resume-token`, and that token is the whole credential
        // for the resume read, which answers with the respondent's full answer map. Sweeping it on
        // every later boot RENEWS a credential-bearing document on disk indefinitely; skipping it lets
        // sw.ts's seven-day expiry collect it. Skipped and not purged — deleting it would cost offline
        // access to a form the respondent deliberately primed.
        // ⚠️ M75 — "RENEWS … INDEFINITELY" ONLY BECAME TRUE IN M75, AND THIS COMMENT ASSERTED IT
        // BEFORE THEN. A raw `cache.put()` renewed the bytes and never the expiry clock, so a swept
        // shell and a skipped one aged out together and this skip changed nothing about lifetime. The
        // case below is the one that can now tell those apart.
        const db = freshDb();
        await db.app_state.put({ key: BRAND_VERSION_KEY, value: 'old000000000' });
        const { caches, put } = fakeCaches({ [SHELL_CACHE]: [RESUME_SHELL, OTHER_SHELL] });
        const doFetch = vi.fn().mockResolvedValue(respond(200));

        const outcome = await syncBrandedShellCache({
            brandVersion: 'new000000000',
            db,
            currentUrl: CURRENT_SHELL,
            caches,
            fetch: doFetch as unknown as typeof fetch,
            navigator: { onLine: true } as Navigator,
        });

        expect(outcome).toBe('refreshed');
        // ⚠️ THE OTHER HALF IS THE POINT. Asserting only that the resume URL was not fetched is
        // satisfied by a sweep that skips EVERYTHING, which is a mutation this file must be able to
        // tell apart from the fix — so the ordinary shell beside it is asserted to still go through.
        expect(doFetch).toHaveBeenCalledTimes(1);
        expect(doFetch).toHaveBeenCalledWith(OTHER_SHELL, { credentials: 'omit' });
        expect(put).toHaveBeenCalledTimes(1);
        // ⛔ M74 — THE HIGHEST-CONSEQUENCE SPELLING OF THE SAME MUTANT, AND THE REASON THIS CASE GETS
        // THE ASSERTION TOO. Here `entries[0]` is RESUME_SHELL, so `cache.put(entries[0], …)` writes
        // the ordinary shell's body under the token-bearing key — renewing on disk exactly the
        // credential-bearing document the skip above exists to let expire. The count is blind to it.
        expect(put).toHaveBeenCalledWith({ url: OTHER_SHELL }, { status: 200 });
        await db.delete();
    });

    it('renews the Workbox expiry clock for the shell it re-puts, and for no other entry', async () => {
        // ⛔ THE DEFECT THIS CASE EXISTS FOR PASSED EVERY OTHER CASE IN THIS FILE. Until M75 the sweep
        // renewed bytes through the raw Cache API and never touched `ExpirationPlugin`'s IndexedDB
        // timestamp, so a shell refreshed on day six was still deleted on day seven as though it had
        // not been — and every assertion above, all of which watch `put`, was green throughout. What
        // separates the two is reading back what Workbox actually stored.
        //
        // ⚠️ URLs UNIQUE TO THIS CASE. The expiry store's name is a constant inside Workbox, so it is
        // shared with every other case in this file; `stampedUrls()` explains why that cannot be fixed
        // by renaming. Filtering to `clock-` is what makes the assertion exact rather than merely
        // non-empty.
        const SWEPT = 'http://acme.test/f/clock-swept';
        const CURRENT = 'http://acme.test/f/clock-current';
        const RESUME = 'http://acme.test/f/resume/clock-token';

        const db = freshDb();
        await db.app_state.put({ key: BRAND_VERSION_KEY, value: 'old000000000' });
        const { caches, put } = fakeCaches({ [SHELL_CACHE]: [CURRENT, RESUME, SWEPT] });
        const doFetch = vi.fn().mockResolvedValue(respond(200));

        const outcome = await syncBrandedShellCache({
            brandVersion: 'new000000000',
            db,
            currentUrl: CURRENT,
            caches,
            fetch: doFetch as unknown as typeof fetch,
            navigator: { onLine: true } as Navigator,
        });

        expect(outcome).toBe('refreshed');

        // The positive half and both negative halves in one assertion. ⛔ The RESUME exclusion is the
        // security half: renewing that entry's clock is what `isResumeShell()` exists to prevent, and
        // it only became preventable in M75 — before it, the skip renewed nothing either way.
        const clockEntries = (await stampedUrls()).filter((url) => url.includes('clock-'));

        expect(clockEntries).toEqual([SWEPT]);

        // ⚠️ ORDER IS DELIBERATE AND WAS CORRECTED AFTER MEASURING. With this above the clock
        // assertion, deleting the `isResumeShell()` skip reddened this case on the CALL COUNT — so
        // the clock half, which is what this case is for, was never the thing that caught it. A case
        // whose subject is short-circuited by its scenery proves the scenery.
        expect(put).toHaveBeenCalledTimes(1);
        await db.delete();
    });

    it('DEFERS while offline and leaves the fingerprint alone so the next online boot retries', async () => {
        // Advancing the fingerprint here would be a lie — nothing was refreshed — and it would suppress
        // the retry permanently, because every later boot would compare equal and skip.
        const db = freshDb();
        await db.app_state.put({ key: BRAND_VERSION_KEY, value: 'old000000000' });
        const { caches } = fakeCaches({ [SHELL_CACHE]: [OTHER_SHELL] });
        const doFetch = vi.fn();

        const outcome = await syncBrandedShellCache({
            brandVersion: 'new000000000',
            db,
            currentUrl: CURRENT_SHELL,
            caches,
            fetch: doFetch as unknown as typeof fetch,
            navigator: { onLine: false } as Navigator,
        });

        expect(outcome).toBe('deferred');
        expect(doFetch).not.toHaveBeenCalled();
        expect(await db.app_state.get(BRAND_VERSION_KEY)).toEqual({
            key: BRAND_VERSION_KEY,
            value: 'old000000000',
        });
        await db.delete();
    });

    it('never caches a non-200 over a working offline shell', async () => {
        // The form was unpublished, or the server is down. Writing that response into the cache would
        // replace a renderable offline shell with an error page — strictly worse than a stale colour.
        const db = freshDb();
        const { caches, put } = fakeCaches({ [SHELL_CACHE]: [OTHER_SHELL] });
        const doFetch = vi.fn().mockResolvedValue(respond(404));

        const outcome = await syncBrandedShellCache({
            brandVersion: 'new000000000',
            db,
            currentUrl: CURRENT_SHELL,
            caches,
            fetch: doFetch as unknown as typeof fetch,
            navigator: { onLine: true } as Navigator,
        });

        expect(outcome).toBe('refreshed');
        expect(put).not.toHaveBeenCalled();
        await db.delete();
    });

    it('keeps sweeping after one URL fails', async () => {
        const db = freshDb();
        const { caches, put } = fakeCaches({ [SHELL_CACHE]: ['http://acme.test/f/dead', OTHER_SHELL] });
        const doFetch = vi
            .fn()
            .mockRejectedValueOnce(new Error('network'))
            .mockResolvedValueOnce(respond(200));

        await syncBrandedShellCache({
            brandVersion: 'new000000000',
            db,
            currentUrl: CURRENT_SHELL,
            caches,
            fetch: doFetch as unknown as typeof fetch,
            navigator: { onLine: true } as Navigator,
        });

        expect(doFetch).toHaveBeenCalledTimes(2);
        expect(put).toHaveBeenCalledTimes(1);
        // ⛔ M74 — AND THIS ONE PINS A PROPERTY NOTHING IN THE FILE ASSERTED AT ALL: that the sweep
        // cached the entry that SUCCEEDED rather than the one that failed. `entries[0]` is the dead
        // URL, whose fetch rejected, so a count of 1 is satisfied by writing a response under the key
        // that never produced one.
        expect(put).toHaveBeenCalledWith({ url: OTHER_SHELL }, { status: 200 });
        await db.delete();
    });

    it('advances the fingerprint even when the device has cached no shell yet', async () => {
        // First-ever boot: the cache does not exist, there is nothing to refresh, and the device is now
        // legitimately up to date. Returning early WITHOUT recording that would re-sweep on every load.
        const db = freshDb();
        const { caches } = fakeCaches({});
        const doFetch = vi.fn();

        const outcome = await syncBrandedShellCache({
            brandVersion: 'none',
            db,
            currentUrl: CURRENT_SHELL,
            caches,
            fetch: doFetch as unknown as typeof fetch,
            navigator: { onLine: true } as Navigator,
        });

        expect(outcome).toBe('refreshed');
        expect(doFetch).not.toHaveBeenCalled();
        expect(await db.app_state.get(BRAND_VERSION_KEY)).toEqual({ key: BRAND_VERSION_KEY, value: 'none' });
        await db.delete();
    });

    it('reports unavailable rather than throwing when Cache Storage refuses', async () => {
        const db = freshDb();
        const caches = {
            has: () => Promise.reject(new Error('denied')),
        } as unknown as CacheStorage;

        const outcome = await syncBrandedShellCache({
            brandVersion: 'new000000000',
            db,
            currentUrl: CURRENT_SHELL,
            caches,
            fetch: vi.fn() as unknown as typeof fetch,
            navigator: { onLine: true } as Navigator,
        });

        expect(outcome).toBe('unavailable');
        // Not advanced — a failed sweep must stay retryable.
        expect(await db.app_state.get(BRAND_VERSION_KEY)).toBeUndefined();
        await db.delete();
    });
});

describe('isResumeShell', () => {
    it('matches the guest resume ROUTE by path, and not by substring', () => {
        expect(isResumeShell(RESUME_SHELL)).toBe(true);

        // ⛔ THE CHEAP SPELLING OF THIS PREDICATE PASSES THE LINE ABOVE AND FAILS THE ONE BELOW.
        // `url.includes('/f/resume/')` cannot tell a path from a query string, so it would stop
        // refreshing an ordinary shell whose query happens to carry that text — silently shrinking
        // the sweep rather than the exemption.
        expect(isResumeShell('http://acme.test/f/intake?next=/f/resume/tok-abc123')).toBe(false);
        expect(isResumeShell('http://acme.test/f/intake#/f/resume/tok-abc123')).toBe(false);

        // A prefix match on the SEGMENT, so a form whose slug merely begins with the same letters is
        // still refreshed. `/f/resume` with no token is not the route either.
        expect(isResumeShell('http://acme.test/f/resumed/tok-abc123')).toBe(false);
        expect(isResumeShell('http://acme.test/f/resume')).toBe(false);
        expect(isResumeShell(OTHER_SHELL)).toBe(false);
    });

    it('reports false rather than throwing on a url it cannot parse', () => {
        // ⚠️ Failing OPEN here would skip every entry and quietly disable the sweep this module exists
        // to perform — a much quieter defect than the one the predicate guards against, and one no
        // other case in this file would catch, because they all pass well-formed URLs.
        expect(isResumeShell('not a url')).toBe(false);
        expect(isResumeShell('')).toBe(false);
    });
});
