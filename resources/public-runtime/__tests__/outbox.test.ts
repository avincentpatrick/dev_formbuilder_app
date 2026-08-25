import { beforeEach, describe, expect, it } from 'vitest';
import { openDb, type MeridianDb } from '../lib/db';
import { attachToSubmission, stash } from '../lib/media-queue';
import {
    counts,
    countsFor,
    discardRow,
    earlierUnsent,
    enqueue,
    listConflicts,
    listPending,
    listSubmissions,
    markConflict,
    markNeedsAttention,
    markSynced,
    pruneSynced,
    recordAttempt,
    retryAll,
    retryRow,
    setAnswers,
    type EnqueueInput,
} from '../lib/outbox';

let n = 0;
let db: MeridianDb;

/** Increment M15 — two visits to one device, named so the assertions read as what they are. */
const MINE = 'visit-mine';
const THEIRS = 'visit-theirs';

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
        // Increment M15 — rows default to THIS visit, because almost every case below is about what the
        // current respondent can see or do. A case about a stranger passes `respondent_session_id: THEIRS`
        // (or null, a pre-M15 row) and says so.
        respondent_session_id: MINE,
        ...over,
    };
}

beforeEach(() => {
    db = openDb(`outbox-test-${(n += 1)}`);
});

describe('outbox', () => {
    it('enqueues a pending row', async () => {
        const row = await enqueue(db, input('u1'));
        expect(row.status).toBe('pending');
        expect(row.attempts).toBe(0);
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'pending', slug: 's' });
    });

    it('lists pending rows oldest-first', async () => {
        await db.outbox.put({ ...(await enqueue(db, input('old'))), created_at: '2026-01-01T00:00:00.000Z' });
        await db.outbox.put({ ...(await enqueue(db, input('new'))), created_at: '2026-12-01T00:00:00.000Z' });
        const pending = await listPending(db);
        expect(pending.map((r) => r.client_submission_uuid)).toEqual(['old', 'new']);
    });

    it('markSynced RETAINS the row as the respondent’s receipt, scrubbed of answers', async () => {
        await enqueue(db, input('u1'));
        await db.media_queue.put({
            attachment_local_id: 'm1',
            client_submission_uuid: 'u1',
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
        // ⚠️ THIS CASE USED TO ASSERT THE ROW WAS DELETED, and I10d reverses it deliberately:
        // docs/PRD.md:223 names `synced` as one of four states the respondent must SEE, and a deleted row
        // cannot be rendered. Do not "restore" this.
        await markSynced(db, 'u1', 'srv-1');

        const row = await db.outbox.get('u1');
        expect(row).toBeDefined();
        expect(row?.status).toBe('synced');
        expect(row?.server_submission_id).toBe('srv-1');
        expect(row?.synced_at).toBeTruthy();
        // The scrub — the whole reason retention is acceptable on shared/kiosk hardware.
        expect(row?.answers).toEqual({});
        // ...and the blobs still go, in the same transaction.
        expect(await db.media_queue.where('client_submission_uuid').equals('u1').count()).toBe(0);
    });

    it('discardRow removes a client-resolved row', async () => {
        await enqueue(db, input('u1'));
        await discardRow(db, 'u1');
        expect(await db.outbox.get('u1')).toBeUndefined();
    });

    it('marks conflict + needs_attention', async () => {
        await enqueue(db, input('u1'));
        await markConflict(db, 'u1', 'form changed');
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'conflict', last_error: 'form changed' });
        await markNeedsAttention(db, 'u1', 'rejected');
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'needs_attention', last_error: 'rejected' });
    });

    it('markConflict records which 409 code parked the row (Increment G8c)', async () => {
        await enqueue(db, input('u1'));
        await markConflict(db, 'u1', 'form changed', 'form_updated');
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'conflict', conflict_code: 'form_updated' });
    });

    it('listConflicts returns conflict rows oldest-first, filtered by slug (Increment G8c)', async () => {
        await db.outbox.put({ ...(await enqueue(db, input('a', { slug: 'x' }))), created_at: '2026-01-01T00:00:00.000Z' });
        await db.outbox.put({ ...(await enqueue(db, input('b', { slug: 'x' }))), created_at: '2026-12-01T00:00:00.000Z' });
        await enqueue(db, input('c', { slug: 'y' }));
        await markConflict(db, 'a', 'e', 'form_updated');
        await markConflict(db, 'b', 'e', 'submission_conflict');
        await markConflict(db, 'c', 'e', 'form_updated');

        expect((await listConflicts(db)).map((r) => r.client_submission_uuid).sort()).toEqual(['a', 'b', 'c']);
        expect((await listConflicts(db, 'x')).map((r) => r.client_submission_uuid)).toEqual(['a', 'b']);
        expect((await listConflicts(db, 'y')).map((r) => r.client_submission_uuid)).toEqual(['c']);
    });

    it('recordAttempt increments + returns the new count, keeping the row pending', async () => {
        await enqueue(db, input('u1'));
        expect(await recordAttempt(db, 'u1', 'net')).toBe(1);
        expect(await recordAttempt(db, 'u1', 'net')).toBe(2);
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'pending', attempts: 2 });
    });

    it('retryAll resets needs_attention rows back to pending', async () => {
        await enqueue(db, input('u1'));
        await markNeedsAttention(db, 'u1', 'x');
        await retryAll(db);
        expect(await db.outbox.get('u1')).toMatchObject({ status: 'pending', attempts: 0, last_error: null });
    });

    it('setAnswers rewrites the queued answers', async () => {
        await enqueue(db, input('u1'));
        await setAnswers(db, 'u1', { a: '2', b: '3' });
        expect((await db.outbox.get('u1'))?.answers).toEqual({ a: '2', b: '3' });
    });

    it('counts reports pending / needs_attention / conflict', async () => {
        await enqueue(db, input('p'));
        await enqueue(db, input('n'));
        await markNeedsAttention(db, 'n', 'x');
        await enqueue(db, input('c'));
        await markConflict(db, 'c', 'x');
        expect(await counts(db)).toEqual({ pending: 1, needsAttention: 1, conflict: 1 });
    });
});

/*
|--------------------------------------------------------------------------
| Increment I10d — per-row retry, the list, and the receipt pruner.
|--------------------------------------------------------------------------
*/

describe('outbox (I10d)', () => {
    it('retryRow returns ONLY the named row to pending, resetting its attempts', async () => {
        await enqueue(db, input('u1'));
        await enqueue(db, input('u2'));
        await markNeedsAttention(db, 'u1', 'rejected');
        await markNeedsAttention(db, 'u2', 'rejected');

        expect(await retryRow(db, 'u1')).toBe(true);

        // Reusing retryAll's `.anyOf('needs_attention')` query is the obvious wrong implementation, and it
        // would flip the sibling too.
        expect((await db.outbox.get('u1'))?.status).toBe('pending');
        expect((await db.outbox.get('u1'))?.attempts).toBe(0);
        expect((await db.outbox.get('u2'))?.status).toBe('needs_attention');
    });

    it('retryRow REFUSES a conflict row', async () => {
        // A parked 409 cannot succeed by being sent again: it re-409s, or the version guard re-parks it
        // before the POST. Offering retry there teaches the respondent the button does nothing.
        await enqueue(db, input('u1'));
        await markConflict(db, 'u1', 'form changed', 'form_updated');

        expect(await retryRow(db, 'u1')).toBe(false);
        expect((await db.outbox.get('u1'))?.status).toBe('conflict');
    });

    it('retryRow refuses a synced row and an unknown uuid', async () => {
        await enqueue(db, input('u1'));
        await markSynced(db, 'u1', 'srv-1');

        expect(await retryRow(db, 'u1')).toBe(false);
        expect(await retryRow(db, 'nope')).toBe(false);
    });

    it('listSubmissions returns every status newest-first and carries no answers', async () => {
        await enqueue(db, input('u1'));
        await enqueue(db, input('u2'));
        await markSynced(db, 'u2', 'srv-2');

        const rows = await listSubmissions(db);

        expect(rows).toHaveLength(2);
        expect(rows.map((r) => r.client_submission_uuid)).toEqual(['u2', 'u1']);
        // Nothing that renders a list should be handed answer data at all.
        for (const row of rows) {
            expect(row.answers).toEqual({});
        }
    });

    it('listSubmissions honours its limit', async () => {
        for (const uuid of ['u1', 'u2', 'u3']) {
            await enqueue(db, input(uuid));
        }

        expect(await listSubmissions(db, { limit: 2 })).toHaveLength(2);
    });

    it('pruneSynced keeps the most recent receipts and drops the rest', async () => {
        for (let i = 0; i < 25; i += 1) {
            await enqueue(db, input(`u${i}`));
            await markSynced(db, `u${i}`, `srv-${i}`);
        }

        await pruneSynced(db);

        expect(await db.outbox.count()).toBe(20);
    });

    it('pruneSynced drops a receipt older than 24h even when well under the count cap', async () => {
        // A count cap alone is unbounded in TIME: a tablet that syncs three responses then sits in a drawer
        // for a month still shows a month-old enumerator's reference to whoever picks it up next.
        await enqueue(db, input('old'));
        await markSynced(db, 'old', 'srv-old');
        await db.outbox.update('old', { synced_at: '2020-01-01T00:00:00.000Z' });

        await enqueue(db, input('fresh'));
        await markSynced(db, 'fresh', 'srv-fresh');

        await pruneSynced(db);

        expect(await db.outbox.get('old')).toBeUndefined();
        expect(await db.outbox.get('fresh')).toBeDefined();
    });

    it('pruneSynced NEVER touches a row that has not been sent, at any age or depth', async () => {
        // §7.3's "never silently dropped" is the whole contract. A reaper that ate an unsent submission
        // would be the worst possible bug in this file.
        //
        // ⚠️ THE FIRST VERSION OF THIS CASE WAS VACUOUS, AND ONLY THE MUTATION CHECK SAID SO. It seeded three
        // unsent rows with an old `created_at` and asserted none were pruned — but the age arm reads
        // `synced_at ?? updated_at` (recent on an unsent row) and the depth arm needs MORE than the keep cap
        // to fire, so neither could have triggered even with the status filter deleted. The mutation
        // "prune every status, not just synced" passed it green.
        //
        // It now puts BOTH arms genuinely in range: 25 unsent rows (past the 20 cap) and an old `updated_at`
        // on one of them. Deleting the `.equals('synced')` filter now destroys real data and says so.
        for (let i = 0; i < 25; i += 1) {
            await enqueue(db, input(`p${i}`));
        }
        await enqueue(db, input('failed'));
        await markNeedsAttention(db, 'failed', 'rejected');
        await enqueue(db, input('conflicted'));
        await markConflict(db, 'conflicted', 'form changed', 'form_updated');

        // Old by every clock the pruner could consult.
        for (const uuid of ['p0', 'failed', 'conflicted']) {
            await db.outbox.update(uuid, {
                created_at: '2020-01-01T00:00:00.000Z',
                updated_at: '2020-01-01T00:00:00.000Z',
            });
        }

        await pruneSynced(db);

        expect(await db.outbox.count()).toBe(27);
        expect(await db.outbox.get('p0')).toBeDefined();
        expect(await db.outbox.get('failed')).toBeDefined();
        expect(await db.outbox.get('conflicted')).toBeDefined();
    });
});

describe('outbox — a delivered row is terminal (I10d review fix)', () => {
    it('refuses to move a synced row back to conflict or needs_attention', async () => {
        // ⚠️ RETENTION REMOVED A GUARD THAT DELETION USED TO PROVIDE FOR FREE. When markSynced() deleted the
        // row, a late write from a second replay context (the service worker and a tab both draining) hit a
        // missing key and Dexie changed nothing. Now the row survives — and without an explicit guard it
        // would be moved back to a RETRYABLE state carrying `answers: {}`, so the respondent would be offered
        // "Retry now" on a submission the server already has, and the retry would POST an empty payload.
        // `inFlight`/`rowsInFlight` cannot help: both are module-level, so neither spans the tab↔SW boundary.
        await enqueue(db, input('u1'));
        await markSynced(db, 'u1', 'srv-1');

        await markConflict(db, 'u1', 'form changed', 'form_updated');
        await markNeedsAttention(db, 'u1', 'rejected');
        await recordAttempt(db, 'u1', 'network');
        await setAnswers(db, 'u1', { a: 'resurrected' });

        const row = await db.outbox.get('u1');
        expect(row?.status).toBe('synced');
        expect(row?.answers).toEqual({});
        expect(row?.attempts).toBe(0);
        expect(row?.conflict_code).toBeNull();
    });

    it('still allows those writes on a row that has NOT been delivered', async () => {
        // The guard must not make the ordinary lifecycle a no-op.
        await enqueue(db, input('u1'));

        await recordAttempt(db, 'u1', 'network');
        await markConflict(db, 'u1', 'form changed', 'form_updated');

        expect((await db.outbox.get('u1'))?.status).toBe('conflict');
        expect((await db.outbox.get('u1'))?.attempts).toBe(1);
    });
});

describe('respondent scope (Increment M15)', () => {
    it('listSubmissions shows THIS visit and hides the previous respondent entirely', async () => {
        await enqueue(db, input('mine'));
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));

        const rows = await listSubmissions(db, { sessionId: MINE });

        expect(rows.map((r) => r.client_submission_uuid)).toEqual(['mine']);
    });

    it('treats a PRE-M15 row (null session) as an earlier visit, never as a wildcard', async () => {
        // The safe reading of an unknown owner is "not yours". Getting this backwards would leave every row
        // written before this increment visible to everybody, which is the defect rather than a migration.
        await enqueue(db, input('legacy', { respondent_session_id: null }));

        expect(await listSubmissions(db, { sessionId: MINE })).toEqual([]);
        expect(await earlierUnsent(db, MINE)).toBe(1);
    });

    it('fills a full page past a stranger newer rows — the filter runs BEFORE the limit', async () => {
        // Post-filtering a limited page returns fewer than the limit, so a respondent's own older rows would
        // hide behind a stranger's newer ones. Three of theirs are newest; a limit of two must still find
        // two of mine rather than nothing.
        await enqueue(db, input('mine-1'));
        await enqueue(db, input('mine-2'));
        for (const uuid of ['theirs-1', 'theirs-2', 'theirs-3']) {
            await enqueue(db, input(uuid, { respondent_session_id: THEIRS }));
        }

        expect(await listSubmissions(db, { sessionId: MINE, limit: 2 })).toHaveLength(2);
    });

    it("REFUSES to discard another visit's row, and the refusal is at the write", async () => {
        // Not "the button is hidden": anything holding a uuid reaches `discardRow`, and this deletes the
        // row AND its queued media in one transaction. Hiding the list without this leaves the harm.
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));
        await stash(db, {
            attachment_local_id: 'blob-1',
            field_key: 'photo',
            blob: new Blob(['x']),
            name: 'x.png',
            mime: 'image/png',
            size: 1,
        });
        await attachToSubmission(db, ['blob-1'], 'theirs');

        expect(await discardRow(db, 'theirs', MINE)).toBe(false);
        expect(await db.outbox.get('theirs')).toBeDefined();
        expect(await db.media_queue.count()).toBe(1);
    });

    it("still discards THIS visit's own row", async () => {
        await enqueue(db, input('mine'));

        expect(await discardRow(db, 'mine', MINE)).toBe(true);
        expect(await db.outbox.get('mine')).toBeUndefined();
    });

    it('refuses to discard a row that does not exist, rather than reporting success', async () => {
        expect(await discardRow(db, 'nope', MINE)).toBe(false);
    });

    it("listConflicts scopes to the visit, so review cannot open a stranger's answers", async () => {
        // This is the sharpest of the leaks: `conflictRow` returns the row UNSCRUBBED and App.vue seeds a
        // fill session with `row.answers`, so opening one renders the previous respondent's answers field
        // by field.
        await enqueue(db, input('mine'));
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));
        await markConflict(db, 'mine', 'e');
        await markConflict(db, 'theirs', 'e');

        expect((await listConflicts(db, 's', MINE)).map((r) => r.client_submission_uuid)).toEqual(['mine']);
        // Unscoped stays device-wide: the pre-M15 call shape relies on it.
        expect(await listConflicts(db, 's')).toHaveLength(2);
    });

    it('earlierUnsent counts every unsent status of every OTHER visit, and no receipts', async () => {
        await enqueue(db, input('mine'));
        await enqueue(db, input('t-pending', { respondent_session_id: THEIRS }));
        await enqueue(db, input('t-failed', { respondent_session_id: THEIRS }));
        await enqueue(db, input('t-conflict', { respondent_session_id: THEIRS }));
        await enqueue(db, input('t-sent', { respondent_session_id: THEIRS }));
        await markNeedsAttention(db, 't-failed', 'e');
        await markConflict(db, 't-conflict', 'e');
        await markSynced(db, 't-sent', 'srv');

        // Three unsent. The delivered one is not waiting for anything, and M15 prunes it besides.
        expect(await earlierUnsent(db, MINE)).toBe(3);
    });

    it('countsFor answers the badges for THIS visit while counts() stays device-wide', async () => {
        await enqueue(db, input('mine'));
        await enqueue(db, input('theirs-1', { respondent_session_id: THEIRS }));
        await enqueue(db, input('theirs-2', { respondent_session_id: THEIRS }));

        expect(await countsFor(db, MINE)).toEqual({ pending: 1, needsAttention: 0, conflict: 0 });
        // The device-wide number is load-bearing for the boot drain and the storage-quota estimate.
        expect(await counts(db)).toEqual({ pending: 3, needsAttention: 0, conflict: 0 });
    });

    it('the DRAIN stays device-wide, so an earlier respondent is never stranded', async () => {
        // `replay.ts` and `sw.ts` call listPending with no session — a service worker has none. Scoping it
        // would leave a stranger's queued response undeliverable forever, which is the silent data loss the
        // whole offline architecture exists to prevent.
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));

        expect((await listPending(db)).map((r) => r.client_submission_uuid)).toEqual(['theirs']);

        await markNeedsAttention(db, 'theirs', 'e');
        await retryAll(db);

        expect((await db.outbox.get('theirs'))?.status).toBe('pending');
    });

    it("pruneSynced drops an earlier visit's RECEIPT, which can never be shown or counted again", async () => {
        await enqueue(db, input('theirs', { respondent_session_id: THEIRS }));
        await markSynced(db, 'theirs', 'srv');

        expect(await pruneSynced(db, Date.now(), MINE)).toBe(1);
        expect(await db.outbox.get('theirs')).toBeUndefined();
    });

    it("NEVER drops an earlier visit's UNSENT row, at any age — containment, not deletion", async () => {
        // The one line M15 did not move. A stranger's unsent response is hidden from this respondent and
        // still drains in the background; a reaper that ate it would be the worst bug in that file.
        await enqueue(db, input('t-pending', { respondent_session_id: THEIRS }));
        await enqueue(db, input('t-failed', { respondent_session_id: THEIRS }));
        await enqueue(db, input('t-conflict', { respondent_session_id: THEIRS }));
        await markNeedsAttention(db, 't-failed', 'e');
        await markConflict(db, 't-conflict', 'e');

        // A year later, and with a different visit doing the pruning.
        expect(await pruneSynced(db, Date.now() + 365 * 24 * 60 * 60 * 1000, MINE)).toBe(0);
        expect(await db.outbox.count()).toBe(3);
    });

    it("keeps this visit's newest receipt even when a stranger has twenty of their own", async () => {
        // The count cap is measured against MY receipts, not the raw list: otherwise twenty of someone
        // else's would push my own out of view before it was ever mine to lose.
        for (let i = 0; i < 20; i += 1) {
            await enqueue(db, input(`theirs-${i}`, { respondent_session_id: THEIRS }));
            await markSynced(db, `theirs-${i}`, 'srv');
        }
        await enqueue(db, input('mine'));
        await markSynced(db, 'mine', 'srv');

        await pruneSynced(db, Date.now(), MINE);

        expect(await db.outbox.get('mine')).toBeDefined();
        expect(await db.outbox.count()).toBe(1);
    });
});
