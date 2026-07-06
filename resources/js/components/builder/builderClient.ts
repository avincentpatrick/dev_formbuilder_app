// The builder's write seam (Increment D4a). There is no write-`fetch` precedent in the codebase — every
// other mutation is an Inertia visit that full-reloads the page from server props. The builder can't do
// that: it holds its own undo/redo state, so each edit is a fine-grained JSON call that must NOT reset the
// page. This wraps `fetch` with the CSRF + credentials contract Laravel's `web` group expects:
//   - `X-XSRF-TOKEN` read from the (encrypted) XSRF-TOKEN cookie Laravel sets, matching axios/Inertia;
//   - `credentials: 'same-origin'` (the builder page and the API share the tenant subdomain);
//   - `Accept: application/json` so validation failures come back as 422 JSON, not an HTML redirect.
// A 409 (optimistic-concurrency drift) is surfaced as a typed result, not thrown — the caller reconciles.

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

export interface ConflictResult<T> {
    conflict: true;
    current: T;
    message: string;
}

export interface OkResult<T> {
    conflict: false;
    data: T;
}

export type BuilderResult<T> = OkResult<T> | ConflictResult<T>;

export class BuilderRequestError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'BuilderRequestError';
    }
}

type Method = 'POST' | 'PATCH' | 'DELETE';

async function request<T>(method: Method, url: string, body?: unknown): Promise<BuilderResult<T>> {
    const token = readCookie('XSRF-TOKEN');

    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    if (response.status === 409) {
        const payload = (await response.json()) as { current: T; message: string };
        return { conflict: true, current: payload.current, message: payload.message };
    }

    if (!response.ok) {
        let message = `Request failed (${response.status}).`;
        let errors: Record<string, string[]> = {};
        try {
            const payload = (await response.json()) as { message?: string; errors?: Record<string, string[]> };
            message = payload.message ?? message;
            errors = payload.errors ?? {};
        } catch {
            // non-JSON error body (e.g. a 419 CSRF page) — keep the generic message
        }
        throw new BuilderRequestError(response.status, message, errors);
    }

    const data = (response.status === 204 ? undefined : await response.json()) as T;
    return { conflict: false, data };
}

export const builderClient = {
    post: <T>(url: string, body?: unknown): Promise<BuilderResult<T>> => request<T>('POST', url, body),
    patch: <T>(url: string, body?: unknown): Promise<BuilderResult<T>> => request<T>('PATCH', url, body),
    delete: <T>(url: string, body?: unknown): Promise<BuilderResult<T>> => request<T>('DELETE', url, body),
};
