/**
 * M187 — the one staged-upload request behind both media controls (`MediaInput.vue`, `SignaturePad.vue`), extracted
 * from `MediaInput` so the signature pad cannot drift from it. Nothing tested it before the move; these hold what it
 * did: the multipart body, the CSRF header only where its cookie exists, the error message from the server's envelope,
 * and the guest's offline stash (Increment G8b).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { uploadMediaFile } from './media-upload';

interface Sent {
    method: string;
    url: string;
    headers: Record<string, string>;
    body: FormData | null;
}

let sent: Sent[] = [];
let respond: (xhr: FakeXhr) => void = () => undefined;

class FakeXhr {
    status = 0;
    response: unknown = null;
    responseType = '';
    withCredentials = false;
    upload: { onprogress: ((event: ProgressEvent) => void) | null } = { onprogress: null };
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    private request: Sent = { method: '', url: '', headers: {}, body: null };

    open(method: string, url: string): void {
        this.request = { method, url, headers: {}, body: null };
    }

    setRequestHeader(name: string, value: string): void {
        this.request.headers[name] = value;
    }

    send(body: FormData): void {
        this.request.body = body;
        sent.push(this.request);
        respond(this);
    }
}

function png(): File {
    return new File(['png'], 'signature.png', { type: 'image/png' });
}

beforeEach(() => {
    sent = [];
    vi.stubGlobal('XMLHttpRequest', FakeXhr);
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('uploadMediaFile', () => {
    it('posts the file and its question key, and resolves the staged reference', async () => {
        respond = (xhr) => {
            xhr.status = 201;
            xhr.response = { data: { id: 'att-1', mime: 'image/png' } };
            xhr.onload?.();
        };

        const ref = await uploadMediaFile({ url: '/forms/f1/attachments' }, 'signed', png(), () => undefined);

        expect(ref).toEqual({ id: 'att-1', mime: 'image/png' });
        expect(sent).toHaveLength(1);
        expect(sent[0].method).toBe('POST');
        expect(sent[0].url).toBe('/forms/f1/attachments');
        expect(sent[0].body?.get('field_key')).toBe('signed');
        expect((sent[0].body?.get('file') as File).name).toBe('signature.png');
        expect(sent[0].headers['X-XSRF-TOKEN']).toBeUndefined();
    });

    it('sends the CSRF header when the tenant app set its cookie', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        respond = (xhr) => {
            xhr.status = 201;
            xhr.response = { data: { id: 'att-1' } };
            xhr.onload?.();
        };

        await uploadMediaFile({ url: '/forms/f1/attachments' }, 'signed', png(), () => undefined);

        expect(sent[0].headers['X-XSRF-TOKEN']).toBe('abc=');
    });

    it('rejects with the server\'s own message', async () => {
        respond = (xhr) => {
            xhr.status = 422;
            xhr.response = { error: { code: 'attachment_type', message: 'That file type is not accepted.' } };
            xhr.onload?.();
        };

        await expect(uploadMediaFile({ url: '/x' }, 'signed', png(), () => undefined)).rejects.toThrow('That file type is not accepted.');
    });

    it('refuses when the question has no upload endpoint (the builder Preview)', async () => {
        await expect(uploadMediaFile(null, 'signed', png(), () => undefined)).rejects.toThrow('Uploads are not configured for this field.');
        expect(sent).toEqual([]);
    });

    it('stashes the file for later when the connection drops, if the channel can (the guest runtime)', async () => {
        respond = (xhr) => xhr.onerror?.();
        const stashOffline = vi.fn(async () => ({ id: 'local:1' }));

        const ref = await uploadMediaFile({ url: '/x', stashOffline }, 'signed', png(), () => undefined);

        expect(ref).toEqual({ id: 'local:1' });
        expect(stashOffline).toHaveBeenCalledWith(expect.any(File), 'signed');
    });

    it('says to retry when the connection drops and nothing can stash it (manual entry)', async () => {
        respond = (xhr) => xhr.onerror?.();

        await expect(uploadMediaFile({ url: '/x' }, 'signed', png(), () => undefined)).rejects.toThrow('Upload failed. Check your connection and retry.');
    });
});
