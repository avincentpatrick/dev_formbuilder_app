/**
 * THE GUEST DEVICE'S SWEEPER (Increment M22) — the enumerator and reaper `draft_answers` and `media_queue`
 * have never had.
 *
 * Every other store on this device is collected by something. `outbox` has `pruneSynced()`. `app_state`
 * holds one scalar. `cached_manifests` is keyed by a version that a republish replaces. These two are not:
 *
 *  - **`draft_answers`** has two readers, two writers, and ONE deleter — `useAutosave.clear()`, which
 *    deletes the `[form_version_id, local_draft_id]` key it was constructed with. Its seven-day TTL is a
 *    branch inside `restore()`, so it only ever fires against the key `restore()` just fetched. A republish
 *    moves `form_version_id`, the session remounts on the NEW key, and the pre-republish row is from that
 *    moment never written, read, or deleted again — by anything, including the TTL that was supposed to
 *    collect it. Before this module there was no `where`, `orderBy`, `toArray`, `each` or `count` on this
 *    table anywhere in the tree.
 *  - **`media_queue`** is worse, because the miss is silent rather than structural. `stash()` writes
 *    `client_submission_uuid: null` — the parent submission does not exist yet — and the table's only two
 *    deleters (`lib/outbox.ts`, in `markSynced` and `deleteRow`) both run
 *    `where('client_submission_uuid').equals(uuid)` against a uuid STRING. ⚠️ **A null-uuid row is not
 *    merely unequal to that string: IndexedDB does not index `null` at all, so the row is absent from the
 *    `client_submission_uuid` index and NO argument to that `where()` can ever reach it.** The one index
 *    that names this field is the one that cannot find these rows. A photo, signature or ID scan picked
 *    mid-fill and then abandoned therefore lives on shared kiosk hardware until the browser evicts the
 *    origin.
 *
 * ⚠️ THIS IS A RETENTION DEFECT AND NOT A DISCLOSURE ONE, AND THE DISTINCTION IS LOAD-BEARING RATHER THAN
 * PEDANTIC. M21 closed every read that could surface this content — `listForSubmission` is by uuid, a
 * `local:` ref only renders if the answers are restored, and both `draft_answers` readers now refuse a
 * foreign row through `draftBelongsToVisit`. Nothing here re-opens or re-argues that. What is left is the
 * data itself sitting on a device that is handed to the next person, which containment does not address
 * and only collection does.
 *
 * ── WHY THIS RUNS ANYWHERE, INCLUDING THE SERVICE WORKER ────────────────────────────────────────────────
 * The obvious design — reap what does not belong to the visit on screen, the way `pruneSynced` drops an
 * earlier visit's receipts — CANNOT be the design here, and the reason is not preference. `sw.ts` imports
 * `lib/db.ts` and `lib/replay.ts`, and `tsconfig.sw.json` re-checks that whole graph under
 * `lib: ["ESNext", "WebWorker"], types: []`, where `Storage` and `sessionStorage` do not exist. A module
 * this one is called from cannot read a visit, and `lib/respondent-session.ts` can never be imported here.
 *
 * So the test is REACHABILITY rather than ownership, and that turns out to be the better test anyway:
 * a blob is live exactly while some answer document still carries its `local:` ref. That is a fact about
 * the database, it needs no visit, and it is the same question `attachToSubmission()` asks at finalize —
 * asked by the same function, `collectLocalMediaIds()`, so the two cannot drift apart. It also means an
 * EARLIER visit's blob that is still referenced by a queued submission is safe, which ownership would not
 * have got right: the drain is device-wide on purpose (`lib/outbox.ts`), so sending a stranger's queued
 * submission is a thing this runtime deliberately still does.
 *
 * ⛔ NOTHING UNSENT IS EVER REAPED, AT ANY AGE, BY ANY PREDICATE. `pruneSynced`'s contract — "§7.3's
 * 'never silently dropped' is the whole contract, and a reaper that quietly ate an unsent submission would
 * be the worst possible bug in this file" — governs here too, and this module is stricter: it does not
 * touch `outbox` at all. It reads it, to learn what to spare.
 */

import { DRAFT_TTL_MS, type MeridianDb } from './db';
import { collectLocalMediaIds } from './media-queue';

/**
 * How long an UNREFERENCED orphan blob is spared anyway.
 *
 * ⚠️ THIS IS A SAFETY MARGIN, NOT A RETENTION POLICY, AND THE TWO WOULD BE SIZED COMPLETELY DIFFERENTLY.
 * Retention here is "as long as something references it" — an abandoned blob dies with the draft that
 * named it, which the next respondent's first keystroke overwrites (M21: a shared primary key makes
 * containment and collection the same mechanism). This window covers only the interval in which a
 * genuinely LIVE `local:` ref exists in memory and not yet in any row this module can read.
 *
 * ⛔ AND ONE HOUR RATHER THAN FIVE MINUTES BECAUSE OF A CASE THAT IS EASY TO MISS: the conflict-review
 * session runs with `enabled: false`, so `createAutosave` is fully inert and writes NO draft row at all
 * (Increment G8c, and deliberately — a transient review must not clobber a live fill on the shared key).
 * A media pick made during a review is therefore referenced by nothing on disk until the resubmit, while
 * `refresh()` can fire underneath it on `online` or `visibilitychange`. A review takes minutes. The
 * ordinary case needs only the 800ms autosave debounce; this one sets the number.
 *
 * It doubles as the concurrency guard, which is why no transaction spans the mark and the sweep: a pick
 * that lands between building the live set and running the delete is younger than the cutoff by
 * construction, so it is spared without any locking.
 */
export const MEDIA_ORPHAN_GRACE_MS = 60 * 60 * 1000;

export interface ReapResult {
    /** Expired `draft_answers` rows deleted — including the ones no key could reach. */
    drafts: number;
    /** Orphaned `media_queue` blobs deleted. */
    media: number;
}

/**
 * Collect what nothing can reach any more. Safe to call on every refresh and every drain; safe to call
 * concurrently with a fill.
 *
 * ⚠️ THE ORDER OF THE TWO SWEEPS IS LOAD-BEARING AND IT IS DRAFTS FIRST. A blob referenced only by an
 * expired draft should go in the SAME pass as that draft, not on the next one — reaping media first would
 * mark it live from a row that is about to be deleted, and the orphan would survive until something else
 * happened to call this again. On a device that has been abandoned, nothing else happens.
 *
 * `now` is injected for the reason `pruneSynced` and `respondentSession` inject it: a time-dependent
 * branch that can only be tested by waiting is a branch that does not get tested.
 */
export async function reapAbandoned(db: MeridianDb, now: number = Date.now()): Promise<ReapResult> {
    const drafts = await reapExpiredDrafts(db, now);
    const media = await reapOrphanedMedia(db, now);

    return { drafts, media };
}

/**
 * The seven-day TTL, finally reachable for keys nobody holds.
 *
 * ⛔ THE WINDOW IS NOT A NEW POLICY — IT IS `DRAFT_TTL_MS`, THE SAME CONSTANT `useAutosave.fresh()` HAS
 * ALWAYS GATED THE RESTORE ON, imported rather than restated so the sweeper and the reader cannot come to
 * disagree about what "expired" means. That is why the constant moved to `lib/db.ts` in this increment:
 * this module cannot import a composable (`useAutosave.ts` imports `vue`, and this file is reached from
 * `sw.ts`'s graph), and two copies of a retention window is exactly the drift this project has recorded
 * often enough to stop repeating.
 *
 * ⚠️ THE RANGE QUERY IS LEXICOGRAPHIC ON AN ISO STRING, WHICH IS ONLY SOUND BECAUSE EVERY WRITE OF THIS
 * FIELD IS PROVABLY `toISOString()`. There are exactly two writers. `useAutosave.persist()` stamps
 * `new Date(now()).toISOString()`. The one-time localStorage migration writes `migrated.savedAt`, but only
 * after `fresh()` has accepted it — and `fresh()` computes `Number.isFinite(now() - Date.parse(savedIso))`,
 * so an unparseable stamp is cleared and never reaches the table. Fixed-width UTC ISO-8601 sorts
 * lexicographically exactly as it sorts chronologically, so `updated_at`'s existing secondary index answers
 * this directly — no table scan, and no `db.version()` bump for an index that was already declared in v1.
 */
async function reapExpiredDrafts(db: MeridianDb, now: number): Promise<number> {
    const cutoff = new Date(now - DRAFT_TTL_MS).toISOString();
    // `primaryKeys()` rather than `toArray()`: the decision needs the key and nothing else, and these rows
    // carry a respondent's whole answer document.
    const doomed = await db.draft_answers.where('updated_at').below(cutoff).primaryKeys();

    if (doomed.length === 0) {
        return 0;
    }

    await db.draft_answers.bulkDelete(doomed);

    return doomed.length;
}

/**
 * Delete every blob that no answer document still names — the null-uuid rows the two deleters in
 * `lib/outbox.ts` cannot see.
 *
 * ⚠️ A FULL TABLE WALK, DELIBERATELY, AND THE INDEX IT DECLINES TO USE IS THE POINT OF THE DEFECT.
 * `status` is indexed and every orphan is `queued` today, because `markUploaded()` is only ever reached
 * through `listForSubmission(db, uuid)` and so can only touch a row that already has a uuid. That is a
 * proof about a call graph rather than about the data, and keying the sweep on it would make the reaper
 * depend on it staying true. `client_submission_uuid` is indexed too and is useless here for the reason
 * this module exists: IndexedDB does not index `null`. So the walk gates on the field itself, which is the
 * literal definition of an orphan and needs no proof at all.
 */
async function reapOrphanedMedia(db: MeridianDb, now: number): Promise<number> {
    const live = await liveLocalMediaIds(db);
    const cutoff = now - MEDIA_ORPHAN_GRACE_MS;
    const doomed: string[] = [];

    await db.media_queue.each((row) => {
        // Linked to a submission: `markSynced` and `deleteRow` own this row's lifetime, not this module.
        if (row.client_submission_uuid !== null) {
            return;
        }
        if (live.has(row.attachment_local_id)) {
            return;
        }
        // An unparseable stamp yields NaN, every comparison with it is false, and the row is therefore
        // reaped. That is the right direction and not an accident: this row is already known to be
        // referenced by nothing, so the only thing a corrupt timestamp could buy it is a longer stay.
        if (Date.parse(row.created_at) > cutoff) {
            return;
        }
        doomed.push(row.attachment_local_id);
    });

    if (doomed.length === 0) {
        return 0;
    }

    await db.media_queue.bulkDelete(doomed);

    return doomed.length;
}

/**
 * Every `local:` id still named by an answer document on this device.
 *
 * ⛔ BOTH TIERS, AND OMITTING THE SECOND WOULD BE A DATA-LOSS BUG RATHER THAN A MISSED OPTIMISATION. A
 * finalized row's blobs normally carry its uuid, so they are not orphans — but `attachToSubmission()`
 * links with `.filter((row) => row.client_submission_uuid === null)` (M21), so a ref that failed to link,
 * or a row whose enqueue and link did not both complete, keeps a null uuid while the outbox row that needs
 * it still carries the `local:` ref. `replay.ts` refuses to POST a submission with an unresolved
 * placeholder — `'queued media is incomplete'`, five attempts, then `needs_attention` — so reaping that
 * blob would park a real respondent's real submission forever. Reading the outbox costs one indexed query.
 *
 * `synced` is excluded because `markSynced()` empties `answers` and deletes that row's media in the SAME
 * transaction, so a delivered row can neither name a blob nor have left one behind.
 *
 * ⚠️ TOP-LEVEL MEDIA ONLY, WHICH IS ENFORCED SERVER-SIDE AND NOT MERELY ASSUMED HERE.
 * `collectLocalMediaIds()` walks only top-level answers because `StructuralValidationGate` refuses to
 * publish a media field inside a repeatable section (`PublishValidationException::mediaInRepeatableSection`).
 * The important property is not that the ban exists but that this sweep and `attachToSubmission()` ask the
 * question through the SAME function: if instance-addressed media is ever allowed, the linker breaks in the
 * same commit as the reaper, loudly, instead of the reaper quietly eating live blobs on its own.
 */
async function liveLocalMediaIds(db: MeridianDb): Promise<Set<string>> {
    const live = new Set<string>();

    await db.draft_answers.each((row) => {
        for (const id of collectLocalMediaIds(row.answers)) {
            live.add(id);
        }
    });

    await db.outbox
        .where('status')
        .anyOf('pending', 'needs_attention', 'conflict')
        .each((row) => {
            for (const id of collectLocalMediaIds(row.answers)) {
                live.add(id);
            }
        });

    return live;
}
