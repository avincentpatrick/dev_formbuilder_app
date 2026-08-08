/**
 * Data operations over the Dexie `outbox` store (Increment G8b) — pure, framework-free, db-injected so they
 * unit-test against `fake-indexeddb`. The replay lifecycle (attempt/escalate/conflict) lives in replay.ts;
 * this module only reads and writes rows.
 *
 * ── I10d: A DELIVERED ROW IS RETAINED, NOT DELETED ─────────────────────────────────────────────────────
 * `docs/PRD.md:223` names four states the respondent must see — queued / syncing / synced / failed — and
 * until I10d the third was unrenderable, because `markSynced()` deleted the row. It now keeps the row and
 * scrubs it instead: status `synced`, the server id and a `synced_at` stamp survive; `answers` is emptied
 * and the queued media blobs are deleted, IN THE SAME TRANSACTION.
 *
 * The scrub is not a tidy-up, it is the point. This runs on enumerator tablets and shared kiosk hardware,
 * so the moment the server has the data there is no reason for a copy of someone's answers to stay on the
 * device — and doing it transactionally means it does not depend on the pruner ever running. What is kept
 * is metadata plus a reference code: enough to answer "did mine send?", not enough to read back.
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
        synced_at: null,
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

/**
 * A submission delivered (201 new or 200 idempotent replay): KEEP the row as the respondent's receipt, and
 * scrub what the server no longer needs us to hold (Increment I10d).
 *
 * Both halves happen in one `rw` transaction, so there is no window in which the row reads as delivered
 * while the answers are still on disk.
 */
export async function markSynced(db: MeridianDb, uuid: string, serverSubmissionId: string | null = null): Promise<void> {
    const now = new Date().toISOString();

    await db.transaction('rw', db.outbox, db.media_queue, async () => {
        await db.media_queue.where('client_submission_uuid').equals(uuid).delete();
        await db.outbox.update(uuid, {
            status: 'synced',
            server_submission_id: serverSubmissionId,
            synced_at: now,
            // The scrub. `last_error` goes too: a row that eventually succeeded should not still be
            // carrying the message from the attempt that did not.
            answers: {},
            last_error: null,
            updated_at: now,
        });
    });
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
    return patchUnsent(db, uuid, { status: 'conflict', last_error: error, conflict_code: code });
}

/** Conflict rows awaiting the G8c review UX, oldest-first; scoped to one form's `slug` when given (the
 *  interactive resolver reuses App's single share-token client, which is bound to the current form). */
export async function listConflicts(db: MeridianDb, slug?: string): Promise<OutboxRow[]> {
    const rows = await db.outbox.where('status').equals('conflict').sortBy('created_at');
    return slug === undefined ? rows : rows.filter((row) => row.slug === slug);
}

/** Flag a row a human must look at: a rejected payload (422), or 5 exhausted retries. Never auto-retried. */
export function markNeedsAttention(db: MeridianDb, uuid: string, error: string): Promise<number> {
    return patchUnsent(db, uuid, { status: 'needs_attention', last_error: error });
}

/** Record a failed network attempt, keeping the row `pending`. Returns the new attempt count for escalation. */
export async function recordAttempt(db: MeridianDb, uuid: string, error: string): Promise<number> {
    const row = await db.outbox.get(uuid);
    const attempts = (row?.attempts ?? 0) + 1;
    await patchUnsent(db, uuid, { attempts, last_error: error });
    return attempts;
}

/** Rewrite a queued row's answers (used to swap `local:` media placeholders for real attachment ids at replay). */
export function setAnswers(db: MeridianDb, uuid: string, answers: OutboxRow['answers']): Promise<number> {
    return patchUnsent(db, uuid, { answers: plainClone(answers) });
}

/** Return a pending row to `pending` for a manual retry (from the "needs attention" banner). */
export async function retryAll(db: MeridianDb): Promise<void> {
    const now = new Date().toISOString();
    await db.outbox
        .where('status')
        .anyOf('needs_attention')
        .modify({ status: 'pending', attempts: 0, last_error: null, updated_at: now });
}

/**
 * Return ONE row to `pending` for a per-item "Retry now" (Increment I10d, UX spec §7.3's requirement that
 * the failed row itself carries the action). Resets `attempts`, which is what "bypass the backoff" means.
 *
 * ⚠️ REFUSES A `conflict` ROW, AND THAT REFUSAL IS LOAD-BEARING. A parked 409 cannot succeed by being sent
 * again: it either 409s once more, or the version guard in replay.ts re-parks it before the POST. Offering
 * Retry there would teach the respondent that the button does nothing. A conflict is resolved through the
 * review flow or not at all. Also refuses `synced`, which has nothing left to send.
 *
 * @return true if the row was returned to pending.
 */
export async function retryRow(db: MeridianDb, uuid: string): Promise<boolean> {
    const row = await db.outbox.get(uuid);

    if (row === undefined || (row.status !== 'needs_attention' && row.status !== 'pending')) {
        return false;
    }

    await patch(db, uuid, { status: 'pending', attempts: 0, last_error: null });

    return true;
}

/**
 * Every row on this device, newest first — the "My submissions on this device" list of UX spec §7.1.
 *
 * Cross-form by design: the respondent's question is "did what I sent go?", which does not stop at the form
 * they happen to have open. `limit` bounds what the UI renders, not what is kept.
 */
export async function listSubmissions(db: MeridianDb, limit = 50): Promise<OutboxRow[]> {
    const rows = await db.outbox.orderBy('created_at').reverse().limit(limit).toArray();

    // Belt and braces on the scrub: nothing that renders a list should be handed answer data at all, and a
    // future caller reaching for `row.answers` should find nothing rather than something stale.
    return rows.map((row) => ({ ...row, answers: {} }));
}

/** Keep at most this many delivered receipts... */
const SYNCED_KEEP = 20;

/** ...and none older than this. */
const SYNCED_MAX_AGE_MS = 24 * 60 * 60 * 1000;

/**
 * Drop delivered receipts that have outlived their usefulness (Increment I10d). A row is pruned if it fails
 * EITHER test — too old, or too far down the list.
 *
 * Both bounds, because each alone leaves a real hole. A count cap is unbounded in TIME: a tablet that syncs
 * three responses and then sits in a drawer for a month still shows a month-old enumerator's reference to
 * whoever picks it up next, and shared hardware is exactly the threat here. An age cap is unbounded in
 * COUNT: someone who syncs four hundred responses in a field day carries four hundred rows on the device
 * whose storage quota `useSyncOutbox` already warns about, and the list becomes a log rather than a status.
 *
 * Twenty-four hours because the reference's whole job is to survive the session and one "I sent it this
 * morning" conversation; past that the tenant's inbox is authoritative. Twenty because that covers a day's
 * work in one view.
 *
 * Only `synced` rows are eligible. A pending, failed or conflicted row is NEVER pruned, at any age — §7.3's
 * "never silently dropped" is the whole contract, and a reaper that quietly ate an unsent submission would
 * be the worst possible bug in this file.
 */
export async function pruneSynced(db: MeridianDb, now: number = Date.now()): Promise<number> {
    const synced = await db.outbox.where('status').equals('synced').sortBy('created_at');
    const cutoff = now - SYNCED_MAX_AGE_MS;

    const doomed = synced
        .filter((row, index) => {
            const stamp = Date.parse(row.synced_at ?? row.updated_at);
            const tooOld = Number.isFinite(stamp) && stamp < cutoff;
            // sortBy is oldest-first, so anything outside the newest SYNCED_KEEP is at the front.
            const tooDeep = index < synced.length - SYNCED_KEEP;

            return tooOld || tooDeep;
        })
        .map((row) => row.client_submission_uuid);

    if (doomed.length > 0) {
        await db.outbox.bulkDelete(doomed);
    }

    return doomed.length;
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

/**
 * ⚠️ A DELIVERED ROW IS TERMINAL, AND THIS GUARD REPLACES ONE THAT USED TO BE FREE.
 *
 * Before I10d, `markSynced()` DELETED the row, so any late write from a second replay context — the service
 * worker and a tab both draining, or two tabs — hit a missing key and Dexie's `update()` changed nothing.
 * Deletion was doing concurrency work nobody had written down. Retention removes it: the row now survives,
 * and `markConflict`/`markNeedsAttention`/`recordAttempt`/`setAnswers` would happily move a delivered,
 * SCRUBBED row back to `conflict` or `needs_attention` — where it is retryable, with `answers: {}`. The
 * respondent would then be offered "Retry now" on a submission the server already has, and a retry would
 * POST an empty payload.
 *
 * `inFlight`/`rowsInFlight` in replay.ts cannot help: both are module-level, so they are per-JS-context and
 * do not span the tab↔service-worker boundary. The guard has to live at the write.
 */
async function patchUnsent(
    db: MeridianDb,
    uuid: string,
    changes: Partial<OutboxRow> & { status?: OutboxStatus },
): Promise<number> {
    const row = await db.outbox.get(uuid);

    if (row === undefined || row.status === 'synced') {
        return 0;
    }

    return patch(db, uuid, changes);
}
