/**
 * The app-side offline replay driver (Increment G8b). Instantiated once at the App root so it spans sessions
 * and forms. It owns the live outbox counts that drive the sync-status UI and fires the shared `replayOutbox`
 * on the reliable cross-platform triggers — network reconnect (`online`), app foreground (`visibilitychange`),
 * a manual "Sync now", and on boot when rows are already queued. The true Background-Sync `sync` event in sw.ts
 * is an additional no-tab path; both drive the same Dexie DB + same replay function, so idempotency makes them
 * safe to overlap. `navigator`/`window`/`document`/`fetch` are injectable for tests.
 *
 * ── I10d ────────────────────────────────────────────────────────────────────────────────────────────────
 * It additionally owns the per-submission LIST (`docs/PRD.md:223`, UX §7.1): the rows themselves, which of
 * them is being sent right now, and which conflicts can actually be resolved from the form currently open.
 */

import { onScopeDispose, ref, type Ref } from 'vue';
import type { MeridianDb, OutboxRow } from '../lib/db';
import type { OutboxCounts } from '../lib/outbox';
import {
    counts,
    countsFor,
    discardRow,
    earlierUnsent,
    listConflicts,
    listSubmissions,
    pruneSynced,
    retryAll,
    retryRow,
} from '../lib/outbox';
import { replayOne, replayOutbox, type ReplayHooks } from '../lib/replay';

/** Background Sync's `SyncManager` is not in the standard TS lib typings. */
type SyncCapableRegistration = ServiceWorkerRegistration & {
    sync?: { register(tag: string): Promise<void> };
};

export interface SyncOutbox {
    /**
     * ⚠️ THESE THREE STAY DEVICE-WIDE AFTER M15, DELIBERATELY. They drive the boot drain and the storage
     * quota estimate, both of which are about the DEVICE and would under-report if they only saw one visit.
     * What a respondent is SHOWN comes from `unsentHere` / `earlierUnsent` / `conflictHere` below.
     */
    pending: Ref<number>;
    needsAttention: Ref<number>;
    conflict: Ref<number>;
    /**
     * Conflicts belonging to the form currently open AND to this visit — the only ones this driver can
     * actually resolve (I10d; the visit half is M15). Distinct from `conflict`, which is every conflict on
     * the device. "Here" always meant "resolvable from where you are standing"; M15 only added that a
     * stranger's parked answers are not resolvable BY YOU, because opening one renders them on screen.
     */
    conflictHere: Ref<number>;
    syncing: Ref<boolean>;
    /** Rows being sent RIGHT NOW, by uuid (I10d). See the note on `syncingUuids` below. */
    syncingUuids: Ref<ReadonlySet<string>>;
    /**
     * THIS VISIT's submissions on this device, newest first (I10d; scoped in M15).
     *
     * ⚠️ IT USED TO BE "every submission on this device", AND THAT WAS THE DEFECT RATHER THAN THE FEATURE.
     * The surface mounts above the phase machine on an unauthenticated page, so on shared hardware the
     * plain reading of "this device" was "whoever used it last". See `lib/outbox.ts`'s `listSubmissions`.
     */
    rows: Ref<OutboxRow[]>;
    /**
     * Increment M15 — unsent rows belonging to some OTHER visit: a bare count, and the only thing a second
     * respondent learns about the first. See `earlierUnsent` in `lib/outbox.ts` for why it is a count.
     */
    earlierUnsent: Ref<number>;
    /**
     * Increment M15 — the same three counts for THIS VISIT: what the badges show and what the summary
     * sentence counts. Unbounded, so they never disagree with the 50-row list beneath them.
     */
    mine: Ref<OutboxCounts>;
    /** The conflict row currently being reviewed, excluded from the list so it is not offered twice (I10d). */
    reviewingUuid: Ref<string | null>;
    /** Politely announced sync progress (I10d) — see SyncStatus.vue's live region. */
    lastAnnouncement: Ref<string>;
    quotaWarning: Ref<string | null>;
    /** The form this driver is bound to, so a row can be asked whether it is resolvable here. */
    slug: string | undefined;
    /** Increment M15 — the visit this driver is bound to, so a row can be asked whose it is. */
    sessionId: string | undefined;
    /** Recompute the outbox counts + rows + storage-quota estimate (call after enqueue so the UI updates at once). */
    refresh(): Promise<void>;
    /** Replay every pending row once, then refresh. */
    syncNow(): Promise<void>;
    /** Return the "needs attention" rows to pending and replay them (from the banner's Retry). */
    retryNeedsAttention(): Promise<void>;
    /** Return ONE row to pending and replay just it (I10d — the per-row "Retry now"). */
    retryOne(uuid: string): Promise<void>;
    /** The oldest unresolved conflict row for this form (Increment G8c), or null — feeds the review UX. */
    nextConflict(): Promise<OutboxRow | null>;
    /** ONE conflict row on this form by uuid (I10d) — what the per-row Review button resolves. */
    conflictRow(uuid: string): Promise<OutboxRow | null>;
    /** Drop a row (and its queued media), then refresh the counts. False when it is another visit's (M15). */
    discardSubmission(uuid: string): Promise<boolean>;
    /** Best-effort: register a Background-Sync tag (no-tab replay) + nudge the active worker to replay now. */
    registerBackgroundSync(): void;
    dispose(): void;
}

export interface SyncOutboxOptions {
    /** The current form's slug — scopes conflict resolution to rows the App-level share-token client can resubmit. */
    slug?: string;
    /**
     * Increment M15 — the current respondent's visit (`lib/respondent-session.ts`), scoping what is shown
     * and what can be discarded. Threaded in as a plain string rather than imported: this module is reached
     * from `sw.ts`'s neighbourhood and that module reads `sessionStorage`, which the service-worker
     * type-check program has no types for. Undefined keeps the pre-M15 device-wide behaviour.
     */
    sessionId?: string;
    fetch?: typeof fetch;
    navigator?: Navigator;
    window?: Window;
    document?: Document;
}

const MB = 1024 * 1024;

export function createSyncOutbox(db: MeridianDb, options: SyncOutboxOptions = {}): SyncOutbox {
    const nav = options.navigator ?? (typeof navigator !== 'undefined' ? navigator : undefined);
    const win = options.window ?? (typeof window !== 'undefined' ? window : undefined);
    const doc = options.document ?? (typeof document !== 'undefined' ? document : undefined);
    const doFetch = options.fetch ?? fetch;

    const pending = ref(0);
    const needsAttention = ref(0);
    const conflict = ref(0);
    const conflictHere = ref(0);
    const earlierUnsentCount = ref(0);
    const mine = ref<OutboxCounts>({ pending: 0, needsAttention: 0, conflict: 0 });
    const syncing = ref(false);
    const quotaWarning = ref<string | null>(null);
    const rows = ref<OutboxRow[]>([]);
    const reviewingUuid = ref<string | null>(null);
    const lastAnnouncement = ref('');

    /**
     * ⚠️ IN MEMORY, AND NOT A PERSISTED `'syncing'` STATUS. The decisive reason is `sw.ts`: it replays with no
     * tab open, and the browser terminates a service worker whenever its Background-Sync budget lapses. A row
     * written as `'syncing'` and then orphaned would be stuck forever, because `listPending()` filters on
     * `status === 'pending'` and no future pass would ever pick it up again — the "never silently dropped"
     * guarantee violated BY the feature meant to uphold it. Recovering from that needs a stale-timestamp
     * reaper built on a timeout guess, which is more machinery and more failure modes than the state is worth.
     *
     * It is also simply honest: "syncing" is a fact about an attempt happening in THIS tab. A row the service
     * worker is sending shows Queued here, because this tab genuinely does not know.
     */
    const syncingUuids = ref<ReadonlySet<string>>(new Set());

    function setSyncing(next: Set<string>): void {
        // Replace rather than mutate: reactivity on a Set proxy is one more thing to be subtly wrong under
        // happy-dom, and this costs nothing at these sizes.
        syncingUuids.value = next;
    }

    /**
     * Whether a settling row is one of THIS visit's (Increment M15). Read off `rows`, which `refresh()` has
     * already scoped, rather than with a second database round-trip inside a replay hook: the row is either
     * in the scoped list the respondent is looking at or it is not, and a lookup that disagreed with what is
     * on screen would be the `conflictHere` mistake again. An unscoped driver answers true, as before.
     */
    function belongsToThisVisit(uuid: string): boolean {
        return options.sessionId === undefined || rows.value.some((row) => row.client_submission_uuid === uuid);
    }

    const hooks: ReplayHooks = {
        onRowStart(uuid) {
            const next = new Set(syncingUuids.value);
            next.add(uuid);
            setSyncing(next);
            lastAnnouncement.value = 'Sending 1 response';
        },
        onRowSettled(uuid, outcome, reference) {
            const next = new Set(syncingUuids.value);
            next.delete(uuid);
            setSyncing(next);

            if (outcome === 'synced') {
                // Increment J2e — the SERVER's handle, handed in by the replay rather than derived from the
                // client uuid. The derived code was stored nowhere, so a screen reader was announcing a
                // number the tenant could not look up. Null only on the pre-J2e path, where saying less is
                // better than saying something unfindable.
                //
                // ⚠️ INCREMENT M15 — AND THE REFERENCE IS THE ONE PART THAT MUST NOT ESCAPE THE VISIT.
                // The drain is device-wide by design, so a row queued by the PREVIOUS respondent settles
                // while the current one is on screen — and this region is `aria-live`, so a screen reader
                // at a kiosk would read out a stranger's reference unprompted. The sweep that found the
                // list found this too; the list was the only half the backlog row named.
                //
                // It still announces, because something genuinely did send and silence would be its own
                // small lie. It just says the sentence that names nobody.
                lastAnnouncement.value =
                    reference == null || !belongsToThisVisit(uuid)
                        ? 'Response sent'
                        : `Response sent — reference ${reference}`;
            } else if (outcome === 'needsAttention') {
                lastAnnouncement.value = 'A response couldn’t be sent and needs your attention';
            }
        },
    };

    async function refresh(): Promise<void> {
        // Prune before reading, so the list never renders a receipt that is already past its window. M15
        // hands it the session too: an EARLIER visit's delivered receipt can never be rendered or counted
        // again, so keeping a server reference for it on shared hardware buys nothing.
        await pruneSynced(db, Date.now(), options.sessionId);

        // ⚠️ STILL DEVICE-WIDE, AND M15 LEFT THEM THAT WAY ON PURPOSE — see the interface note. The boot
        // drain below and `checkQuota` are statements about the DEVICE, and a session-scoped `pending`
        // would leave an earlier respondent's queue undrained and their storage pressure unreported.
        const c = await counts(db);
        pending.value = c.pending;
        needsAttention.value = c.needsAttention;
        conflict.value = c.conflict;

        rows.value = await listSubmissions(db, { sessionId: options.sessionId });

        // What the respondent is SHOWN. An unscoped driver answers the device-wide numbers for both, which
        // is exactly the pre-M15 behaviour every existing caller and test expects.
        mine.value = options.sessionId === undefined ? { ...c } : await countsFor(db, options.sessionId);
        earlierUnsentCount.value =
            options.sessionId === undefined ? 0 : await earlierUnsent(db, options.sessionId);

        // ⚠️ FROM AN INDEXED QUERY, NOT FROM `rows`. Deriving it by filtering the list was wrong and the
        // comment that justified it ("the list is exhaustive for this status") was false: `listSubmissions`
        // caps at 50 newest-by-created_at across ALL statuses, so on a device carrying a field day's queue an
        // older conflict falls outside the window. `conflict` comes from an unbounded indexed count, so the
        // two disagreed — and SyncStatus turns that disagreement into both the Review CTA's visibility AND a
        // sentence claiming the conflict belongs to another form. The respondent would be told to look
        // somewhere else for a row that is right here and that they cannot reach.
        conflictHere.value = (await listConflicts(db, options.slug, options.sessionId)).length;

        await checkQuota();
    }

    async function checkQuota(): Promise<void> {
        const storage = nav?.storage;
        if (storage === undefined || typeof storage.estimate !== 'function') {
            return;
        }
        try {
            const { usage, quota } = await storage.estimate();
            if (usage !== undefined && quota !== undefined && quota > 0 && usage / quota > 0.8) {
                // `docs/offline-first-sync-design.md:93` asks for the COUNT and the SIZE, not a bare
                // percentage: "you have N submissions queued and using X MB". A percentage alone tells the
                // respondent something is wrong without telling them what syncing would buy back.
                const queued = pending.value + needsAttention.value + conflict.value;
                const used = Math.round(usage / MB);
                // `usage` is the ORIGIN's total, not the queue's — it includes cached shells, schemas and
                // media. Saying "N queued, using X MB" would attribute all of it to the queue; the two facts
                // are reported side by side instead.
                quotaWarning.value =
                    `This site is using about ${used} MB, ${Math.round((usage / quota) * 100)}% of what the browser allows. ` +
                    `${queued} response${queued === 1 ? '' : 's'} waiting to send — sync soon to free space.`;
            } else {
                quotaWarning.value = null;
            }
        } catch {
            quotaWarning.value = null;
        }
    }

    let running = false;
    async function syncNow(): Promise<void> {
        if (running) {
            return;
        }
        running = true;
        syncing.value = true;
        try {
            await replayOutbox(db, doFetch, hooks);
        } finally {
            running = false;
            syncing.value = false;
            await refresh();
        }
    }

    async function retryNeedsAttention(): Promise<void> {
        await retryAll(db);
        await syncNow();
    }

    async function retryOne(uuid: string): Promise<void> {
        if (!(await retryRow(db, uuid))) {
            return;
        }
        await refresh();
        await replayOne(db, uuid, doFetch, hooks);
        await refresh();
    }

    async function nextConflict(): Promise<OutboxRow | null> {
        const conflicts = await listConflicts(db, options.slug, options.sessionId);
        return conflicts[0] ?? null;
    }

    /**
     * ⚠️ THIS IS THE ONE READ THAT RETURNS ANSWER CONTENT, AND UNTIL M15 IT WAS THE LEAST GUARDED.
     * `listSubmissions` blanks `answers` on every read as belt and braces ("nothing that renders a list
     * should be handed answer data at all"); this bypasses that helper entirely, and `App.vue` seeds a fill
     * session with `row.answers`, so the row is rendered field by field. Session-checked as well as
     * slug-checked, because hiding the Review button is a property of one render and this is a property of
     * the data — anything holding a uuid could reach it otherwise.
     */
    async function conflictRow(uuid: string): Promise<OutboxRow | null> {
        const row = await db.outbox.get(uuid);

        // Slug-checked, not just status-checked: the resolver reuses a share-token client bound to ONE form,
        // so handing it a foreign row would re-mint against the wrong slug.
        return row !== undefined &&
            row.status === 'conflict' &&
            (options.slug === undefined || row.slug === options.slug) &&
            (options.sessionId === undefined || row.respondent_session_id === options.sessionId)
            ? row
            : null;
    }

    /**
     * Increment M15 — the session is passed THROUGH to the delete rather than checked here, so the refusal
     * lives at the write. See `discardRow` in `lib/outbox.ts`.
     *
     * @return true when a row was actually removed; false when it belonged to another visit.
     */
    async function discardSubmission(uuid: string): Promise<boolean> {
        const dropped = await discardRow(db, uuid, options.sessionId);
        await refresh();

        return dropped;
    }

    function registerBackgroundSync(): void {
        if (nav === undefined || !('serviceWorker' in nav)) {
            return;
        }
        nav.serviceWorker.ready
            .then((registration) => {
                const sync = (registration as SyncCapableRegistration).sync;
                if (sync !== undefined && typeof sync.register === 'function') {
                    void sync.register('outbox-sync').catch(() => undefined);
                }
                registration.active?.postMessage('replay-outbox');
            })
            .catch(() => undefined);
    }

    function onOnline(): void {
        void syncNow();
    }
    function onVisible(): void {
        if (doc?.visibilityState === 'visible' && (nav?.onLine ?? true)) {
            void syncNow();
        }
    }

    win?.addEventListener('online', onOnline);
    doc?.addEventListener('visibilitychange', onVisible);

    // Boot: surface any pre-existing queue and drain it if we're already online.
    void refresh().then(() => {
        if ((nav?.onLine ?? true) && pending.value > 0) {
            void syncNow();
        }
    });

    function dispose(): void {
        win?.removeEventListener('online', onOnline);
        doc?.removeEventListener('visibilitychange', onVisible);
    }

    onScopeDispose(dispose, true);

    return {
        pending,
        needsAttention,
        conflict,
        conflictHere,
        syncing,
        syncingUuids,
        rows,
        earlierUnsent: earlierUnsentCount,
        mine,
        reviewingUuid,
        lastAnnouncement,
        quotaWarning,
        slug: options.slug,
        sessionId: options.sessionId,
        refresh,
        syncNow,
        retryNeedsAttention,
        retryOne,
        nextConflict,
        conflictRow,
        discardSubmission,
        registerBackgroundSync,
        dispose,
    };
}
