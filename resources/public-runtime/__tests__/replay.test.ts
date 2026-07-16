import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { openDb, type MeridianDb } from '../lib/db';
import { enqueue, type EnqueueInput } from '../lib/outbox';
import { attachToSubmission, localMediaRefId, stash } from '../lib/media-queue';
import { replayOutbox } from '../lib/replay';

let n = 0;
let db: MeridianDb;

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

interface Handlers {
    submit?: () => Response; // may throw to simulate a network failure
    attachment?: () => Response;
}

function makeFetch(h: Handlers = {}) {
    const submitBodies: Array<Record<string, unknown>> = [];
    const fetchFn = vi.fn(async (url: string, init?: RequestInit) => {
        if (url.startsWith('/f/')) {
            return res(200, { shareToken: 'tok', expiresAt: '', form: { id: 'f', title: 'T' } });
        }
        if (url.includes('/attachments')) {
            return h.attachment ? h.attachment() : res(201, { data: { id: 'att-1' } });
        }
        if (url.includes('/submissions')) {
            if (typeof init?.body === 'string') {
                submitBodies.push(JSON.parse(init.body) as Record<string, unknown>);
            }
            return h.submit ? h.submit() : res(201, { data: { id: 'srv-1', status: 'submitted' } });
        }
        throw new Error(`unexpected url ${url}`);
    });
    return { fetchFn: fetchFn as unknown as typeof fetch, submitBodies };
}

function input(uuid: string, over: Partial<EnqueueInput> = {}): EnqueueInput {
    return {
        client_submission_uuid: uuid,
        slug: 's',
        form_version_id: 'v1',
        checksum: 'c1',
        answers: { a: '1' },
        locale: 'en',
        device_id: 'dev',
        app_version: 'test',
        ...over,
    };
}

const errorBody = (code: string) => ({ error: { code, message: `err:${code}`, details: { fields: [] } } });

beforeEach(() => {
    db = openDb(`replay-test-${(n += 1)}`);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

// happy-dom's Blob does not survive fake-indexeddb's structuredClone (a real browser stores Blobs natively),
// so a blob read back from Dexie isn't a valid FormData part in this env. Stub FormData for the media case so
// the test exercises the replay's upload→rewrite→submit ordering without depending on that env quirk.
class FakeFormData {
    entries: Array<[string, unknown]> = [];
    append(name: string, value: unknown): void {
        this.entries.push([name, value]);
    }
}

describe('replayOutbox', () => {
    it('marks a 201 as synced and removes the row', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch();
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 1 });
        expect(await db.outbox.get('u1')).toBeUndefined();
    });

    it('treats a 200 idempotent replay as synced too', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({ submit: () => res(200, { data: { id: 'srv-1', status: 'submitted' } }) });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 1 });
        expect(await db.outbox.get('u1')).toBeUndefined();
    });

    it('flags a 422 as needs_attention (never dropped)', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({ submit: () => res(422, errorBody('submission_invalid')) });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ needsAttention: 1 });
        expect((await db.outbox.get('u1'))?.status).toBe('needs_attention');
    });

    it('flags a 409 as conflict for the G8c UX', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({ submit: () => res(409, errorBody('form_updated')) });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ conflict: 1 });
        expect((await db.outbox.get('u1'))?.status).toBe('conflict');
    });

    it('keeps a network failure pending and escalates to needs_attention after 5 attempts', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({
            submit: () => {
                throw new TypeError('offline');
            },
        });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ retry: 1 });
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'pending', attempts: 1 });
        for (let i = 0; i < 4; i += 1) {
            await replayOutbox(db, fetchFn);
        }
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'needs_attention', attempts: 5 });
    });

    it('uploads queued media first and rewrites placeholder ids before POSTing the submission', async () => {
        vi.stubGlobal('FormData', FakeFormData);
        await enqueue(db, input('u1', { answers: { photo: [{ id: localMediaRefId('l1'), name: 'p.png' }] } }));
        await stash(db, {
            attachment_local_id: 'l1',
            field_key: 'photo',
            blob: new Blob(['x'], { type: 'image/png' }),
            name: 'p.png',
            mime: 'image/png',
            size: 1,
        });
        await attachToSubmission(db, ['l1'], 'u1');

        const { fetchFn, submitBodies } = makeFetch({ attachment: () => res(201, { data: { id: 'att-99' } }) });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 1 });

        const posted = submitBodies[0].answers as { photo: Array<{ id: string }> };
        expect(posted.photo[0].id).toBe('att-99'); // the local: placeholder was swapped for the real id
        expect(await db.outbox.get('u1')).toBeUndefined();
    });

    it('drains multiple rows in one pass', async () => {
        await enqueue(db, input('u1'));
        await enqueue(db, input('u2'));
        const { fetchFn } = makeFetch();
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 2 });
    });
});
