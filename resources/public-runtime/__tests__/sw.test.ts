/**
 * The service worker's runtime-cache ROUTE TABLE (M77).
 *
 * ⛔ WHY THIS FILE DID NOT EXIST BEFORE, AND WHY THAT MATTERED. `sw.ts` registers three runtime
 * caches and had never been mounted in this repository — `register-sw.test.ts` covers the
 * REGISTRATION call, not the worker. So the one property that distinguishes the three routes from
 * each other, the response-status filter, was asserted nowhere, and the route that lacked it went
 * unnamed through two increments that were both looking straight at the file.
 *
 * ⛔ THE THING THAT MAKES THIS TESTABLE AT ALL: Workbox PREPENDS its own `cacheOkAndOpaquePlugin`
 * to any strategy that supplies no `CacheableResponsePlugin`, and that default admits
 * `status === 0` — an opaque cross-origin response — as cacheable. So "no filter" is not an absent
 * behaviour that a test would have to prove a negative about; it is a DIFFERENT, permissive
 * behaviour, and a test can drive it directly.
 *
 * ⚠️ THE PROBE MUST USE A REAL `Response`, AND THE OBVIOUS FORM OF THIS TEST DOES NOT WORK.
 * `CacheableResponse.isResponseCacheable()` opens with `assert.isInstance(response, Response)` in
 * every non-production build — three lines above the status comparison — so passing a plain
 * `{ status: 0 }` literal THROWS `incorrect-class` rather than returning null, and every route goes
 * red together instead of only the unfiltered one. That is not a weak discriminator, it is a
 * non-functioning test, and it was measured against the vendored workbox before this file was
 * written. `new Response(null, { status: 0 })` is also unavailable — the constructor throws
 * `RangeError`, status must be 200..599. The only status-0 `Response` in the platform is
 * `Response.error()`, which happy-dom supplies.
 */

import { describe, expect, it, vi } from 'vitest';
import { isResumeShell } from '../lib/brand-cache';

/**
 * ⛔ THE MODULE IS IMPORTED STATICALLY, AT FILE SCOPE, AND THAT IS NOT A STYLE CHOICE — IT IS THE
 * SECOND INSTANCE OF A TRAP `M76` RECORDED AND `M77` WALKED INTO ANYWAY. The first draft of this file
 * did `await import('../sw')` inside a `beforeEach`. It passed when the file was run alone and when
 * run beside `register-sw.test.ts`, and **timed out in a full 136-file run** — "Hook timed out in
 * 10000ms", pointing at the hook and naming nothing else. `M76` hit the identical shape mounting
 * `App.vue` and wrote down the remedy verbatim: a dynamic `import()` inside a Vitest case or hook may
 * never resolve; a static import at file scope works.
 *
 * ⚠️ WHAT `M76` DID NOT KNOW, AND THIS RUN MEASURED: IT IS LOAD-DEPENDENT. That is what makes it
 * expensive — the failing configuration is the full suite, which is the one a developer runs least
 * often and CI runs always, so the naive read is "flaky test" and the fix is a retry.
 *
 * The globals below are set in a `vi.hoisted()` block because a static import evaluates `sw.ts`'s
 * module body immediately, before any hook could run. `sw.ts` needs exactly one thing to exist at
 * that moment: `self.skipWaiting`. `precacheAndRoute(self.__WB_MANIFEST)` is mocked to a no-op, so
 * the manifest's value is irrelevant, and it is supplied only so the read is not a surprise later.
 */
const { routes } = vi.hoisted(() => {
    const captured: Array<{ match: unknown; strategy: { cacheName?: string; plugins?: unknown[] } }> = [];

    const scope = globalThis as unknown as {
        __WB_MANIFEST: unknown[];
        __WB_DISABLE_DEV_LOGS: boolean;
        skipWaiting: () => void;
    };

    scope.__WB_MANIFEST = [];
    scope.skipWaiting = () => undefined;

    // ⚠️ The opaque-response arm walks Workbox's not-cacheable branch, which in a non-production build
    // dumps the whole `Response` and its headers to stdout for EVERY rejection — six screens of noise
    // on a fully passing run. This repository's own rule, from the AbortError row M76 closed: output on
    // a green run is what teaches a reader to skip output. Vite strips this branch from the shipped
    // worker (`public/build/sw.js` contains none of these strings), so it changes nothing about
    // production.
    scope.__WB_DISABLE_DEV_LOGS = true;

    return { routes: captured };
});

vi.mock('workbox-routing', () => ({
    registerRoute: (match: unknown, strategy: { cacheName?: string; plugins?: unknown[] }) => {
        routes.push({ match, strategy });
    },
}));

// The rest of the worker's imports are inert for this file's purpose: it asserts the route table,
// not precaching, claiming or the Background-Sync drain. Each is stubbed to a no-op so importing
// `sw.ts` has no side effect beyond filling `routes`.
vi.mock('workbox-precaching', () => ({
    cleanupOutdatedCaches: () => undefined,
    precacheAndRoute: () => undefined,
}));
vi.mock('workbox-core', () => ({ clientsClaim: () => undefined }));
vi.mock('../lib/db', () => ({ openDb: () => Promise.resolve({}) }));
vi.mock('../lib/replay', () => ({ replayOutbox: () => Promise.resolve(undefined) }));

// The subject. Static, at file scope — see the note at the top of this file.
import '../sw';

/**
 * Ask a strategy's plugin chain the one question the cache actually asks it: may this response be
 * written? Workbox returns the response to store it and a nullish value to reject it.
 */
async function isCacheable(
    strategy: { plugins?: unknown[] },
    response: Response,
): Promise<boolean> {
    const plugins = (strategy.plugins ?? []) as Array<{
        cacheWillUpdate?: (o: { response: Response }) => Promise<Response | null | undefined>;
    }>;

    for (const plugin of plugins) {
        if (typeof plugin.cacheWillUpdate !== 'function') {
            continue;
        }
        const verdict = await plugin.cacheWillUpdate({ response });
        if (verdict === null || verdict === undefined) {
            return false;
        }
    }

    return true;
}

function routeFor(cacheName: string): { cacheName?: string; plugins?: unknown[] } {
    const found = routes.find((r) => r.strategy.cacheName === cacheName);
    expect(found, `sw.ts registers no route with cacheName '${cacheName}'`).toBeDefined();
    return found!.strategy;
}

type MatchArg = { request: Request; url: URL; sameOrigin: boolean };

/**
 * The route's MATCHER, which decides whether a URL enters the cache at all.
 *
 * ⚠️ Until M78 this file captured `match` and never invoked one, so every assertion here was about what a
 * route does with a response it had ALREADY decided to handle — and nothing at all about which URLs it
 * claims. That is the half the credential-exposure question turns on.
 */
function matcherFor(cacheName: string): (o: MatchArg) => boolean {
    const found = routes.find((r) => r.strategy.cacheName === cacheName);
    expect(found, `sw.ts registers no route with cacheName '${cacheName}'`).toBeDefined();
    return found!.match as (o: MatchArg) => boolean;
}

/**
 * A navigation probe.
 *
 * ⛔ `request.mode` IS NOT SETTABLE FROM SCRIPT — `new Request(url, { mode: 'navigate' })` throws, and
 * `lib/brand-cache.ts` records the same constraint for the same reason. So the probe is an object literal
 * cast, exactly as `Response.error()` is the only way to reach status 0 in the arms below. A real `Request`
 * here would not be more faithful; it would be impossible.
 */
function nav(path: string): MatchArg {
    return { request: { mode: 'navigate' } as Request, url: new URL(`https://acme.test${path}`), sameOrigin: true };
}

/** A same-origin `fetch()`, which is what every API call and every `remint()` actually is. */
function apiGet(path: string): MatchArg {
    return { request: { mode: 'cors' } as Request, url: new URL(`https://acme.test${path}`), sameOrigin: true };
}

describe('sw.ts runtime cache route table', () => {
    it('registers the six runtime caches, the shell cache by two routes', () => {
        // A floor on the table itself. Without it, a route deleted outright would take its own
        // status assertion with it and the file would stay green while covering nothing — the
        // vacuous-success shape this repository gates everywhere else. M130 added the fourth, M132 the fifth, M133 the sixth.
        // M140 added a seventh ROUTE but no cache: resume links write the shell cache under one token-free key. M141 the
        // eighth, the CSV choice lists.
        expect(routes).toHaveLength(8);
        expect(routes.map((r) => r.strategy.cacheName)).toEqual([
            'guest-shell-assets',
            'guest-schema',
            'guest-shell-html',
            'guest-shell-html',
            'guest-content-images',
            'guest-reference-files',
            'guest-linked-choices',
            'guest-choice-lists',
        ]);
    });

    it.each(['guest-shell-assets', 'guest-schema', 'guest-shell-html', 'guest-content-images', 'guest-reference-files', 'guest-linked-choices', 'guest-choice-lists'])(
        'caches a 200 on %s',
        async (cacheName) => {
            // The control arm. If this ever goes red the filter is too strict, not too loose, and
            // the route has stopped caching the thing it exists to cache.
            expect(await isCacheable(routeFor(cacheName), new Response('', { status: 200 }))).toBe(true);
        },
    );

    it.each(['guest-shell-assets', 'guest-schema', 'guest-shell-html', 'guest-content-images', 'guest-reference-files', 'guest-linked-choices', 'guest-choice-lists'])(
        'refuses an opaque (status 0) response on %s',
        async (cacheName) => {
            // ⛔ THE DISCRIMINATOR, AND THE ONLY ARM THAT WAS RED BEFORE M77. Delete the
            // CacheableResponsePlugin from any one of the three routes and Workbox prepends its
            // permissive default instead, `cacheOkAndOpaquePlugin`, which returns the response for
            // `status === 0` — so exactly that route flips to `true` here while the other two stay
            // green. Proved by deliberate defect, not by observing a pass.
            expect(await isCacheable(routeFor(cacheName), Response.error())).toBe(false);
        },
    );
});

describe('sw.ts route matchers — which URLs enter a cache at all', () => {
    it('claims a guest form shell navigation for the shell cache, and only that cache', () => {
        // The control. If this goes red the runtime has stopped caching the thing it exists to cache and
        // every assertion below is measuring a worker that no longer works offline.
        expect(matcherFor('guest-shell-html')(nav('/f/clinic-intake'))).toBe(true);
        expect(matcherFor('guest-schema')(nav('/f/clinic-intake'))).toBe(false);
        expect(matcherFor('guest-shell-assets')(nav('/f/clinic-intake'))).toBe(false);
    });

    it('does not claim a schema fetch as a navigation', () => {
        // `remint()` and `fetchSchema()` are `fetch()`, never navigations, so the shell route must not
        // take them even though their path starts with `/f/` after the API prefix.
        expect(matcherFor('guest-schema')(apiGet('/api/v1/public/f/some-share-token'))).toBe(true);
        expect(matcherFor('guest-shell-html')(apiGet('/api/v1/public/f/some-share-token'))).toBe(false);
    });

    it('⛔ KEEPS THE RESUME READ OUT OF EVERY CACHE, WHICH ONE ROUTE RENAME WOULD UNDO', () => {
        // ⛔ THIS IS THE HIGHEST-VALUE ASSERTION IN THE FILE AND IT PINS AN ACCIDENT (M78).
        // `GET /api/v1/public/drafts/{resumeToken}` answers the FULL answer map plus a freshly minted
        // share token, and it carries no auth middleware — the token in the path is the whole credential.
        // It escapes caching for exactly one reason: its prefix is `drafts/` and the schema route matches
        // `f/`. `routes/api.php` warns about this at the site, in prose, where nothing can enforce it.
        // Rename the route under `f/`, or consolidate the two public API groups, and a complete answer
        // document lands in a cache with a seven-day clock. This arm is what turns that comment into a gate.
        // ✅ PROVED BY DELIBERATE DEFECT, NOT BY OBSERVING A PASS (M78). Widen the schema route's prefix
        // in `sw.ts` from `/api/v1/public/f/` to `/api/v1/public/` — one token, sha256 confirmed to move —
        // and THIS ARM ALONE goes red (`expected true to be false`) while the other ten stay green,
        // control included. That is the rename this comment warns about, performed. `scripts/mutate.php`
        // could not drive it: it runs Pest in the app container, and this is Vitest — the limitation
        // already filed as its own backlog row. So the mutation was applied by hand, with the sha256
        // checked before and after and the restore verified by byte comparison rather than `git checkout`.
        const resumeRead = apiGet('/api/v1/public/drafts/eyJhbGciOi.some.token');

        expect(matcherFor('guest-schema')(resumeRead)).toBe(false);
        expect(matcherFor('guest-shell-html')(resumeRead)).toBe(false);
        expect(resumeRoute().match(resumeRead)).toBe(false);
        expect(matcherFor('guest-shell-assets')(resumeRead)).toBe(false);
        expect(matcherFor('guest-content-images')(resumeRead)).toBe(false);
        expect(matcherFor('guest-reference-files')(resumeRead)).toBe(false);
        expect(matcherFor('guest-linked-choices')(resumeRead)).toBe(false);
        expect(matcherFor('guest-choice-lists')(resumeRead)).toBe(false);
    });

    it('claims a content image for the image cache only, and keys it without the share token (M130)', async () => {
        const image = apiGet('/api/v1/public/content-images/token-a/att-1');

        expect(matcherFor('guest-content-images')(image)).toBe(true);
        // Off the schema prefix on purpose: an image there would evict a cached schema.
        expect(matcherFor('guest-schema')(image)).toBe(false);
        expect(matcherFor('guest-shell-html')(image)).toBe(false);
        expect(matcherFor('guest-content-images')(apiGet('/api/v1/public/f/some-share-token'))).toBe(false);

        const plugins = (routeFor('guest-content-images').plugins ?? []) as Array<{
            cacheKeyWillBeUsed?: (o: { request: Request; mode: string }) => Promise<Request | string>;
        }>;
        const keyPlugin = plugins.find((plugin) => typeof plugin.cacheKeyWillBeUsed === 'function');
        expect(keyPlugin, 'the image route has no cache-key plugin').toBeDefined();

        const keyFor = (token: string) =>
            keyPlugin!.cacheKeyWillBeUsed!({ request: new Request(`https://acme.test/api/v1/public/content-images/${token}/att-1`), mode: 'read' });

        // A token minted on the next visit must find the same entry, or every visit re-downloads every image.
        expect(await keyFor('token-a')).toBe('https://acme.test/api/v1/public/content-images/att-1');
        expect(await keyFor('token-b')).toBe(await keyFor('token-a'));
    });

    it('claims a reference file for its own cache only, and keys it without the share token (M132, D84)', async () => {
        const file = apiGet('/api/v1/public/reference-files/token-a/att-9');

        expect(matcherFor('guest-reference-files')(file)).toBe(true);
        // Off the schema prefix and off the image prefix: each cache holds one kind of thing.
        expect(matcherFor('guest-schema')(file)).toBe(false);
        expect(matcherFor('guest-content-images')(file)).toBe(false);
        expect(matcherFor('guest-reference-files')(apiGet('/api/v1/public/content-images/token-a/att-1'))).toBe(false);
        expect(matcherFor('guest-reference-files')(apiGet('/api/v1/public/f/some-share-token'))).toBe(false);

        const plugins = (routeFor('guest-reference-files').plugins ?? []) as Array<{
            cacheKeyWillBeUsed?: (o: { request: Request; mode: string }) => Promise<Request | string>;
        }>;
        const keyPlugin = plugins.find((plugin) => typeof plugin.cacheKeyWillBeUsed === 'function');
        expect(keyPlugin, 'the reference-file route has no cache-key plugin').toBeDefined();

        const keyFor = (token: string) =>
            keyPlugin!.cacheKeyWillBeUsed!({ request: new Request(`https://acme.test/api/v1/public/reference-files/${token}/att-9`), mode: 'read' });

        // Opened under one visit's token, it must be found under the next visit's, offline.
        expect(await keyFor('token-a')).toBe('https://acme.test/api/v1/public/reference-files/att-9');
        expect(await keyFor('token-b')).toBe(await keyFor('token-a'));
    });

    it('claims a linked-choices read for its own cache only, and keys it by VERSION without the share token (M133, D60)', async () => {
        const lists = apiGet('/api/v1/public/linked-choices/token-a/ver-7');

        expect(matcherFor('guest-linked-choices')(lists)).toBe(true);
        // Beside the schema, never under it: a list there would be cached AS a schema and evict one.
        expect(matcherFor('guest-schema')(lists)).toBe(false);
        expect(matcherFor('guest-reference-files')(lists)).toBe(false);
        expect(matcherFor('guest-linked-choices')(apiGet('/api/v1/public/f/some-share-token'))).toBe(false);

        const plugins = (routeFor('guest-linked-choices').plugins ?? []) as Array<{
            cacheKeyWillBeUsed?: (o: { request: Request; mode: string }) => Promise<Request | string>;
        }>;
        const keyPlugin = plugins.find((plugin) => typeof plugin.cacheKeyWillBeUsed === 'function');
        expect(keyPlugin, 'the linked-choices route has no cache-key plugin').toBeDefined();

        const keyFor = (token: string, version = 'ver-7') =>
            keyPlugin!.cacheKeyWillBeUsed!({ request: new Request(`https://acme.test/api/v1/public/linked-choices/${token}/${version}`), mode: 'read' });

        // Fetched under one visit's token, the list must be found under the next visit's, offline — and a
        // republished version is a different list.
        expect(await keyFor('token-a')).toBe('https://acme.test/api/v1/public/linked-choices/ver-7');
        expect(await keyFor('token-b')).toBe(await keyFor('token-a'));
        expect(await keyFor('token-a', 'ver-8')).not.toBe(await keyFor('token-a'));
    });

    it('claims a CSV choice-list read for its own cache only, keyed by VERSION without the share token (M141, D95)', async () => {
        const lists = apiGet('/api/v1/public/choice-lists/token-a/ver-7');

        expect(matcherFor('guest-choice-lists')(lists)).toBe(true);
        // Beside the schema and beside the linked lists: a different freshness, so a different cache.
        expect(matcherFor('guest-schema')(lists)).toBe(false);
        expect(matcherFor('guest-linked-choices')(lists)).toBe(false);
        expect(matcherFor('guest-choice-lists')(apiGet('/api/v1/public/linked-choices/token-a/ver-7'))).toBe(false);

        const plugins = (routeFor('guest-choice-lists').plugins ?? []) as Array<{
            cacheKeyWillBeUsed?: (o: { request: Request; mode: string }) => Promise<Request | string>;
        }>;
        const keyPlugin = plugins.find((plugin) => typeof plugin.cacheKeyWillBeUsed === 'function');
        expect(keyPlugin, 'the choice-list route has no cache-key plugin').toBeDefined();

        const keyFor = (token: string, version = 'ver-7') =>
            keyPlugin!.cacheKeyWillBeUsed!({ request: new Request(`https://acme.test/api/v1/public/choice-lists/${token}/${version}`), mode: 'read' });

        // Loaded under one visit's token, the list must be found under the next visit's, offline.
        expect(await keyFor('token-a')).toBe('https://acme.test/api/v1/public/choice-lists/ver-7');
        expect(await keyFor('token-b')).toBe(await keyFor('token-a'));
        expect(await keyFor('token-a', 'ver-8')).not.toBe(await keyFor('token-a'));
    });
});

/**
 * The resume shell's route: the one route that claims a resume navigation (M140).
 *
 * Found by what it CLAIMS rather than by its cache name, because it shares `guest-shell-html` with the shell route.
 * Exactly one route may claim a resume link; two would leave which one wins to registration order.
 */
function resumeRoute(): { match: (o: MatchArg) => boolean; strategy: { cacheName?: string; plugins?: unknown[] } } {
    const claiming = routes.filter((r) => (r.match as (o: MatchArg) => boolean)(nav('/f/resume/tok-a')));
    expect(claiming, 'exactly one route claims a resume navigation').toHaveLength(1);

    return claiming[0] as { match: (o: MatchArg) => boolean; strategy: { cacheName?: string; plugins?: unknown[] } };
}

/** What the strategy would write to the cache: each `cacheWillUpdate` in order, as Workbox runs them. */
async function cachedCopy(strategy: { plugins?: unknown[] }, response: Response): Promise<Response | null> {
    const plugins = (strategy.plugins ?? []) as Array<{
        cacheWillUpdate?: (o: { response: Response }) => Promise<Response | null | undefined>;
    }>;
    let copy: Response | null = response;

    for (const plugin of plugins) {
        if (typeof plugin.cacheWillUpdate === 'function' && copy !== null) {
            copy = (await plugin.cacheWillUpdate({ response: copy })) ?? null;
        }
    }

    return copy;
}

describe('the resume shell — cached under one token-free key (M140, R-68656155, D20 = 2)', () => {
    // Until M140 this file PINNED the exposure: `/f/resume/{token}` was a navigation under `/f/`, so the shell route
    // cached it under its own token-bearing URL, and `caches.open('guest-shell-html').keys()` listed every resume link
    // on the device. `D20` kept the offline surface such a link brings (the offline pill, and the "Sync now" that
    // `docs/offline-first-sync-design.md` §7 makes the iOS Background-Sync fallback) and took the token out instead.

    it('gives a resume link a route of its own, which the shell route leaves alone', () => {
        const { match, strategy } = resumeRoute();

        expect(strategy.cacheName).toBe('guest-shell-html');
        expect(matcherFor('guest-shell-html')(nav('/f/resume/tok-a'))).toBe(false);
        expect(match(nav('/f/resume/eyJhbGciOi.some.token'))).toBe(true);
        // Its control: the ordinary shell is still the shell route's, and nothing else is this one's.
        expect(match(nav('/f/clinic-intake'))).toBe(false);
        expect(match(nav('/f/resumed/tok-a'))).toBe(false);
        expect(match(apiGet('/f/resume/tok-a'))).toBe(false);
    });

    it('keys every resume link to ONE entry with no token in it, which the brand sweep still skips', async () => {
        const plugins = (resumeRoute().strategy.plugins ?? []) as Array<{
            cacheKeyWillBeUsed?: (o: { request: Request; mode: string }) => Promise<Request | string>;
        }>;
        const keyPlugin = plugins.find((plugin) => typeof plugin.cacheKeyWillBeUsed === 'function');
        expect(keyPlugin, 'the resume route has no cache-key plugin').toBeDefined();

        const keyFor = (token: string, mode = 'write') =>
            keyPlugin!.cacheKeyWillBeUsed!({ request: new Request(`https://acme.test/f/resume/${token}`), mode });

        // Written under one key and read back under the same one, whatever the token: nothing to list.
        expect(await keyFor('tok-a')).toBe('https://acme.test/f/resume/');
        expect(await keyFor('tok-b')).toBe(await keyFor('tok-a'));
        expect(await keyFor('tok-b', 'read')).toBe(await keyFor('tok-a'));
        // The sweep in `lib/brand-cache.ts` must never re-fetch it: there is no link to fetch.
        expect(isResumeShell(String(await keyFor('tok-a')))).toBe(true);
    });

    it('writes a copy with the resume token blanked, and only for a 200', async () => {
        const { strategy } = resumeRoute();
        const page = '<div id="app" data-share-token="share-1" data-resume-token="tok-secret-1"></div>';
        const copy = await cachedCopy(
            strategy,
            new Response(page, { status: 200, headers: { 'content-type': 'text/html', 'content-length': String(page.length) } }),
        );

        expect(copy).not.toBeNull();
        const stored = await copy!.text();
        expect(stored).not.toContain('tok-secret-1');
        expect(stored).toContain('data-resume-token=""');
        // The share token is the ordinary per-version one every `/f/{slug}` shell caches, not a draft credential.
        expect(stored).toContain('data-share-token="share-1"');
        expect(copy!.headers.get('content-type')).toBe('text/html');
        expect(copy!.headers.get('content-length')).toBeNull();

        expect(await cachedCopy(strategy, new Response('gone', { status: 404 }))).toBeNull();
        expect(await cachedCopy(strategy, Response.error())).toBeNull();
    });

    it('⛔ answers from the cache only on a network error — no timeout, unlike the shell route', () => {
        // ⛔ THE LINE THAT MAKES ONE SHARED KEY SAFE. NetworkFirst with a timeout serves the cached copy while still
        // ONLINE once the timeout passes; under one key that boots a slow resume link with another link's page.
        // Read from Workbox's own field (7.4.1, `NetworkFirst._networkTimeoutSeconds`), because the behaviour itself
        // needs a FetchEvent this file cannot construct. The shell route's 5 is the control that the field is real.
        const timeoutOf = (strategy: unknown) => (strategy as { _networkTimeoutSeconds: number })._networkTimeoutSeconds;

        expect(timeoutOf(routeFor('guest-shell-html'))).toBe(5);
        expect(timeoutOf(resumeRoute().strategy)).toBe(0);
    });
});
