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
import { counts, discardRow, listConflicts, listSubmissions, pruneSynced, retryAll, retryRow } from '../lib/outbox';
import { replayOne, replayOutbox, type ReplayHooks } from '../lib/replay';
import { deriveReference } from '../lib/reference-number';

/** Background Sync's `SyncManager` is not in the standard TS lib typings. */
type SyncCapableRegistration = ServiceWorkerRegistration & {
    sync?: { register(tag: string): Promise<void> };
};

export interface SyncOutbox {
    pending: Ref<number>;
    needsAttention: Ref<number>;
    conflict: Ref<number>;
    /**
     * Conflicts belonging to the form currently open — the only ones this driver can actually resolve
     * (I10d). Distinct from `conflict`, which is every conflict on the device.
     */
    conflictHere: Ref<number>;
    syncing: Ref<boolean>;
    /** Rows being sent RIGHT NOW, by uuid (I10d). See the note on `syncingUuids` below. */
    syncingUuids: Ref<ReadonlySet<string>>;
    /** Every submission on this device, newest first (I10d). */
    rows: Ref<OutboxRow[]>;
    /** The conflict row currently being reviewed, excluded from the list so it is not offered twice (I10d). */
    reviewingUuid: Ref<string | null>;
    /** Politely announced sync progress (I10d) — see SyncStatus.vue's live region. */
    lastAnnouncement: Ref<string>;
    quotaWarning: Ref<string | null>;
    /** The form this driver is bound to, so a row can be asked whether it is resolvable here. */
    slug: string | undefined;
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
    /** Drop a row (and its queued media), then refresh the counts. */
    discardSubmission(uuid: string): Promise<void>;
    /** Best-effort: register a Background-Sync tag (no-tab replay) + nudge the active worker to replay now. */
    registerBackgroundSync(): void;
    dispose(): void;
}

export interface SyncOutboxOptions {
    /** The current form's slug — scopes conflict resolution to rows the App-level share-token client can resubmit. */
    slug?: string;
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

    const hooks: ReplayHooks = {
        onRowStart(uuid) {
            const next = new Set(syncingUuids.value);
            next.add(uuid);
            setSyncing(next);
            lastAnnouncement.value = 'Sending 1 response';
        },
        onRowSettled(uuid, outcome) {
            const next = new Set(syncingUuids.value);
            next.delete(uuid);
            setSyncing(next);

            if (outcome === 'synced') {
                lastAnnouncement.value = `Response sent — reference ${deriveReference(uuid)}`;
            } else if (outcome === 'needsAttention') {
                lastAnnouncement.value = 'A response couldn’t be sent and needs your attention';
            }
        },
    };

    async function refresh(): Promise<void> {
        // Prune before reading, so the list never renders a receipt that is already past its window.
        await pruneSynced(db);

        const c = await counts(db);
        pending.value = c.pending;
        needsAttention.value = c.needsAttention;
        conflict.value = c.conflict;
        rows.value = await listSubmissions(db);

        // Derived from the row list rather than a second indexed count: conflict rows are NEVER pruned, so
        // the list is exhaustive for this status and cannot disagree with `counts()`.
        conflictHere.value = rows.value.filter(
            (row) => row.status === 'conflict' && (options.slug === undefined || row.slug === options.slug),
        ).length;

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
                quotaWarning.value =
                    `Storage is ${Math.round((usage / quota) * 100)}% full — ` +
                    `${queued} response${queued === 1 ? '' : 's'} queued, using about ${used} MB. Sync soon to free space.`;
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
        const conflicts = await listConflicts(db, options.slug);
        return conflicts[0] ?? null;
    }

    async function discardSubmission(uuid: string): Promise<void> {
        await discardRow(db, uuid);
        await refresh();
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
        reviewingUuid,
        lastAnnouncement,
        quotaWarning,
        slug: options.slug,
        refresh,
        syncNow,
        retryNeedsAttention,
        retryOne,
        nextConflict,
        discardSubmission,
        registerBackgroundSync,
        dispose,
    };
}
