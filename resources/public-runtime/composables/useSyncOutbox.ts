/**
 * The app-side offline replay driver (Increment G8b). Instantiated once at the App root so it spans sessions
 * and forms. It owns the live outbox counts that drive the sync-status UI and fires the shared `replayOutbox`
 * on the reliable cross-platform triggers — network reconnect (`online`), app foreground (`visibilitychange`),
 * a manual "Sync now", and on boot when rows are already queued. The true Background-Sync `sync` event in sw.ts
 * is an additional no-tab path; both drive the same Dexie DB + same replay function, so idempotency makes them
 * safe to overlap. `navigator`/`window`/`document`/`fetch` are injectable for tests.
 */

import { onScopeDispose, ref, type Ref } from 'vue';
import type { MeridianDb, OutboxRow } from '../lib/db';
import { counts, discardRow, listConflicts, retryAll } from '../lib/outbox';
import { replayOutbox } from '../lib/replay';

/** Background Sync's `SyncManager` is not in the standard TS lib typings. */
type SyncCapableRegistration = ServiceWorkerRegistration & {
    sync?: { register(tag: string): Promise<void> };
};

export interface SyncOutbox {
    pending: Ref<number>;
    needsAttention: Ref<number>;
    conflict: Ref<number>;
    syncing: Ref<boolean>;
    quotaWarning: Ref<string | null>;
    /** Recompute the outbox counts + storage-quota estimate (call after enqueue so the badge updates at once). */
    refresh(): Promise<void>;
    /** Replay every pending row once, then refresh. */
    syncNow(): Promise<void>;
    /** Return the "needs attention" rows to pending and replay them (from the banner's Retry). */
    retryNeedsAttention(): Promise<void>;
    /** The oldest unresolved conflict row for this form (Increment G8c), or null — feeds the review UX. */
    nextConflict(): Promise<OutboxRow | null>;
    /** Drop a reviewed conflict row (and its queued media), then refresh the counts (Increment G8c). */
    discardConflict(uuid: string): Promise<void>;
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

export function createSyncOutbox(db: MeridianDb, options: SyncOutboxOptions = {}): SyncOutbox {
    const nav = options.navigator ?? (typeof navigator !== 'undefined' ? navigator : undefined);
    const win = options.window ?? (typeof window !== 'undefined' ? window : undefined);
    const doc = options.document ?? (typeof document !== 'undefined' ? document : undefined);
    const doFetch = options.fetch ?? fetch;

    const pending = ref(0);
    const needsAttention = ref(0);
    const conflict = ref(0);
    const syncing = ref(false);
    const quotaWarning = ref<string | null>(null);

    async function refresh(): Promise<void> {
        const c = await counts(db);
        pending.value = c.pending;
        needsAttention.value = c.needsAttention;
        conflict.value = c.conflict;
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
                quotaWarning.value = `Storage is ${Math.round((usage / quota) * 100)}% full — sync soon to free space.`;
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
            await replayOutbox(db, doFetch);
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

    async function nextConflict(): Promise<OutboxRow | null> {
        const rows = await listConflicts(db, options.slug);
        return rows[0] ?? null;
    }

    async function discardConflict(uuid: string): Promise<void> {
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
        syncing,
        quotaWarning,
        refresh,
        syncNow,
        retryNeedsAttention,
        nextConflict,
        discardConflict,
        registerBackgroundSync,
        dispose,
    };
}
