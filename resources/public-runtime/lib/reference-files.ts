/**
 * A form's reference files on the guest page (M132, `R-bf49e4c1`) — where they are read from, and the key the service
 * worker caches each under once it has been opened (`D84`: offline once opened; nothing is fetched in advance).
 *
 * ⚠️ ONE MODULE FOR BOTH SIDES, `content-images.ts`'s precedent: `sw.ts` stores each file under a key and the page
 * asks for the same URL, so the key is computed in one place. It imports nothing that touches the DOM, because
 * `sw.ts` is type-checked against the worker library.
 *
 * ⛔ THE ROUTE IS NOT UNDER `/api/v1/public/f/` — that prefix is the schema cache's, and a file there would evict
 * cached schemas — and the cache key drops the share token, for the image cache's reasons: a token is minted on
 * every visit and lives a day, while an attachment id never changes what it names.
 *
 * ⛔ THE PAGE FETCHES A FILE; IT NEVER NAVIGATES TO ONE. The worker is registered with scope `/f/`, so a file opened
 * in a new tab at `/api/...` is a navigation it never sees, and could never be served offline. A fetch made BY the
 * page is one it sees.
 */

export const REFERENCE_FILE_PREFIX = '/api/v1/public/reference-files/';

/** The runtime cache `sw.ts` registers for them. */
export const REFERENCE_FILE_CACHE = 'guest-reference-files';

/** Where the guest page reads one file: the route takes the current share token, which scopes the read. */
export function referenceFileUrl(shareToken: string, attachmentId: string): string {
    return `${REFERENCE_FILE_PREFIX}${encodeURIComponent(shareToken)}/${encodeURIComponent(attachmentId)}`;
}

/**
 * The key a file is cached under: its absolute URL with the share token removed. A URL that is not a reference file
 * is returned unchanged, so the plugin can never merge two unrelated requests.
 */
export function referenceFileCacheKey(absoluteUrl: string): string {
    const url = new URL(absoluteUrl);
    if (!url.pathname.startsWith(REFERENCE_FILE_PREFIX)) {
        return absoluteUrl;
    }

    const segments = url.pathname.slice(REFERENCE_FILE_PREFIX.length).split('/');
    if (segments.length !== 2 || segments[1] === '') {
        return absoluteUrl;
    }

    return `${url.origin}${REFERENCE_FILE_PREFIX}${segments[1]}`;
}

/** "Visit guide.pdf" — the name a saved PDF is given: the author's label, with the extension it needs. */
export function referenceFileDownloadName(label: string, mimeType: string): string {
    const name = label.trim() === '' ? 'Reference file' : label.trim();
    if (mimeType === 'application/pdf' && !name.toLowerCase().endsWith('.pdf')) {
        return `${name}.pdf`;
    }
    return name;
}
