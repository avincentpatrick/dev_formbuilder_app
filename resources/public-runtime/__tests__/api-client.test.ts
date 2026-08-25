import { describe, expect, it, vi } from 'vitest';
import { createApiClient, resumeDraft } from '../lib/api-client';
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

    // ── H10 save-and-resume ────────────────────────────────────────────────────────────────────

    it('saveDraft posts to the draft route and maps the resume handle', async () => {
        const fetchImpl = vi.fn(async () =>
            res(201, {
                data: { id: 'sub-1', completeness_percent: 60, resume_token: 'rt', resume_url: 'https://acme/f/resume/rt', expires_at: '2026-08-01T00:00:00Z' },
            }),
        );
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl });

        const result = await client.saveDraft({ answers: { a: 1 }, clientSubmissionUuid: 'u', locale: 'en' });
        expect(fetchImpl.mock.calls[0][0]).toBe('/api/v1/public/f/t/draft');
        expect(result).toEqual({
            id: 'sub-1',
            completenessPercent: 60,
            resumeToken: 'rt',
            resumeUrl: 'https://acme/f/resume/rt',
            expiresAt: '2026-08-01T00:00:00Z',
        });
    });

    it('saveDraft includes finish_later + draft_current_step only when set', async () => {
        const fetchImpl = vi.fn(async () =>
            res(200, { data: { id: 's', completeness_percent: 10, resume_token: 'r', resume_url: 'u', expires_at: 'e' } }),
        );
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl });

        await client.saveDraft({ answers: {}, clientSubmissionUuid: 'u', locale: 'en', draftCurrentStep: 'sec-2', guestContactEmail: 'a@b.c', finishLater: true });
        const withExtras = JSON.parse((fetchImpl.mock.calls[0][1] as RequestInit).body as string);
        expect(withExtras).toMatchObject({ draft_current_step: 'sec-2', guest_contact_email: 'a@b.c', finish_later: true });

        await client.saveDraft({ answers: {}, clientSubmissionUuid: 'u', locale: 'en' });
        const bare = JSON.parse((fetchImpl.mock.calls[1][1] as RequestInit).body as string);
        expect(bare).not.toHaveProperty('draft_current_step');
        expect(bare).not.toHaveProperty('finish_later');
    });

    it('saveDraft surfaces a 409 form_updated as an ApiError kind refresh', async () => {
        const client = createApiClient({
            token: 't',
            slug: 's',
            fetch: async () => res(409, { error: { code: 'form_updated', message: 'updated' } }),
        });
        await expect(client.saveDraft({ answers: {}, clientSubmissionUuid: 'u', locale: 'en' })).rejects.toMatchObject({
            normalized: { kind: 'refresh' },
        });
    });

    it('resumeDraft GETs the resume route and maps snake_case → camelCase', async () => {
        const fetchImpl = vi.fn(async () =>
            res(200, {
                data: {
                    id: 'sub-1',
                    completeness_percent: 64,
                    client_submission_uuid: 'uuid-1',
                    form_version_id: 'ver-1',
                    answers: { name: 'Ada' },
                    last_saved_at: '2026-07-23T10:00:00Z',
                    draft_current_step: 'sec-3',
                    locale: 'es',
                    share_token: 'fresh-share',
                    share_token_expires_at: '2026-07-24T10:00:00Z',
                },
            }),
        );

        const result = await resumeDraft('rt', { fetch: fetchImpl });
        expect(fetchImpl.mock.calls[0][0]).toBe('/api/v1/public/drafts/rt');
        expect(result).toEqual({
            id: 'sub-1',
            completenessPercent: 64,
            clientSubmissionUuid: 'uuid-1',
            formVersionId: 'ver-1',
            answers: { name: 'Ada' },
            lastSavedAt: '2026-07-23T10:00:00Z',
            draftCurrentStep: 'sec-3',
            locale: 'es',
            shareToken: 'fresh-share',
            shareTokenExpiresAt: '2026-07-24T10:00:00Z',
        });
    });

    it('resumeDraft throws a terminal ApiError when the draft is gone (404)', async () => {
        await expect(
            resumeDraft('rt', { fetch: async () => res(404, { error: { code: 'draft_not_found', message: 'gone' } }) }),
        ).rejects.toMatchObject({ normalized: { kind: 'terminal' } });
    });
});

// ── Increment P3a — the lost-update baseline on the wire ──────────────────────────────────────────
// The resume link hands a SECOND device the same client_submission_uuid, so a draft has two writers. The
// baseline is what lets the server tell "this device has seen everything" from "this device is about to
// overwrite answers it never saw".
describe('P3a draft lost-update baseline', () => {
    const saveBody = (checksum: string | null) => ({
        data: {
            id: 's',
            completeness_percent: 10,
            resume_token: 'r',
            resume_url: 'u',
            expires_at: 'e',
            content_checksum: checksum,
        },
    });

    it('sends base_content_checksum and maps the server checksum back out', async () => {
        const fetchImpl = vi.fn(async () => res(200, saveBody('bbb')));
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl });

        const result = await client.saveDraft({
            answers: {},
            clientSubmissionUuid: 'u',
            locale: 'en',
            baseContentChecksum: 'aaa',
        });

        expect(JSON.parse((fetchImpl.mock.calls[0][1] as RequestInit).body as string).base_content_checksum).toBe('aaa');
        // The NEXT save's base. Dropping this on the floor would make every save after the first look like a
        // second device to the server.
        expect(result.contentChecksum).toBe('bbb');
    });

    it('sends base_content_checksum as an explicit null on a first save, never omits the key', async () => {
        const fetchImpl = vi.fn(async () => res(201, saveBody('bbb')));
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl });

        await client.saveDraft({ answers: {}, clientSubmissionUuid: 'u', locale: 'en' });

        const body = JSON.parse((fetchImpl.mock.calls[0][1] as RequestInit).body as string);
        // Unlike draft_current_step/finish_later above, this key is NOT conditionally spread: an absent key
        // is indistinguishable from a client that forgot, and null is the honest claim a first save makes.
        expect(body).toHaveProperty('base_content_checksum');
        expect(body.base_content_checksum).toBeNull();
    });

    it('resumeDraft carries the opening baseline through to the caller', async () => {
        const fetchImpl = async () =>
            res(200, {
                data: {
                    id: 'd',
                    completeness_percent: 40,
                    client_submission_uuid: 'uuid-1',
                    form_version_id: 'fv',
                    answers: { a: 1 },
                    last_saved_at: null,
                    draft_current_step: null,
                    locale: null,
                    share_token: 'st',
                    share_token_expires_at: 'x',
                    content_checksum: 'server-sum',
                },
            });

        const result = await resumeDraft('rt', { fetch: fetchImpl });
        expect(result.contentChecksum).toBe('server-sum');
    });

    it('maps a 409 draft_conflict as a distinct code AND kind the caller can branch on', async () => {
        const client = createApiClient({
            token: 't',
            slug: 's',
            fetch: async () => res(409, { error: { code: 'draft_conflict', message: 'Reload it.' } }),
        });

        await expect(client.saveDraft({ answers: {}, clientSubmissionUuid: 'u', locale: 'en', baseContentChecksum: 'stale' }))
            // ⚠️ Increment M14 — `kind` IS THE HALF THAT WAS MISSING, AND ITS ABSENCE HERE WAS DELIBERATE.
            // This case was written asserting the code alone, under a title promising the caller could branch
            // on it — while the classifier folded every 409 to `refresh`, so no caller could. The assertion
            // documented the defect instead of failing on it. It fails on it now.
            .rejects.toMatchObject({ normalized: { code: 'draft_conflict', kind: 'draft_stale' } });
    });
});
