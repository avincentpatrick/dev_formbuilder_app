import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { openDb, type MeridianDb } from '../lib/db';
import { enqueue, markConflict, markNeedsAttention, type EnqueueInput } from '../lib/outbox';
import { createSyncOutbox, type SyncOutbox } from '../composables/useSyncOutbox';
import { field, schemaResponse } from './fixtures';

let n = 0;
let db: MeridianDb;
const drivers: SyncOutbox[] = [];

/** Increment M15 — two visits to one shared device. */
const MINE = 'visit-mine';
const THEIRS = 'visit-theirs';

function res(status: number, body: unknown): Response {
    const r = { ok: status >= 200 && status < 300, status, headers: new Headers(), json: async () => body, clone: () => r };
    return r as unknown as Response;
}

function okFetch(): typeof fetch {
    const fetchFn = vi.fn(async (url: string) => {
        if (url.startsWith('/f/')) {
            return res(200, { shareToken: 'tok', expiresAt: '', form: { id: 'f', title: 'T' } });
        }
        // The schema read replay does before posting (Increment H21b) — `v1` matches what `input()` enqueues,
        // so these rows replay rather than parking as version conflicts.
        if (url.startsWith('/api/v1/public/f/') && !url.includes('/submissions')) {
            return res(200, { data: schemaResponse({ fields: [field({ key: 'a' })], versionId: 'v1' }) });
        }
        // Increment M15 — a `reference` so the live-region announcement can be asserted at all; before
        // this the stub omitted one and every settle announced the reference-free sentence.
        return res(201, { data: { id: 'srv-1', status: 'submitted', reference: 'REF-1' } });
    });
    return fetchFn as unknown as typeof fetch;
}

function fakeEnv({ online = true, usage = 0, quota = 0 }: { online?: boolean; usage?: number; quota?: number } = {}) {
    const listeners: Record<string, Array<() => void>> = {};
    const add = (t: string, h: () => void) => void (listeners[t] ??= []).push(h);
    const remove = (t: string, h: () => void) => void (listeners[t] = (listeners[t] ?? []).filter((x) => x !== h));
    const nav = { onLine: online, storage: { estimate: async () => ({ usage, quota }) } } as unknown as Navigator;
    const win = { addEventListener: add, removeEventListener: remove } as unknown as Window;
    const doc = { visibilityState: 'visible', addEventListener: add, removeEventListener: remove } as unknown as Document;
    return { nav, win, doc, fire: (t: string) => (listeners[t] ?? []).forEach((h) => h()) };
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
        // Increment M15 — this visit by default; a case about the previous respondent passes THEIRS.
        respondent_session_id: MINE,
        ...over,
    };
}

function makeDriver(
    env: ReturnType<typeof fakeEnv>,
    fetchFn: typeof fetch,
    slug?: string,
    sessionId?: string,
): SyncOutbox {
    const driver = createSyncOutbox(db, {
        slug,
        sessionId,
        fetch: fetchFn,
        navigator: env.nav,
        window: env.win,
        document: env.doc,
    });
    drivers.push(driver);
    return driver;
}

beforeEach(() => {
    db = openDb(`sync-test-${(n += 1)}`);
});

afterEach(() => {
    drivers.splice(0).forEach((d) => d.dispose());
});

describe('createSyncOutbox', () => {
    it('refresh() reflects the live pending count', async () => {
        await enqueue(db, input('u1'));
        const driver = makeDriver(fakeEnv({ online: false }), okFetch());
        await driver.refresh();
        expect(driver.pending.value).toBe(1);
    });

    it('syncNow() drains the queue', async () => {
        await enqueue(db, input('u1'));
        const driver = makeDriver(fakeEnv({ online: false }), okFetch());
        await driver.syncNow();
        // `pending` is still 0 — the row left the pending bucket — but it is RETAINED as a synced receipt.
        expect(driver.pending.value).toBe(0);
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'synced' });
    });

    it('replays automatically on the online event', async () => {
        await enqueue(db, input('u1'));
        const env = fakeEnv({ online: false });
        const driver = makeDriver(env, okFetch());
        await driver.refresh();
        expect(driver.pending.value).toBe(1);
        env.fire('online');
        await vi.waitFor(() => expect(driver.pending.value).toBe(0));
    });

    it('surfaces a storage-quota warning past 80% usage', async () => {
        const driver = makeDriver(fakeEnv({ online: false, usage: 90, quota: 100 }), okFetch());
        await driver.refresh();
        expect(driver.quotaWarning.value).toContain('90%');
    });

    it('does not warn on healthy storage', async () => {
        const driver = makeDriver(fakeEnv({ online: false, usage: 10, quota: 100 }), okFetch());
        await driver.refresh();
        expect(driver.quotaWarning.value).toBeNull();
    });

    it('nextConflict returns the oldest conflict scoped to the current form slug (Increment G8c)', async () => {
        await db.outbox.put({ ...(await enqueue(db, input('old', { slug: 's' }))), created_at: '2026-01-01T00:00:00.000Z' });
        await db.outbox.put({ ...(await enqueue(db, input('new', { slug: 's' }))), created_at: '2026-12-01T00:00:00.000Z' });
        await enqueue(db, input('other', { slug: 'elsewhere' }));
        await markConflict(db, 'old', 'e');
        await markConflict(db, 'new', 'e');
        await markConflict(db, 'other', 'e');

        const driver = makeDriver(fakeEnv({ online: false }), okFetch(), 's');
        const next = await driver.nextConflict();
        expect(next?.client_submission_uuid).toBe('old');
    });

    it('nextConflict is null when there are no conflicts for this form', async () => {
        await enqueue(db, input('other', { slug: 'elsewhere' }));
        await markConflict(db, 'other', 'e');
        const driver = makeDriver(fakeEnv({ online: false }), okFetch(), 's');
        expect(await driver.nextConflict()).toBeNull();
    });

    it('discardSubmission drops the row, its media, and refreshes the count (Increment G8c)', async () => {
        await enqueue(db, input('c', { slug: 's' }));
        await db.media_queue.put({
            attachment_local_id: 'm1',
            client_submission_uuid: 'c',
            field_key: 'photo',
            blob: new Blob(['x']),
            name: 'x',
            mime: 'text/plain',
            size: 1,
            status: 'queued',
            attachment_id: null,
            attempts: 0,
            created_at: '2026-07-16T00:00:00.000Z',
        });
        await markConflict(db, 'c', 'e');
        const driver = makeDriver(fakeEnv({ online: false }), okFetch(), 's');
        await driver.refresh();
        expect(driver.conflict.value).toBe(1);

        await driver.discardSubmission('c');
        expect(await db.outbox.get('c')).toBeUndefined();
        expect(await db.media_queue.where('client_submission_uuid').equals('c').count()).toBe(0);
        expect(driver.conflict.value).toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| Increment I10d — per-row syncing state, the row list, and the cross-form conflict count.
|--------------------------------------------------------------------------
*/

describe('createSyncOutbox (I10d)', () => {
    it('holds the row in syncingUuids while it is in flight and clears it after', async () => {
        await enqueue(db, input('u1'));
        const driver = makeDriver(fakeEnv({ online: false }), okFetch());

        const inFlight = driver.syncNow();
        // Not asserted mid-flight (the fake fetch resolves immediately); what matters is that it does not
        // LEAK, which a missing `finally` in sendRow would cause.
        await inFlight;

        expect(driver.syncingUuids.value.size).toBe(0);
    });

    it('exposes the live row list, including delivered receipts', async () => {
        await enqueue(db, input('u1'));
        const driver = makeDriver(fakeEnv({ online: false }), okFetch());
        await driver.syncNow();

        expect(driver.rows.value).toHaveLength(1);
        expect(driver.rows.value[0].status).toBe('synced');
    });

    it('counts conflicts on THIS form separately from every conflict on the device', async () => {
        // The live bug this closes: `conflict` is cross-form while the resolver is slug-scoped, so a foreign
        // conflict produced a Review button that silently did nothing.
        await enqueue(db, input('mine'));
        await markConflict(db, 'mine', 'form changed', 'form_updated');
        await enqueue(db, { ...input('theirs'), slug: 'other-form' });
        await markConflict(db, 'theirs', 'form changed', 'form_updated');

        // 's' is input()'s default slug, so `mine` is on this form and `theirs` is not.
        const driver = makeDriver(fakeEnv({ online: false }), okFetch(), 's');
        await driver.refresh();

        expect(driver.conflict.value).toBe(2);
        expect(driver.conflictHere.value).toBe(1);
    });

    it('retryOne flips one row and replays only it', async () => {
        await enqueue(db, input('u1'));
        await enqueue(db, input('u2'));
        await markNeedsAttention(db, 'u1', 'rejected');
        await markNeedsAttention(db, 'u2', 'rejected');

        const driver = makeDriver(fakeEnv({ online: false }), okFetch());
        await driver.retryOne('u1');

        expect((await db.outbox.get('u1'))?.status).toBe('synced');
        expect((await db.outbox.get('u2'))?.status).toBe('needs_attention');
    });

    it('names the queued COUNT and the megabytes in the quota warning, not just a percentage', async () => {
        // docs/offline-first-sync-design.md:93 asks for "you have N submissions queued and using X MB".
        // A bare percentage tells the respondent something is wrong without telling them what syncing buys.
        await enqueue(db, input('u1'));
        const driver = makeDriver(fakeEnv({ online: false, usage: 900 * 1024 * 1024, quota: 1024 * 1024 * 1024 }), okFetch());
        await driver.refresh();

        expect(driver.quotaWarning.value).toContain('1 response');
        expect(driver.quotaWarning.value).toContain('MB');
    });
});

describe('createSyncOutbox — respondent scope (Increment M15)', () => {
    it('shows the current visit its own rows and reduces the previous one to a bare count', async () => {
        await enqueue(db, input('mine'));
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));

        const driver = makeDriver(fakeEnv(), vi.fn() as unknown as typeof fetch, 's', MINE);
        await driver.refresh();

        expect(driver.rows.value.map((r) => r.client_submission_uuid)).toEqual(['mine']);
        expect(driver.mine.value).toEqual({ pending: 1, needsAttention: 0, conflict: 0 });
        expect(driver.earlierUnsent.value).toBe(1);
    });

    it('keeps `pending` DEVICE-WIDE, because the boot drain and the quota estimate are about the device', async () => {
        await enqueue(db, input('mine'));
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));

        const driver = makeDriver(fakeEnv(), vi.fn() as unknown as typeof fetch, 's', MINE);
        await driver.refresh();

        // Two numbers about two different questions, and both are needed: a session-scoped `pending` would
        // leave the previous respondent's queue undrained and their storage pressure unreported.
        expect(driver.pending.value).toBe(2);
        expect(driver.mine.value.pending).toBe(1);
    });

    it("refuses to open another visit's conflict, at both entry points into the review flow", async () => {
        // `nextConflict()` is the banner Review, `conflictRow(uuid)` is the per-row one. Both hand the row
        // to App.vue, which seeds a fill session with `row.answers` — so a miss here renders the previous
        // respondent's answers on screen, field by field.
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));
        await markConflict(db, 'theirs', 'boom');

        const driver = makeDriver(fakeEnv(), vi.fn() as unknown as typeof fetch, 's', MINE);
        await driver.refresh();

        expect(await driver.nextConflict()).toBeNull();
        expect(await driver.conflictRow('theirs')).toBeNull();
        expect(driver.conflictHere.value).toBe(0);
    });

    it("refuses to discard another visit's row and reports the refusal", async () => {
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));

        const driver = makeDriver(fakeEnv(), vi.fn() as unknown as typeof fetch, 's', MINE);

        expect(await driver.discardSubmission('theirs')).toBe(false);
        expect(await db.outbox.get('theirs')).toBeDefined();
    });

    it("never speaks another visit's server reference into the live region", async () => {
        // The region is `aria-live`, the drain is device-wide by design, and a row queued by the PREVIOUS
        // respondent settles while the current one is on screen — so a screen reader at a kiosk would read
        // out a stranger's reference unprompted. It still announces; it just names nobody.
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));

        const env = fakeEnv();
        const driver = makeDriver(env, okFetch(), 's', MINE);
        await driver.syncNow();

        expect(driver.lastAnnouncement.value).toBe('Response sent');
        expect(driver.lastAnnouncement.value).not.toContain('REF-1');
    });

    it("still speaks THIS visit's reference, which is the whole point of the region", async () => {
        await enqueue(db, input('mine'));

        const env = fakeEnv();
        const driver = makeDriver(env, okFetch(), 's', MINE);
        await driver.refresh();
        await driver.syncNow();

        expect(driver.lastAnnouncement.value).toBe('Response sent — reference REF-1');
    });

    it('drains the previous respondent queue rather than stranding it, then keeps no receipt', async () => {
        // Containment is not deletion and not abandonment: their response still SENDS, from whoever picks
        // the device up next, because the drain is device-wide.
        //
        // ⚠️ AND THE ROW IS THEN GONE, WHICH IS THE DESIGN AND NOT A LEAK IN THE TEST. The first version of
        // this case asserted `status === 'synced'` and failed with `undefined`: the row delivered and
        // `pruneSynced` dropped it in the same `refresh()`, because a delivered receipt belonging to an
        // earlier visit can never be rendered or counted again and holding a server reference for it on
        // shared hardware buys nothing. So the assertion is on the POST having happened, not on residue.
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));

        const fetchFn = okFetch();
        const driver = makeDriver(fakeEnv(), fetchFn, 's', MINE);
        await driver.syncNow();

        const posted = (fetchFn as unknown as ReturnType<typeof vi.fn>).mock.calls.filter((call) =>
            String(call[0]).includes('/submissions'),
        );

        expect(posted).toHaveLength(1);
        expect(await db.outbox.get('theirs')).toBeUndefined();
    });

    it('behaves exactly as it did before M15 when no session is supplied', async () => {
        // The pre-M15 call shape is still valid and still device-wide, which is what every existing case in
        // this file exercises.
        await enqueue(db, input('a'));
        await enqueue(db, input('b', { respondent_session_id: THEIRS }));

        const driver = makeDriver(fakeEnv(), vi.fn() as unknown as typeof fetch, 's');
        await driver.refresh();

        expect(driver.rows.value).toHaveLength(2);
        expect(driver.mine.value).toEqual({ pending: 2, needsAttention: 0, conflict: 0 });
        expect(driver.earlierUnsent.value).toBe(0);
    });
});
