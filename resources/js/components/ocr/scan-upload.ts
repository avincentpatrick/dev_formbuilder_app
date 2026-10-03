/**
 * Sending a scan from the scans page (M129 — single-form OCR groundwork 2). Pure helpers: the page wires them
 * to its file input.
 *
 * The checks here MIRROR the server's (`OcrScanService` and `StoreOcrScanRequest`) so a reviewer learns
 * before a long upload that a sixth page or a PDF with photos will be refused. They are a courtesy, never the
 * gate: the server re-checks every one, and the limits arrive from `config/ocr.php` in the page's props
 * rather than being written here.
 */

export interface ScanLimits {
    max_pages: number;
    accepted_types: string[];
    max_bytes_per_file: number;
    max_bytes_per_scan: number;
}

/** A plain-words refusal for the chosen files, or null when they may be sent. */
export function checkScanFiles(files: File[], limits: ScanLimits): string | null {
    if (files.length === 0) {
        return 'Choose the photos or the PDF of one filled-in form.';
    }
    if (files.length > limits.max_pages) {
        return `A scan can have at most ${limits.max_pages} pages.`;
    }
    if (files.length > 1 && files.some((file) => file.type === 'application/pdf')) {
        return 'Upload a PDF scan on its own. Photos of one form can be uploaded together, one photo per page.';
    }

    const accepted = new Set(limits.accepted_types);
    const refused = files.find((file) => !accepted.has(file.type));
    if (refused !== undefined) {
        return `“${refused.name}” is not a JPEG, PNG, WebP or PDF file.`;
    }

    const tooLarge = files.find((file) => file.size > limits.max_bytes_per_file);
    if (tooLarge !== undefined) {
        return `“${tooLarge.name}” is larger than ${megabytes(limits.max_bytes_per_file)} MB.`;
    }

    const total = files.reduce((sum, file) => sum + file.size, 0);
    if (total > limits.max_bytes_per_scan) {
        return `The pages of one scan can add up to at most ${megabytes(limits.max_bytes_per_scan)} MB.`;
    }

    return null;
}

function megabytes(bytes: number): number {
    return Math.floor(bytes / 1_000_000);
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

/** The server's refusal sentence, from either envelope: `{ error: { message } }` or a validation `errors` map. */
function refusalOf(body: unknown): string | null {
    if (body === null || typeof body !== 'object') {
        return null;
    }
    const record = body as { error?: { message?: unknown }; errors?: Record<string, unknown>; message?: unknown };
    if (typeof record.error?.message === 'string') {
        return record.error.message;
    }
    for (const messages of Object.values(record.errors ?? {})) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') {
            return messages[0];
        }
    }
    return typeof record.message === 'string' ? record.message : null;
}

/**
 * POST the pages as one scan and resolve its id. The route is the session one, so the XSRF cookie travels as
 * the header Laravel reads — the same posture as `MediaInput.vue`'s upload. Rejects with the server's own
 * sentence when it refuses.
 */
export async function uploadScan(url: string, files: File[]): Promise<{ id: string }> {
    const form = new FormData();
    for (const file of files) {
        form.append('pages[]', file);
    }

    const headers: Record<string, string> = { Accept: 'application/json' };
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf !== null) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    let response: Response;
    try {
        response = await fetch(url, { method: 'POST', body: form, headers, credentials: 'same-origin' });
    } catch {
        throw new Error('The upload failed. Check your connection and try again.');
    }

    const body: unknown = await response.json().catch(() => null);
    const id = (body as { data?: { id?: unknown } } | null)?.data?.id;
    if (response.ok && typeof id === 'string') {
        return { id };
    }

    throw new Error(refusalOf(body) ?? 'The scan was not accepted. Please try again.');
}
