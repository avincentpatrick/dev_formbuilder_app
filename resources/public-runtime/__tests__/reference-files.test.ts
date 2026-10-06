/**
 * Where a form's reference files are read from (M132, `R-bf49e4c1`, `D84`): the URL and the cache key the guest route and
 * the service worker share. Since M138 (`D92` = A) the guest page lists and opens none, and the route refuses every file;
 * these two stay because the route and the worker's cache route are kept for the Kobo-style rebuild.
 */
import { describe, expect, it } from 'vitest';

import { referenceFileCacheKey, referenceFileUrl } from '../lib/reference-files';

describe('where a reference file is read from', () => {
    it('reads through the token-scoped route, off the schema prefix', () => {
        expect(referenceFileUrl('tok/en', 'att-1')).toBe('/api/v1/public/reference-files/tok%2Fen/att-1');
        expect(referenceFileUrl('t', 'a').startsWith('/api/v1/public/f/')).toBe(false);
    });

    it('caches under a key without the share token, and leaves any other URL alone', () => {
        expect(referenceFileCacheKey('https://acme.test/api/v1/public/reference-files/token-a/att-1')).toBe(
            'https://acme.test/api/v1/public/reference-files/att-1',
        );
        expect(referenceFileCacheKey('https://acme.test/api/v1/public/reference-files/token-b/att-1')).toBe(
            referenceFileCacheKey('https://acme.test/api/v1/public/reference-files/token-a/att-1'),
        );
        for (const other of [
            'https://acme.test/api/v1/public/content-images/token-a/att-1',
            'https://acme.test/api/v1/public/reference-files/only-one-segment',
        ]) {
            expect(referenceFileCacheKey(other)).toBe(other);
        }
    });
});
