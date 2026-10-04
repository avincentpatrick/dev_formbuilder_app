/**
 * The Files section's requests (M132, `R-bf49e4c1`): list, attach, rename and remove a form's reference files on its
 * draft. JSON `fetch` with the XSRF cookie as the header Laravel reads — the content-image upload's posture
 * (`builder/content-blocks.ts`), because the section lives in the builder too, which never makes an Inertia visit, and
 * an upload is multipart. Every failure rejects with the server's own sentence, so the panel shows exactly why.
 */
import type { ReferenceFileRow } from '@/components/forms/types';

const FALLBACK = 'That did not work. Check your connection and try again.';

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

/** The first message a refusal carries: a field error, the error envelope, or Laravel's top-level message. */
function refusal(json: unknown, fallback: string): string {
    const body = (json ?? {}) as { errors?: Record<string, string[]>; error?: { message?: string }; message?: string };
    const firstError = body.errors !== undefined ? Object.values(body.errors)[0]?.[0] : undefined;
    return firstError ?? body.error?.message ?? body.message ?? fallback;
}

async function send(url: string, init: { method: string; body?: BodyInit; json?: unknown }, fallback: string): Promise<unknown> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf !== null) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }
    let body = init.body;
    if (init.json !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(init.json);
    }

    let response: Response;
    try {
        response = await fetch(url, { method: init.method, body, headers, credentials: 'same-origin' });
    } catch {
        throw new Error(FALLBACK);
    }

    if (response.status === 204) {
        return null;
    }
    const json: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        throw new Error(refusal(json, fallback));
    }
    return json;
}

function base(formId: string): string {
    return `/forms/${encodeURIComponent(formId)}/reference-files`;
}

function rowOf(json: unknown): ReferenceFileRow {
    const data = (json as { data?: ReferenceFileRow } | null)?.data;
    if (data === undefined || typeof data.id !== 'string') {
        throw new Error(FALLBACK);
    }
    return data;
}

/** The draft's files, in display order — read again while a new file's virus check is still running. */
export async function listReferenceFiles(formId: string): Promise<ReferenceFileRow[]> {
    const json = (await send(base(formId), { method: 'GET' }, FALLBACK)) as { data?: unknown } | null;
    return Array.isArray(json?.data) ? (json.data as ReferenceFileRow[]) : [];
}

/** Attach one file to the draft, under its own name. */
export async function uploadReferenceFile(formId: string, file: File): Promise<ReferenceFileRow> {
    const body = new FormData();
    body.append('file', file);
    return rowOf(await send(base(formId), { method: 'POST', body }, 'The file was not accepted. Please try another file.'));
}

/** Rename one of the draft's files — what respondents see in its place. */
export async function renameReferenceFile(formId: string, fileId: string, label: string): Promise<ReferenceFileRow> {
    return rowOf(await send(`${base(formId)}/${encodeURIComponent(fileId)}`, { method: 'PATCH', json: { label } }, 'The name was not saved.'));
}

/** Remove one of the draft's files. A published version that shows it keeps it. */
export async function removeReferenceFile(formId: string, fileId: string): Promise<void> {
    await send(`${base(formId)}/${encodeURIComponent(fileId)}`, { method: 'DELETE' }, 'The file was not removed.');
}

/** "PDF, 1.2 MB" — the kind a respondent will get, and its size, in the units a person reads. */
export function describeReferenceFile(mimeType: string, sizeBytes: number): string {
    const kind = mimeType === 'application/pdf' ? 'PDF' : mimeType.startsWith('image/') ? 'Image' : 'File';
    if (sizeBytes < 1024 * 1024) {
        return `${kind}, ${Math.max(1, Math.round(sizeBytes / 1024))} KB`;
    }
    return `${kind}, ${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`;
}
