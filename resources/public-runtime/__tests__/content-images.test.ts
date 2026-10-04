import { describe, expect, it, vi } from 'vitest';
import { CONTENT_IMAGE_CACHE, contentImageCacheKey, contentImagesIn, contentImageUrl, warmContentImages } from '../lib/content-images';
import { field, schemaResponse } from './fixtures';

/**
 * M130 (`R-c9f50df2`) — the guest content-image route, its token-free cache key, and the offline warm-up.
 */
describe('content images on the guest page', () => {
    it('reads an image through the current share token', () => {
        expect(contentImageUrl('tok/en', 'att 1')).toBe('/api/v1/public/content-images/tok%2Fen/att%201');
    });

    it('caches an image under its id alone, so a new token finds the same entry', () => {
        const first = contentImageCacheKey('https://acme.test/api/v1/public/content-images/token-a/att-1');
        const second = contentImageCacheKey('https://acme.test/api/v1/public/content-images/token-b/att-1');

        expect(first).toBe('https://acme.test/api/v1/public/content-images/att-1');
        expect(second).toBe(first);
    });

    it('leaves every other address alone, so the key can never merge unrelated requests', () => {
        for (const url of [
            'https://acme.test/api/v1/public/f/token-a',
            'https://acme.test/api/v1/public/content-images/only-one-segment',
            'https://acme.test/api/v1/public/content-images/token/att/extra',
        ]) {
            expect(contentImageCacheKey(url)).toBe(url);
        }
    });

    it('collects every note image once, in form order, and nothing else', () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'intro', field_type: 'note', sequence: 1, config: { content: [{ type: 'image', attachment_id: 'b', alt: 'x' }, { type: 'divider' }] } }),
                field({ key: 'name', field_type: 'short_text', sequence: 2, config: { content: [{ type: 'image', attachment_id: 'not-a-note' }] } }),
                field({ key: 'outro', field_type: 'note', sequence: 3, config: { content: [{ type: 'image', attachment_id: 'a' }, { type: 'image', attachment_id: 'b' }, { type: 'image', attachment_id: '' }, 'junk'] } }),
            ],
        });

        expect(contentImagesIn(schema)).toEqual(['b', 'a']);
    });

    it('requests only the images not already cached, one at a time', async () => {
        const images = ['cached', 'fresh', 'fresh-2'].map((id) => ({ type: 'image', attachment_id: id }));
        const schema = schemaResponse({ fields: [field({ key: 'intro', field_type: 'note', sequence: 1, config: { content: images } })] });
        const cache = { match: vi.fn(async (key: string) => (key.endsWith('/content-images/cached') ? new Response('') : undefined)) };
        const caches = { open: vi.fn(async () => cache as unknown as Cache) };
        const fetched: string[] = [];
        let inFlight = 0;
        let mostInFlight = 0;

        const requested = await warmContentImages(schema, (id) => contentImageUrl('tok', id), {
            origin: 'https://acme.test',
            caches,
            fetch: async (url) => {
                inFlight++;
                mostInFlight = Math.max(mostInFlight, inFlight);
                fetched.push(url);
                // A macrotask, so a second request started before this one settled would overlap it.
                await new Promise((resolve) => setTimeout(resolve, 0));
                inFlight--;
            },
        });

        expect(caches.open).toHaveBeenCalledWith(CONTENT_IMAGE_CACHE);
        expect(requested).toBe(2);
        expect(fetched).toEqual(['https://acme.test/api/v1/public/content-images/tok/fresh', 'https://acme.test/api/v1/public/content-images/tok/fresh-2']);
        // The image route's limiter never sees a burst from one page.
        expect(mostInFlight).toBe(1);
    });

    it('requests every image where there is no Cache Storage, and shrugs off a failed request', async () => {
        const schema = schemaResponse({
            fields: [field({ key: 'intro', field_type: 'note', sequence: 1, config: { content: [{ type: 'image', attachment_id: 'one' }, { type: 'image', attachment_id: 'two' }] } })],
        });
        const fetchSpy = vi.fn(async () => {
            throw new Error('offline');
        });

        expect(await warmContentImages(schema, (id) => contentImageUrl('tok', id), { origin: 'https://acme.test', caches: null, fetch: fetchSpy })).toBe(2);
        expect(fetchSpy).toHaveBeenCalledTimes(2);
    });

    it('does nothing for a form with no images', async () => {
        const fetchSpy = vi.fn(async () => undefined);

        expect(await warmContentImages(schemaResponse({ fields: [field({ key: 'q' })] }), () => 'x', { origin: 'https://acme.test', caches: null, fetch: fetchSpy })).toBe(0);
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

describe('content images — a schema the warm-up does not expect', () => {
    it('warms nothing, and never rejects, when the schema carries no fields', async () => {
        const odd = { form: {}, version: { id: 'v' } } as unknown as Parameters<typeof contentImagesIn>[0];
        const fetchSpy = vi.fn(async () => undefined);

        expect(contentImagesIn(odd)).toEqual([]);
        await expect(warmContentImages(odd, () => 'x', { origin: 'https://acme.test', caches: null, fetch: fetchSpy })).resolves.toBe(0);
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
