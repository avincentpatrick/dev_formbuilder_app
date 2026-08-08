import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { openDb, type MeridianDb } from '../lib/db';
import { enqueue, markSynced, type EnqueueInput } from '../lib/outbox';
import { attachToSubmission, localMediaRefId, stash } from '../lib/media-queue';
import { replayOne, replayOutbox, type RowOutcome } from '../lib/replay';
import { field, schemaResponse } from './fixtures';

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
    /**
     * The version id the freshly-minted token's schema reports. Defaults to the `form_version_id` `input()`
     * enqueues, so the ordinary cases replay unchanged; a drift test raises it (Increment H21b, Doc #27 §5.4).
     */
    schemaVersionId?: string;
}

function makeFetch(h: Handlers = {}) {
    const submitBodies: Array<Record<string, unknown>> = [];
    const schemaReads: string[] = [];
    const fetchFn = vi.fn(async (url: string, init?: RequestInit) => {
        // The mint is `/f/{slug}`; the schema read is `/api/v1/public/f/{token}` — hence startsWith, not includes.
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
        if (url.startsWith('/api/v1/public/f/')) {
            schemaReads.push(url);
            return res(200, {
                data: schemaResponse({ fields: [field({ key: 'a' })], versionId: h.schemaVersionId ?? 'v1' }),
            });
        }
        throw new Error(`unexpected url ${url}`);
    });
    return { fetchFn: fetchFn as unknown as typeof fetch, submitBodies, schemaReads };
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
    it('marks a 201 as synced, KEEPS the row, records the server id and scrubs the answers', async () => {
        // I10d reversed the old assertion here (the row used to be deleted). Reverting markSynced() to a
        // delete is what this reddens on.
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch();
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 1 });

        const row = await db.outbox.get('u1');
        expect(row?.status).toBe('synced');
        expect(row?.server_submission_id).toBeTruthy();
        expect(row?.answers).toEqual({});
    });

    it('treats a 200 idempotent replay as synced too', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({ submit: () => res(200, { data: { id: 'srv-1', status: 'submitted' } }) });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 1 });
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'synced', server_submission_id: 'srv-1' });
    });

    it('flags a 422 as needs_attention (never dropped)', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({ submit: () => res(422, errorBody('submission_invalid')) });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ needsAttention: 1 });
        expect((await db.outbox.get('u1'))?.status).toBe('needs_attention');
    });

    it('flags a 409 as conflict for the G8c UX and records the code', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({ submit: () => res(409, errorBody('form_updated')) });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ conflict: 1 });
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'conflict', conflict_code: 'form_updated' });
    });

    // ── Increment H21b, Doc #27 §5.4 — the version guard that was already sitting unused in the row ──────
    //
    // Replay re-mints a token before posting, and a fresh token pins the form's CURRENT published version, so
    // the server's own guard compares that column against itself and can never fire. A submission pruned
    // client-side against version N was validated and persisted against N+1's relevance graph, returned 201,
    // and the row was marked `synced`: silent data loss behind a green badge.

    it('parks a row captured against an older version as a conflict, and never POSTs it', async () => {
        await enqueue(db, input('u1')); // captured at v1
        const { fetchFn, submitBodies } = makeFetch({ schemaVersionId: 'v2' }); // the form has moved on

        expect(await replayOutbox(db, fetchFn)).toMatchObject({ conflict: 1, synced: 0 });
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'conflict', conflict_code: 'form_updated' });
        // The half that makes this non-vacuous against the 409 case above: the guard fires BEFORE the network
        // write, so the stale answers never reach N+1's validator at all.
        expect(submitBodies).toHaveLength(0);
    });

    it('checks the version BEFORE uploading media, so queued blobs never land under the new version', async () => {
        vi.stubGlobal('FormData', FakeFormData);
        await enqueue(db, input('u1', { answers: { photo: [{ id: localMediaRefId('m1') }] } }));
        await stash(db, { attachment_local_id: 'm1', field_key: 'photo', name: 'a.jpg', type: 'image/jpeg', blob: new Blob(['x']) });
        await attachToSubmission(db, ['m1'], 'u1');

        const { fetchFn } = makeFetch({ schemaVersionId: 'v2' });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ conflict: 1 });
        // `GuestAttachmentController` carries the identical current-version guard against the same fresh
        // token, so uploading first would have written the blobs under N+1 before the submission was parked.
        expect(fetchFn).not.toHaveBeenCalledWith(expect.stringContaining('/attachments'), expect.anything());
    });

    it('still syncs when the captured version is the current one', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn, submitBodies } = makeFetch({ schemaVersionId: 'v1' });
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 1, conflict: 0 });
        expect(submitBodies).toHaveLength(1);
    });

    it('reads the schema once per form per pass, not once per row', async () => {
        await enqueue(db, input('u1'));
        await enqueue(db, input('u2'));
        const { fetchFn, schemaReads } = makeFetch();

        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 2 });
        expect(schemaReads).toHaveLength(1);
    });

    it('retries rather than dropping the row when the schema read fails transiently', async () => {
        await enqueue(db, input('u1'));
        const fetchFn = vi.fn(async (url: string) => {
            if (url.startsWith('/f/')) {
                return res(200, { shareToken: 'tok', expiresAt: '', form: { id: 'f', title: 'T' } });
            }
            throw new Error('offline again');
        }) as unknown as typeof fetch;

        expect(await replayOutbox(db, fetchFn)).toMatchObject({ retry: 1 });
        expect((await db.outbox.get('u1'))?.status).toBe('pending');
    });

    it('records a content-conflict 409 code (Increment G8c)', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({ submit: () => res(409, errorBody('submission_conflict')) });
        await replayOutbox(db, fetchFn);
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'conflict', conflict_code: 'submission_conflict' });
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
        // Retention and the scrub coexist: the receipt survives, the blobs do not.
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'synced' });
        expect(await db.media_queue.where('client_submission_uuid').equals('u1').count()).toBe(0);
    });

    it('drains multiple rows in one pass', async () => {
        await enqueue(db, input('u1'));
        await enqueue(db, input('u2'));
        const { fetchFn } = makeFetch();
        expect(await replayOutbox(db, fetchFn)).toMatchObject({ synced: 2 });
    });
});

/*
|--------------------------------------------------------------------------
| Increment I10d — one-row replay, the progress hooks, and pruning on the no-tab path.
|--------------------------------------------------------------------------
*/

describe('replayOne (I10d)', () => {
    it('replays exactly the named row and leaves the others alone', async () => {
        await enqueue(db, input('u1'));
        await enqueue(db, input('u2'));
        const { fetchFn, submitBodies } = makeFetch();

        expect(await replayOne(db, 'u1', fetchFn)).toBe('synced');

        // Delegating to run() is the obvious wrong implementation and it would drain the sibling too.
        expect(submitBodies).toHaveLength(1);
        expect((await db.outbox.get('u1'))?.status).toBe('synced');
        expect((await db.outbox.get('u2'))?.status).toBe('pending');
    });

    it('returns null for a row that is not pending', async () => {
        await enqueue(db, input('u1'));
        await markSynced(db, 'u1', 'srv-1');
        const { fetchFn, submitBodies } = makeFetch();

        expect(await replayOne(db, 'u1', fetchFn)).toBeNull();
        expect(submitBodies).toHaveLength(0);
    });

    it('calls onRowStart BEFORE the POST and onRowSettled after it', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn, submitBodies } = makeFetch();
        const seen: string[] = [];

        await replayOne(db, 'u1', fetchFn, {
            onRowStart: () => seen.push(`start:${submitBodies.length}`),
            onRowSettled: (_uuid, outcome: RowOutcome) => seen.push(`settled:${outcome}:${submitBodies.length}`),
        });

        // The ordering IS the feature: a "Sending…" indicator that appears after the request has finished is
        // not an indicator. The counts pin it without needing a deferred fetch.
        expect(seen).toEqual(['start:0', 'settled:synced:1']);
    });

    it('clears the in-flight mark even when the row fails', async () => {
        await enqueue(db, input('u1'));
        const { fetchFn } = makeFetch({ submit: () => res(422, errorBody('submission_invalid')) });
        const settled: string[] = [];

        await replayOne(db, 'u1', fetchFn, { onRowSettled: (uuid) => settled.push(uuid) });

        // Without the `finally`, a failed row would be stuck showing "Sending…" forever.
        expect(settled).toEqual(['u1']);
    });

    it('prunes receipts on a plain drain, which is the ONLY path a tabless device takes', async () => {
        // sw.ts calls replayOutbox() directly on a Background-Sync event with no tab open, so pruning only
        // in the composable would let a device that never opens the app accumulate receipts forever.
        for (let i = 0; i < 25; i += 1) {
            await enqueue(db, input(`old${i}`));
            await markSynced(db, `old${i}`, `srv-${i}`);
        }
        await enqueue(db, input('fresh'));

        const { fetchFn } = makeFetch();
        await replayOutbox(db, fetchFn);

        // 20, not 21: the row just delivered is ITSELF a receipt now, and it is the newest, so it is one of
        // the twenty kept. The cap is on retained receipts in total, not on pre-existing ones.
        expect(await db.outbox.count()).toBe(20);
        expect((await db.outbox.get('fresh'))?.status).toBe('synced');
    });
});
