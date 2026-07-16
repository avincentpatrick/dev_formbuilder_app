import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '../lib/api-client';
import { ApiError } from '../lib/error-normalizer';

function res(status: number, body: unknown, headers: Record<string, string> = {}): Response {
    const response = {
        ok: status >= 200 && status < 300,
        status,
        headers: new Headers(headers),
        json: async () => body,
        clone(): Response {
            return response as unknown as Response;
        },
    };
    return response as unknown as Response;
}

describe('createApiClient', () => {
    it('fetchSchema unwraps the data envelope and uses the current token', async () => {
        const fetchImpl = vi.fn(async () => res(200, { data: { form: { id: 'f' }, version: { id: 'v' } } }));
        const client = createApiClient({ token: 't1', slug: 'my-form', fetch: fetchImpl });

        const schema = await client.fetchSchema();
        expect(fetchImpl).toHaveBeenCalledWith('/api/v1/public/f/t1', { headers: { Accept: 'application/json' } });
        expect(schema).toEqual({ form: { id: 'f' }, version: { id: 'v' } });
    });

    it('throws an ApiError on a non-2xx schema fetch', async () => {
        const client = createApiClient({ token: 't1', slug: 's', fetch: async () => res(404, { message: 'Not Found' }) });
        await expect(client.fetchSchema()).rejects.toBeInstanceOf(ApiError);
    });

    it('submit reports created=true on 201 and created=false on a 200 replay', async () => {
        const created = createApiClient({ token: 't', slug: 's', fetch: async () => res(201, { data: { id: 'a', status: 'submitted' } }) });
        expect(await created.submit({ answers: {}, clientSubmissionUuid: 'u', locale: 'en' })).toEqual({
            id: 'a',
            status: 'submitted',
            created: true,
        });

        const replay = createApiClient({ token: 't', slug: 's', fetch: async () => res(200, { data: { id: 'a', status: 'submitted' } }) });
        expect((await replay.submit({ answers: {}, clientSubmissionUuid: 'u', locale: 'en' })).created).toBe(false);
    });

    it('includes device_id/app_version in the submit body only when provided (Increment G8b)', async () => {
        const fetchImpl = vi.fn(async () => res(201, { data: { id: 'a', status: 'submitted' } }));
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl });

        await client.submit({ answers: {}, clientSubmissionUuid: 'u', locale: 'en', deviceId: 'dev-1', appVersion: 'g8b' });
        const withDevice = JSON.parse((fetchImpl.mock.calls[0][1] as RequestInit).body as string);
        expect(withDevice).toMatchObject({ device_id: 'dev-1', app_version: 'g8b' });

        await client.submit({ answers: {}, clientSubmissionUuid: 'u', locale: 'en' });
        const withoutDevice = JSON.parse((fetchImpl.mock.calls[1][1] as RequestInit).body as string);
        expect(withoutDevice).not.toHaveProperty('device_id');
        expect(withoutDevice).not.toHaveProperty('app_version');
    });

    it('transparently re-mints and retries once on an expired token', async () => {
        let submitCalls = 0;
        const fetchImpl = vi.fn(async (url: string) => {
            if (url.startsWith('/f/')) {
                return res(200, { shareToken: 't2', expiresAt: '2026-07-11T00:00:00Z', form: { id: 'f', title: 'T' } });
            }
            submitCalls += 1;
            if (submitCalls === 1) {
                return res(401, { error: { code: 'share_token_expired', message: 'expired' } });
            }
            return res(201, { data: { id: 'a', status: 'submitted' } });
        });
        const client = createApiClient({ token: 't1', slug: 'my-form', fetch: fetchImpl });

        const result = await client.submit({ answers: {}, clientSubmissionUuid: 'u', locale: 'en' });
        expect(result.created).toBe(true);
        expect(client.token()).toBe('t2');
        expect(fetchImpl).toHaveBeenCalledWith('/api/v1/public/f/t2/submissions', expect.anything());
    });

    it('does NOT retry a hard 401 (invalid token) — it surfaces as terminal', async () => {
        const fetchImpl = vi.fn(async () => res(401, { error: { code: 'invalid_share_token', message: 'bad' } }));
        const client = createApiClient({ token: 't1', slug: 's', fetch: fetchImpl });
        await expect(client.fetchSchema()).rejects.toMatchObject({ normalized: { kind: 'terminal' } });
        expect(fetchImpl).toHaveBeenCalledTimes(1);
    });

    it('remint updates the current token', async () => {
        const client = createApiClient({
            token: 't1',
            slug: 's',
            fetch: async () => res(200, { shareToken: 'tX', expiresAt: '', form: { id: 'f', title: 'T' } }),
        });
        await client.remint();
        expect(client.token()).toBe('tX');
    });
});
