/**
 * The resume shell, cached under ONE token-free key (M140, `R-68656155`, `D20` = 2).
 *
 * `/f/resume/{resumeToken}` is a navigation like any `/f/` page, and the token in its path is the whole
 * credential for the respondent's saved answers (`GET /api/v1/public/drafts/{resumeToken}`). Cached under
 * its own URL, the token became the cache KEY: Cache Storage is origin-scoped, so
 * `caches.open('guest-shell-html').keys()` listed every resume link the device had opened, and Workbox's
 * expiry bookkeeping (IndexedDB `workbox-expiration`, keyed by URL) listed them a second time. `D20` kept the
 * offline surface a resume link brings — the offline pill and "Sync now" — and took the token out of the
 * cache instead. Three things do that, and each is needed:
 *
 *  1. every resume link is stored under the one key `RESUME_SHELL_PATH`, which closes both listings;
 *  2. the stored copy has `data-resume-token` blanked, so the single entry is no credential either;
 *  3. the boot takes the token from the ADDRESS (`resumeTokenFromPath()`), which the server verified to
 *     render the page, rather than from a cached copy written for some other link.
 *
 * ⛔ AND THE ROUTE THAT SERVES IT HAS NO NETWORK TIMEOUT (`sw.ts`), WHICH IS WHAT MAKES ONE SHARED KEY SAFE.
 * The `/f/` route answers from the cache after 5 seconds while still ONLINE. Under one shared key that would
 * boot a slow link B with whatever link A left in the cache; with the token now read from the address, it
 * would still boot B's draft into A's form. So the cache answers a resume link only on a real network error,
 * when the draft read fails too and the respondent sees the error state beside the offline surface.
 *
 * ⚠️ Known cost, accepted with `D20`: offline, the shell shown is the LAST resume link cached, so its title,
 * brand and slug may be another form's. A cached resume shell has never rendered the form offline (`M78`).
 */
import { isResumeShell } from './brand-cache';
import { SHELL_CACHE } from './shell-cache';

/** The one key every resume link is cached under: the route's prefix, with no token. */
export const RESUME_SHELL_PATH = '/f/resume/';

/** The cache key for a resume link — the same for every token, so the cache holds one entry. */
export function resumeShellCacheKey(url: string): string {
    return `${new URL(url).origin}${RESUME_SHELL_PATH}`;
}

/** The blade's `data-resume-token` attribute, emptied. Blade escapes the value, so it holds no quote. */
export function blankResumeToken(html: string): string {
    return html.replace(/data-resume-token="[^"]*"/g, 'data-resume-token=""');
}

/** The copy of a resume shell that is written to the cache: the same page with no token in it. */
export async function tokenFreeShell(response: Response): Promise<Response> {
    const headers = new Headers(response.headers);
    headers.delete('content-length'); // the body is shorter now

    return new Response(blankResumeToken(await response.text()), {
        status: response.status,
        statusText: response.statusText,
        headers,
    });
}

/** The resume token a `/f/resume/{token}` address carries, or '' for any other address. */
export function resumeTokenFromPath(pathname: string): string {
    const match = /^\/f\/resume\/([^/]+)\/?$/.exec(pathname);

    if (match === null) {
        return '';
    }

    try {
        return decodeURIComponent(match[1]);
    } catch {
        return ''; // a malformed escape is no token; the boot falls back to the page's own attribute
    }
}

/** A resume shell stored under its own token-bearing URL — what every worker before M140 wrote. */
function isTokenKeyed(url: string): boolean {
    return isResumeShell(url) && new URL(url).pathname !== RESUME_SHELL_PATH;
}

const EXPIRY_DB = 'workbox-expiration';
const EXPIRY_STORE = 'cache-entries';

/**
 * Delete the token-keyed resume shells an older worker left behind: their cache entries and their expiry
 * stamps. Run once, when this worker activates. Without it they would sit on the device until the shell
 * cache's seven-day clock reached them.
 *
 * Every failure is swallowed: this is clean-up, and an activating worker must never fail on it.
 */
export async function purgeTokenKeyedResumeShells(cacheStorage: CacheStorage, idb: IDBFactory): Promise<void> {
    try {
        if (await cacheStorage.has(SHELL_CACHE)) {
            const cache = await cacheStorage.open(SHELL_CACHE);

            for (const request of await cache.keys()) {
                if (isTokenKeyed(request.url)) {
                    await cache.delete(request);
                }
            }
        }
    } catch {
        // Cache Storage refused; the seven-day clock still applies.
    }

    try {
        await purgeExpiryStamps(idb);
    } catch {
        // IndexedDB refused (private mode); the stamps age out with the clock.
    }
}

/**
 * ⛔ THIS READS WORKBOX'S OWN STORE, AND IT MUST NEVER CREATE IT. `workbox-expiration` opens
 * `workbox-expiration` at version 1 and creates its object store in the upgrade. If this code created an
 * empty version-1 database first, Workbox's upgrade would never run and expiry would stop working for every
 * cache on the device. So the open aborts its own upgrade: a database that does not exist stays absent.
 * The record shape (`url`, `cacheName`, indexed by `cacheName`) is the one `brand-cache.test.ts` reads.
 */
function openExistingExpiryDb(idb: IDBFactory): Promise<IDBDatabase | null> {
    return new Promise((resolve) => {
        const request = idb.open(EXPIRY_DB);

        request.onupgradeneeded = () => request.transaction?.abort();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => resolve(null);
        request.onblocked = () => resolve(null);
    });
}

async function purgeExpiryStamps(idb: IDBFactory): Promise<void> {
    const db = await openExistingExpiryDb(idb);

    if (db === null) {
        return;
    }

    try {
        if (!db.objectStoreNames.contains(EXPIRY_STORE)) {
            return;
        }

        await new Promise<void>((resolve, reject) => {
            const transaction = db.transaction(EXPIRY_STORE, 'readwrite');
            const cursorRequest = transaction.objectStore(EXPIRY_STORE).index('cacheName').openCursor(IDBKeyRange.only(SHELL_CACHE));

            cursorRequest.onsuccess = () => {
                const cursor = cursorRequest.result;

                if (cursor === null) {
                    return;
                }

                if (isTokenKeyed((cursor.value as { url: string }).url)) {
                    cursor.delete();
                }

                cursor.continue();
            };
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    } finally {
        db.close();
    }
}
