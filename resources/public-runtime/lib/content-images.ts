/**
 * A note's content images on the guest page (M130, `R-c9f50df2`) — where they are read from, how the service
 * worker caches them, and how a loaded form makes sure they are there offline.
 *
 * ⚠️ ONE MODULE FOR BOTH SIDES, THE `shell-cache.ts` PRECEDENT. `sw.ts` stores each image under a key and the
 * page checks that key before warming; a key computed twice is two copies of one fact, so both import it here.
 *
 * ⛔ THE ROUTE IS DELIBERATELY NOT UNDER `/api/v1/public/f/`. That prefix is the schema cache's
 * (`guest-schema`, NetworkFirst, 20 entries), so an image there would evict cached schemas, and
 * `ServiceWorkerCachePrefixRouteTest` refuses any second route under it. It is also off `throttle:guest`,
 * whose per-token budget a note's 50 images would spend before the respondent could submit.
 *
 * ⛔ THE CACHE KEY DROPS THE SHARE TOKEN. A token is minted on every visit and lives 24 hours, while an
 * attachment id never changes; keyed by the full URL, every visit would re-download every image, and a tab
 * left open past a day would ask for later images with a dead token.
 */
import type { SchemaResponse } from './types';

export const CONTENT_IMAGE_PREFIX = '/api/v1/public/content-images/';

/** The runtime cache `sw.ts` registers for them. */
export const CONTENT_IMAGE_CACHE = 'guest-content-images';

/** Where the guest page reads one image: the route takes the current share token, which scopes the read. */
export function contentImageUrl(shareToken: string, attachmentId: string): string {
    return `${CONTENT_IMAGE_PREFIX}${encodeURIComponent(shareToken)}/${encodeURIComponent(attachmentId)}`;
}

/**
 * The key an image is cached under: its absolute URL with the share token removed. A URL that is not a content
 * image is returned unchanged, so the plugin can never merge two unrelated requests.
 */
export function contentImageCacheKey(absoluteUrl: string): string {
    const url = new URL(absoluteUrl);
    if (!url.pathname.startsWith(CONTENT_IMAGE_PREFIX)) {
        return absoluteUrl;
    }

    const segments = url.pathname.slice(CONTENT_IMAGE_PREFIX.length).split('/');
    if (segments.length !== 2 || segments[1] === '') {
        return absoluteUrl;
    }

    return `${url.origin}${CONTENT_IMAGE_PREFIX}${segments[1]}`;
}

/**
 * Every content image the version's notes name, once each, in form order — an image block with a non-empty
 * string `attachment_id`, the same rule `note-content.ts`'s `renderableBlocks()` draws by. Read here rather than
 * imported from there because `sw.ts` imports this module and is type-checked against the worker library, where
 * the builder modules that file reaches (which touch `document`) do not compile.
 */
export function contentImagesIn(schema: SchemaResponse): string[] {
    const ids: string[] = [];
    for (const field of schema.version.schema.fields) {
        const content = field.field_type === 'note' ? field.config?.content : undefined;
        if (!Array.isArray(content)) {
            continue;
        }
        for (const block of content) {
            if (typeof block !== 'object' || block === null) {
                continue;
            }
            const { type, attachment_id: id } = block as { type?: unknown; attachment_id?: unknown };
            if (type === 'image' && typeof id === 'string' && id !== '' && !ids.includes(id)) {
                ids.push(id);
            }
        }
    }

    return ids;
}

export interface WarmDependencies {
    origin: string;
    fetch: (url: string) => Promise<unknown>;
    /** The Cache Storage API, or null where the browser has none — then every image is simply requested. */
    caches: Pick<CacheStorage, 'open'> | null;
}

/**
 * Request every content image the form names that is not already cached, one at a time so the image route's
 * limiter never sees a burst, so a step the respondent reaches offline still shows its pictures. The service
 * worker does the caching; this only makes sure each image has been asked for once while online. A failure is
 * ignored: the renderer shows the description instead, and the next online load tries again.
 *
 * @returns how many images were requested
 */
export async function warmContentImages(schema: SchemaResponse, urlFor: (attachmentId: string) => string, deps: WarmDependencies): Promise<number> {
    const ids = contentImagesIn(schema);
    if (ids.length === 0) {
        return 0;
    }

    const cache = deps.caches !== null ? await deps.caches.open(CONTENT_IMAGE_CACHE).catch(() => null) : null;
    let requested = 0;

    for (const id of ids) {
        const url = new URL(urlFor(id), deps.origin).href;
        if (cache !== null && (await cache.match(contentImageCacheKey(url)).catch(() => undefined)) !== undefined) {
            continue;
        }
        requested++;
        await deps.fetch(url).catch(() => undefined);
    }

    return requested;
}
