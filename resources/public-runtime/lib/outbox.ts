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
 *
 * ── M15: THE THREAT ABOVE HAD A SECOND HALF, AND ONLY THE FIRST WAS EVER ANSWERED ──────────────────────
 * I10d applied that kiosk reasoning to data AT REST and, in the same increment, made the surviving metadata
 * far more visible: it promoted the sync surface above the phase machine, gave every row a Discard button
 * and started RETAINING delivered receipts (before I10d `markSynced()` deleted the row, so a server
 * reference never persisted on the device at all). The question nobody had asked is who is LOOKING at the
 * screen. Rows now carry `respondent_session_id`, and the reads a respondent SEES or ACTS ON are scoped to
 * their own visit.
 *
 * ⛔ THE SCOPE IS ON THE READS THAT DISCLOSE OR DESTROY, NEVER ON THE DRAIN. `listPending()` and
 * `retryAll()` stay device-wide on purpose: `replay.ts` and `sw.ts` call them with no session and a service
 * worker HAS no `sessionStorage`, so scoping them would strand every earlier row forever and break the
 * "never silently dropped" contract this file states below. Sending a stranger's submission discloses
 * nothing and destroys nothing — it is what they wanted. Showing it, and deleting it, are the harms.
 *
 * ⛔ AND THE SESSION ID IS AN ARGUMENT, NEVER AN IMPORT. `lib/respondent-session.ts` reads
 * `sessionStorage`; this module is in `sw.ts`'s import graph, which `tsconfig.sw.json` type-checks under
 * `lib: ["ESNext", "WebWorker"]` where `Storage` does not exist. Importing it here fails that program.
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
    /** Increment M15 — the visit that finalized this row. See `OutboxRow` in db.ts for why null is meaningful. */
    respondent_session_id: string | null;
    /**
     * Increment P3a — the server-draft baseline this submission was finalized against, or null when the
     * session never created a server draft (the ordinary fill). Stored so a replay hours later makes the
     * SAME claim the live submit would have. An un-indexed field on an existing row shape, so it needs no
     * Dexie version bump — see `docs/offline-first-sync-design.md` §3.
     */
    base_content_checksum: string | null;
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
        server_reference: null,
        synced_at: null,
        created_at: now,
        updated_at: now,
    };
    await db.outbox.put(row);
    return row;
}

/**
 * Pending rows, oldest-first (the time-sortable uuid + created_at give a stable replay order).
 *
 * ⛔ DEVICE-WIDE, AND M15 DELIBERATELY LEFT IT THAT WAY. This is the drain, not a disclosure: `replay.ts`
 * and `sw.ts` call it with no session — a service worker has none — and a respondent-scoped drain would
 * strand every earlier row on the device forever, which is exactly the silent data loss `pruneSynced`'s
 * contract below exists to prevent.
 */
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
export async function markSynced(
    db: MeridianDb,
    uuid: string,
    serverSubmissionId: string | null = null,
    serverReference: string | null = null,
): Promise<void> {
    const now = new Date().toISOString();

    await db.transaction('rw', db.outbox, db.media_queue, async () => {
        await db.media_queue.where('client_submission_uuid').equals(uuid).delete();
        await db.outbox.update(uuid, {
            status: 'synced',
            server_submission_id: serverSubmissionId,
            // Increment J2e — written in the SAME transaction as the status, so there is no window in which
            // a row reads as delivered while still advertising its provisional queue tag.
            server_reference: serverReference,
            synced_at: now,
            // The scrub. `last_error` goes too: a row that eventually succeeded should not still be
            // carrying the message from the attempt that did not.
            answers: {},
            last_error: null,
            updated_at: now,
        });
    });
}

/**
 * An online submit resolved the intent without queuing (422/409/429 handled live), or the respondent chose
 * to drop a parked row: delete the row AND its queued media.
 *
 * ⚠️ INCREMENT M15 — `sessionId` MAKES THIS REFUSE ANOTHER VISIT'S ROW, AND THE GUARD IS HERE RATHER THAN
 * IN THE COMPONENT ON PURPOSE. `patchUnsent` below already argues the general form of this ("The guard has
 * to live at the write") for a different reason — two JS contexts share this database and module state
 * cannot span them. The same holds with a second respondent instead of a second context: hiding the
 * Discard button is a property of one render, while this is a property of the data. Filtering the LIST
 * without also guarding the DELETE would leave `discardSubmission(uuid)` reachable from anything holding
 * a uuid, which is precisely the shape M15 exists to close.
 *
 * `sessionId` undefined keeps the pre-M15 unscoped behaviour, which the resolve/submit paths in
 * `RuntimeSession` still want: those delete a row THIS session just created, by a uuid they minted.
 *
 * @return true when a row was deleted.
 */
export async function discardRow(db: MeridianDb, uuid: string, sessionId?: string): Promise<boolean> {
    if (sessionId !== undefined) {
        const row = await db.outbox.get(uuid);

        // Null never matches: a pre-M15 row has an unknown owner, and the safe reading of unknown is
        // "not yours" (db.ts). Refusing to delete it is the direction that cannot lose someone's data.
        if (row === undefined || row.respondent_session_id !== sessionId) {
            return false;
        }
    }

    await deleteRow(db, uuid);

    return true;
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

/**
 * Conflict rows awaiting the G8c review UX, oldest-first; scoped to one form's `slug` when given (the
 * interactive resolver reuses App's single share-token client, which is bound to the current form).
 *
 * ⚠️ INCREMENT M15 ADDED THE SECOND FILTER, AND IT CLOSES THE WORST OF THE THREE LEAKS. Review does not
 * merely NAME another respondent's row — `useSyncOutbox.conflictRow()` returns it unscrubbed and `App.vue`
 * seeds a fill session with `row.answers`, so the previous respondent's answers are rendered field by
 * field on shared hardware. Both entry points reach the review flow through this function, so scoping it
 * here shuts both rather than shutting the button and leaving the door.
 *
 * `sessionId` undefined means "do not scope" and exists for the pre-M15 call shape and for tests; a
 * CALLER that has a session must pass it. Null on the row never matches, per db.ts.
 */
export async function listConflicts(db: MeridianDb, slug?: string, sessionId?: string): Promise<OutboxRow[]> {
    const rows = await db.outbox.where('status').equals('conflict').sortBy('created_at');

    return rows.filter(
        (row) =>
            (slug === undefined || row.slug === slug) &&
            (sessionId === undefined || row.respondent_session_id === sessionId),
    );
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

/**
 * Return every failed row to `pending` for a manual retry (from the "needs attention" banner).
 *
 * ⛔ DEVICE-WIDE, like `listPending` and for the same reason, stated here because the asymmetry with
 * `discardRow` below is a decision rather than an oversight: retrying SENDS a stranger's submission, which
 * is the outcome they wanted and discloses nothing; discarding DESTROYS it. M15 scopes the second and not
 * the first, so an earlier respondent's queue still drains from whoever picks the device up next.
 */
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
 * This visit's rows on this device, newest first — the "My submissions on this device" list of UX §7.1.
 *
 * Cross-FORM by design, and that has not changed: the respondent's question is "did what I sent go?", which
 * does not stop at the form they happen to have open. `limit` bounds what the UI renders, not what is kept.
 *
 * ⚠️ INCREMENT M15 MADE IT SINGLE-RESPONDENT, WHICH READS AS A NARROWING OF §7.1 AND IS THE OPPOSITE.
 * `docs/ux/form-filling-ux-flow.md:180` scopes the list to "every submission THE RESPONDENT has finalized"
 * — definite article, singular — and §7.3 specifies the persistent app-level surface as a COUNT plus a call
 * to action ("1 submission couldn't be sent. Tap to review."), not the identified list. The spec simply
 * never contemplated two people at one device; showing the identified list to the current respondent and
 * degrading everything older to `earlierUnsent()`'s count is the more faithful reading, not a departure.
 *
 * ⚠️ THE FILTER RUNS BEFORE `.limit()`, AND SWAPPING THEM IS A REAL BUG WITH A PRECEDENT IN THIS CODEBASE.
 * Post-filtering a 50-row page silently returns FEWER than the limit, so a device carrying a field day's
 * queue would hide this respondent's own older rows behind a stranger's newer ones. `useSyncOutbox`'s
 * `conflictHere` note records the same class of error, found live. `created_at` is indexed and the session
 * id deliberately is not (no `db.version()` bump), so the ordering stays indexed and only the predicate is
 * in JS.
 */
export async function listSubmissions(
    db: MeridianDb,
    options: { sessionId?: string; limit?: number } = {},
): Promise<OutboxRow[]> {
    const { sessionId, limit = 50 } = options;

    const collection = db.outbox.orderBy('created_at').reverse();
    const scoped =
        sessionId === undefined ? collection : collection.filter((row) => row.respondent_session_id === sessionId);
    const rows = await scoped.limit(limit).toArray();

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
 * ⛔ ONLY `synced` ROWS ARE ELIGIBLE, AND THAT IS THE ONE LINE IN THIS FILE THAT M15 DID NOT MOVE. A
 * pending, failed or conflicted row is NEVER pruned, at any age, by any predicate — §7.3's "never silently
 * dropped" is the whole contract, and a reaper that quietly ate an unsent submission would be the worst
 * possible bug in this file. A stranger's unsent row is CONTAINED by M15, never deleted.
 *
 * ⚠️ INCREMENT M15 ADDED A THIRD TEST, AND THE DOCBLOCK ABOVE HAD ALREADY ARGUED FOR IT WITHOUT NOTICING.
 * "A tablet in a drawer for a month still shows a month-old enumerator's reference to whoever picks it up
 * next" is a statement about the NEXT PERSON, not about elapsed time — the age cap was only ever a proxy
 * for it. Now that a row knows whose visit it belongs to, a delivered receipt from an EARLIER visit is
 * dropped immediately: it can never be rendered again (the list is scoped) and it can never be counted
 * again (`earlierUnsent` counts unsent rows only), so retaining it keeps a `server_reference`, a
 * `synced_at` and a `slug` on shared hardware in exchange for nothing at all. The two original bounds still
 * govern this visit's own receipts.
 */
export async function pruneSynced(db: MeridianDb, now: number = Date.now(), sessionId?: string): Promise<number> {
    const synced = await db.outbox.where('status').equals('synced').sortBy('created_at');
    const cutoff = now - SYNCED_MAX_AGE_MS;

    // An earlier visit's receipts go whole, so the two original bounds are then measured against THIS
    // visit's rows alone. Measuring them against the raw list instead would let twenty of a stranger's
    // receipts push a respondent's own out of the count cap before it was ever theirs to lose.
    const foreign = sessionId === undefined ? [] : synced.filter((row) => row.respondent_session_id !== sessionId);
    const mine = sessionId === undefined ? synced : synced.filter((row) => row.respondent_session_id === sessionId);

    const doomed = mine
        .filter((row, index) => {
            const stamp = Date.parse(row.synced_at ?? row.updated_at);
            const tooOld = Number.isFinite(stamp) && stamp < cutoff;
            // sortBy is oldest-first, so anything outside the newest SYNCED_KEEP is at the front.
            const tooDeep = index < mine.length - SYNCED_KEEP;

            return tooOld || tooDeep;
        })
        .concat(foreign)
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

/**
 * How many UNSENT rows on this device belong to some OTHER visit (Increment M15) — the whole of what a
 * second respondent is told about the first.
 *
 * ⚠️ A COUNT, AND NOTHING ELSE, WHICH IS THE SHAPE THE SPEC ITSELF ASKS FOR. `docs/ux/form-filling-ux-flow.md`
 * §7.3 specifies the persistent app-level surface as "1 submission couldn't be sent. Tap to review." — a
 * number plus an action, never the identified list, which §7.1 keeps for the respondent's own submissions.
 * So no queue tag, no server reference, no form, no time, and no per-row action reaches this number: it
 * exists so that a device with a stuck queue does not look idle, and so "Sync now" has something to explain.
 *
 * ⛔ DELIBERATELY NOT A FOURTH KEY ON `OutboxCounts`. `outbox.test.ts` asserts that object with `toEqual`,
 * i.e. exact equality, and that pinned shape is worth more than the convenience of one call site.
 *
 * `synced` is excluded because a delivered receipt is not waiting for anything — and after M15 an earlier
 * visit's receipts are pruned outright, so there would be nothing to count.
 */
export async function earlierUnsent(db: MeridianDb, sessionId: string): Promise<number> {
    return db.outbox
        .where('status')
        .anyOf('pending', 'needs_attention', 'conflict')
        .filter((row) => row.respondent_session_id !== sessionId)
        .count();
}

/**
 * The same three counts for ONE visit (Increment M15) — what the badges and the summary sentence show.
 *
 * A separate function rather than an argument on `counts()` below, for the reason `earlierUnsent` gives:
 * `counts()`'s return is asserted with `toEqual` and its device-wide meaning is load-bearing for the boot
 * drain and the storage-quota estimate. Two callers wanting two different questions answered is not a case
 * for one function with a mode flag.
 *
 * ⚠️ UNBOUNDED, AND NOT DERIVED FROM `listSubmissions`. That helper caps at 50 newest-by-`created_at`, so a
 * device carrying a field day's queue would show a badge that disagreed with the list beneath it — the
 * exact defect `useSyncOutbox`'s `conflictHere` note records having shipped once already.
 */
export async function countsFor(db: MeridianDb, sessionId: string): Promise<OutboxCounts> {
    const [pending, needsAttention, conflict] = await Promise.all(
        (['pending', 'needs_attention', 'conflict'] as const).map((status) =>
            db.outbox
                .where('status')
                .equals(status)
                .filter((row) => row.respondent_session_id === sessionId)
                .count(),
        ),
    );

    return { pending, needsAttention, conflict };
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
