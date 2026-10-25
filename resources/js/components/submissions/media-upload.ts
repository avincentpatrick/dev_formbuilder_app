/**
 * One staged upload to a media question's endpoint (Increment G6), shared by {@link MediaInput.vue} and, since M187
 * (`R-af2d278b`), {@link SignaturePad.vue}: two controls, one request, so the pad cannot drift from the picker on the
 * CSRF header, the error shape or the guest's offline stash.
 *
 * The endpoint is the channel's own (form-scoped for manual entry, share-token-scoped for the guest SPA). Increment G8b
 * (guest SPA only): when the network is unavailable, the file is stashed locally instead of failing — the returned
 * `local:` placeholder ref replays when the outbox syncs.
 */
import type { MediaAttachmentRef, MediaUploadConfig } from './FieldInput.vue';

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

/** POST one file to the staging endpoint via XHR (for upload progress); resolve the returned AttachmentRef. */
export function uploadMediaFile(
    upload: MediaUploadConfig | null | undefined,
    fieldKey: string,
    file: File,
    onProgress: (pct: number) => void,
): Promise<MediaAttachmentRef> {
    return new Promise((resolve, reject) => {
        const url = upload?.url;
        const stashOffline = upload?.stashOffline;
        if (url === undefined) {
            reject(new Error('Uploads are not configured for this field.'));
            return;
        }

        // Offline pre-check: skip the doomed request and stash the blob locally.
        if (stashOffline !== undefined && typeof navigator !== 'undefined' && !navigator.onLine) {
            onProgress(100);
            stashOffline(file, fieldKey).then(resolve).catch(reject);
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.responseType = 'json';
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        // The tenant (web) endpoint is CSRF-guarded; the guest (api) endpoint is not (no XSRF cookie there).
        const xsrf = readCookie('XSRF-TOKEN');
        if (xsrf !== null) {
            xhr.setRequestHeader('X-XSRF-TOKEN', xsrf);
        }

        xhr.upload.onprogress = (event: ProgressEvent): void => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        };
        xhr.onload = (): void => {
            const body = xhr.response as { data?: MediaAttachmentRef; error?: { code?: string; message?: string } } | null;
            if (xhr.status >= 200 && xhr.status < 300 && body?.data !== undefined && typeof body.data.id === 'string') {
                resolve(body.data);
            } else {
                reject(new Error(body?.error?.message ?? 'The upload was rejected. Please try a different file.'));
            }
        };
        xhr.onerror = (): void => {
            // A dropped connection surfaces here (not xhr.onload). If offline staging is available, keep the
            // blob locally so the pick isn't lost (Increment G8b); otherwise surface the retryable error.
            if (stashOffline !== undefined) {
                onProgress(100);
                stashOffline(file, fieldKey).then(resolve).catch(reject);
            } else {
                reject(new Error('Upload failed. Check your connection and retry.'));
            }
        };

        const form = new FormData();
        form.append('file', file);
        form.append('field_key', fieldKey);
        xhr.send(form);
    });
}
