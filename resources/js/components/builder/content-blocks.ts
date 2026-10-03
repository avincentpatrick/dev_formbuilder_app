/**
 * A note's content blocks on the author's side (M129 — `R-6dedc3a9`'s editor half, `R-f0c5b682`'s images).
 *
 * The SHAPE is `App\Rules\ContentBlocks`, the one rule the builder's save, the blueprint validator and the
 * publish gate all ask. These constants MIRROR its bounds so the editor can stop an author before a save is
 * refused; `ContentBlocksMirrorDriftTest` reads both files and fails when they disagree, so neither copy can
 * move alone. The server stays authoritative either way.
 */

export type CalloutTone = 'info' | 'success' | 'warning' | 'danger';

/** Mirror of `ContentBlocks::TONES`, in its order. */
export const CALLOUT_TONES: readonly CalloutTone[] = ['info', 'success', 'warning', 'danger'];

/** Mirror of `ContentBlocks`' bounds. */
export const CONTENT_LIMITS = {
    maxBlocks: 50,
    maxSpans: 50,
    maxHeadingLength: 300,
    maxTextLength: 2000,
    maxLinkLength: 2000,
    maxAltLength: 300,
} as const;

/** Mirror of `ContentBlocks::LINK_SCHEMES`, in its order. */
export const LINK_SCHEMES: readonly string[] = ['https', 'http', 'mailto', 'tel'];

/**
 * One run of text. A flag that is off is ABSENT rather than false, and a link that is cleared is DELETED rather
 * than set to null or '' — the save accepts a null link and publish refuses it, so a leftover key would pass
 * every save and then block publishing.
 */
export interface ContentSpan {
    text: string | null;
    bold?: boolean;
    italic?: boolean;
    code?: boolean;
    link?: string;
}

export type ContentBlock =
    | { type: 'heading'; level: 1 | 2; text: string | null }
    | { type: 'paragraph'; spans: ContentSpan[] }
    | { type: 'callout'; tone: CalloutTone; spans: ContentSpan[] }
    | { type: 'divider' }
    | { type: 'image'; attachment_id: string; alt: string | null };

export type ContentBlockType = ContentBlock['type'];

/** What the upload answers with: the new image's id, where staff read it, and whether it has passed its check. */
export interface ContentImageRef {
    id: string;
    url: string;
    servable: boolean;
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Upload one image for a note's content. Multipart, with the XSRF cookie as the header Laravel reads — the
 * builder never makes an Inertia visit (`builderClient.ts`), and `builderClient` sends JSON only, so this is the
 * `MediaInput.vue` posture. Rejects with the server's own sentence when it refuses.
 */
export async function uploadContentImage(formId: string, file: File): Promise<ContentImageRef> {
    const body = new FormData();
    body.append('file', file);

    const headers: Record<string, string> = { Accept: 'application/json' };
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf !== null) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    let response: Response;
    try {
        response = await fetch(`/forms/${formId}/content-images`, { method: 'POST', body, headers, credentials: 'same-origin' });
    } catch {
        throw new Error('The upload failed. Check your connection and try again.');
    }

    const json = (await response.json().catch(() => null)) as
        | { data?: ContentImageRef; errors?: Record<string, string[]>; error?: { message?: string }; message?: string }
        | null;

    if (response.ok && json?.data !== undefined && typeof json.data.id === 'string') {
        return json.data;
    }

    const firstError = json?.errors !== undefined ? Object.values(json.errors)[0]?.[0] : undefined;
    throw new Error(firstError ?? json?.error?.message ?? json?.message ?? 'The image was not accepted. Please try another file.');
}
