/**
 * Data operations over the Dexie `outbox` store (Increment G8b) — pure, framework-free, db-injected so they
 * unit-test against `fake-indexeddb`. The replay lifecycle (attempt/escalate/conflict) lives in replay.ts;
 * this module only reads and writes rows. A synced row is deleted together with its queued media.
 */

import { plainClone, type MeridianDb, type OutboxRow, type OutboxStatus } from './db';

export interface EnqueueInput {
    client_submission_uuid: string;
    slug: string;
    form_version_id: string;
    checksum: string;
    answers: OutboxRow['answers'];
    locale: string;
    device_id: string;
    app_version: string;
}

/** Append a finalized submission as a `pending` outbox row (the durable record of submit intent). */
export async function enqueue(db: MeridianDb, input: EnqueueInput): Promise<OutboxRow> {
    const now = new Date().toISOString();
    const row: OutboxRow = {
        ...input,
        answers: plainClone(input.answers),
        submitted_at: now,
        status: 'pending',
        attempts: 0,
        last_error: null,
        conflict_code: null,
        server_submission_id: null,
        created_at: now,
        updated_at: now,
    };
    await db.outbox.put(row);
    return row;
}

/** Pending rows, oldest-first (the time-sortable uuid + created_at give a stable replay order). */
export function listPending(db: MeridianDb): Promise<OutboxRow[]> {
    return db.outbox.where('status').equals('pending').sortBy('created_at');
}

/** A submission delivered (201 new or 200 idempotent replay): drop the row and any queued media for it. */
export function markSynced(db: MeridianDb, uuid: string): Promise<void> {
    return deleteRow(db, uuid);
}

/** An online submit resolved the intent without queuing (422/409/429 handled live): drop the row + its media. */
export function discardRow(db: MeridianDb, uuid: string): Promise<void> {
    return deleteRow(db, uuid);
}

async function deleteRow(db: MeridianDb, uuid: string): Promise<void> {
    await db.transaction('rw', db.outbox, db.media_queue, async () => {
        await db.media_queue.where('client_submission_uuid').equals(uuid).delete();
        await db.outbox.delete(uuid);
    });
}

/** Flag a genuine concurrent-edit conflict (409) for manual resolution (the G8c UX consumes this state). The
 *  `code` records which 409 parked it so the resolve notice can distinguish drift from a server-copy conflict. */
export function markConflict(db: MeridianDb, uuid: string, error: string, code: string | null = null): Promise<number> {
    return patch(db, uuid, { status: 'conflict', last_error: error, conflict_code: code });
}

/** Conflict rows awaiting the G8c review UX, oldest-first; scoped to one form's `slug` when given (the
 *  interactive resolver reuses App's single share-token client, which is bound to the current form). */
export async function listConflicts(db: MeridianDb, slug?: string): Promise<OutboxRow[]> {
    const rows = await db.outbox.where('status').equals('conflict').sortBy('created_at');
    return slug === undefined ? rows : rows.filter((row) => row.slug === slug);
}

/** Flag a row a human must look at: a rejected payload (422), or 5 exhausted retries. Never auto-retried. */
export function markNeedsAttention(db: MeridianDb, uuid: string, error: string): Promise<number> {
    return patch(db, uuid, { status: 'needs_attention', last_error: error });
}

/** Record a failed network attempt, keeping the row `pending`. Returns the new attempt count for escalation. */
export async function recordAttempt(db: MeridianDb, uuid: string, error: string): Promise<number> {
    const row = await db.outbox.get(uuid);
    const attempts = (row?.attempts ?? 0) + 1;
    await patch(db, uuid, { attempts, last_error: error });
    return attempts;
}

/** Rewrite a queued row's answers (used to swap `local:` media placeholders for real attachment ids at replay). */
export function setAnswers(db: MeridianDb, uuid: string, answers: OutboxRow['answers']): Promise<number> {
    return patch(db, uuid, { answers: plainClone(answers) });
}

/** Return a pending row to `pending` for a manual retry (from the "needs attention" banner). */
export async function retryAll(db: MeridianDb): Promise<void> {
    const now = new Date().toISOString();
    await db.outbox
        .where('status')
        .anyOf('needs_attention')
        .modify({ status: 'pending', attempts: 0, last_error: null, updated_at: now });
}

export interface OutboxCounts {
    pending: number;
    needsAttention: number;
    conflict: number;
}

/** Live counts driving the sync-status UI (queued badge, needs-attention banner). */
export async function counts(db: MeridianDb): Promise<OutboxCounts> {
    const [pending, needsAttention, conflict] = await Promise.all([
        db.outbox.where('status').equals('pending').count(),
        db.outbox.where('status').equals('needs_attention').count(),
        db.outbox.where('status').equals('conflict').count(),
    ]);
    return { pending, needsAttention, conflict };
}

async function patch(db: MeridianDb, uuid: string, changes: Partial<OutboxRow> & { status?: OutboxStatus }): Promise<number> {
    return db.outbox.update(uuid, { ...changes, updated_at: new Date().toISOString() });
}
