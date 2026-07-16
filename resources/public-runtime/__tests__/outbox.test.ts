import { beforeEach, describe, expect, it } from 'vitest';
import { openDb, type MeridianDb } from '../lib/db';
import {
    counts,
    discardRow,
    enqueue,
    listConflicts,
    listPending,
    markConflict,
    markNeedsAttention,
    markSynced,
    recordAttempt,
    retryAll,
    setAnswers,
    type EnqueueInput,
} from '../lib/outbox';

let n = 0;
let db: MeridianDb;

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

    it('markSynced deletes the row and its queued media', async () => {
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
        await markSynced(db, 'u1');
        expect(await db.outbox.get('u1')).toBeUndefined();
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
