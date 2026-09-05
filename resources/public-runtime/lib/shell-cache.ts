/**
 * The single declaration of the `/f/*` shell cache's name and its expiry policy (Increment M75).
 *
 * ── ⛔ TWO PROGRAMS NEED THESE FACTS AND THEY MUST NOT BE TWO COPIES ───────────────────────────────
 * `sw.ts` registers the `NetworkFirst` route that creates the cache and owns its `ExpirationPlugin`.
 * `lib/brand-cache.ts` runs in the WINDOW, rewrites entries in that same cache, and — since M75 —
 * has to renew the same expiry clock the plugin keeps. Those two must agree on the cache NAME or the
 * sweep writes into a cache nothing serves, and on the EXPIRY CONFIG or `CacheExpiration` opens its
 * bookkeeping with a different policy than the plugin that wrote it.
 *
 * Before this module the name lived in `brand-cache.ts` under a comment reading *"Must match the
 * `cacheName` of sw.ts's `/f/*` navigation route"*, and `sw.ts` carried the literal. ⚠️ **A
 * must-match comment is a duplicate with a note attached** — the same shape `docs/gate-baselines.md`
 * exists to end for gate numbers, and the same shape `docs/claims/TEMPLATE.md` records for the claim
 * template. The comment was correct on the day it was written; nothing would have caught it drifting.
 *
 * ⚠️ THE OTHER TWO RUNTIME CACHES ARE DELIBERATELY NOT HERE. `guest-shell-assets` and `guest-schema`
 * have exactly one writer each — `sw.ts` — so their literals are declarations rather than copies.
 * Hoisting them would move a fact away from its only reader to no purpose.
 */

/** Must match nothing, because it is the definition: the cache `sw.ts`'s `/f/*` route writes. */
export const SHELL_CACHE = 'guest-shell-html';

/**
 * The expiry policy for `SHELL_CACHE`, passed to `ExpirationPlugin` in the worker and to
 * `CacheExpiration` in the window.
 *
 * ⚠️ `maxEntries` IS NOT COSMETIC AND ITS ORDERING IS LOAD-BEARING. `workbox-expiration` enforces it
 * by walking the `timestamp` index newest-first and deleting everything past the Nth survivor, so the
 * eviction order is whatever last wrote a timestamp — see `refreshCachedShells()` in
 * `brand-cache.ts`, which changes that ordering on the boots where it runs.
 */
export const SHELL_EXPIRATION = { maxEntries: 20, maxAgeSeconds: 7 * 24 * 60 * 60 } as const;
