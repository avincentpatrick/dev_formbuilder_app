import { onScopeDispose, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    fetchNotificationFeed,
    markAllNotificationsRead,
    markNotificationRead,
} from '@/components/notifications/notificationsClient';
import type { NotificationRow } from '@/components/notifications/types';

/** ~60 seconds, the interval PRD Feature #13's polling deviation is recorded against. */
export const NOTIFICATION_POLL_MS = 60_000;

/**
 * Consecutive ticks with no sign of a human before the poll gives up. See `startTimer()` for why this
 * exists at all — it is a session-lifetime decision, not a performance one.
 */
export const NOTIFICATION_IDLE_TICKS = 30;

/**
 * The notification bell's feed: fetch, poll, refetch-on-navigate, optimistic mark-read (Increment I4).
 *
 * ⚠️ USE THIS EXACTLY ONCE, from `NotificationBell.vue`. It registers a document listener, an interval and
 * a router subscription. That is safe there and only there, because `AppLayout` is a PERSISTENT Inertia
 * layout — `app.ts` imports it at module scope specifically so its identity is stable and Inertia keeps ONE
 * instance for the session. The bell therefore mounts once and survives every visit, which is what makes an
 * interval safe and what makes "refresh on navigate" a subscription rather than a remount.
 *
 * ⚠️ NOT `usePoll()` / `router.poll()`, which do both exist in this Inertia version. Both drive
 * `router.reload()` — a full Inertia visit that re-requests and re-renders the CURRENT page's props every
 * minute, which on /audit-log or /submissions means a paginate plus a count(*) thrown away per tick. The
 * whole reason this feed is a JSON sidecar is to not do that. Do not "simplify" this into them.
 */
export function useNotificationFeed() {
    const items = ref<NotificationRow[]>([]);
    const unreadCount = ref(0);
    /** False until the first attempt settles, so the popover can show a spinner rather than "all caught up". */
    const hasLoaded = ref(false);
    /** The last attempt failed. Used ONLY to add a line to the popover — never to clear anything. */
    const stale = ref(false);

    let inFlight = false;
    /** Bumped by every optimistic local write; see the guard in `refresh()`. */
    let epoch = 0;
    let timer: ReturnType<typeof setInterval> | null = null;
    let idleTicks = 0;
    let stopNavigate: (() => void) | null = null;
    let disposed = false;

    async function refresh(): Promise<void> {
        // DEDUPE. A navigate, a tick and a visibility change can all land in the same frame — opening the
        // bell right after marking something read does exactly that — and two responses landing out of
        // order would write a stale count.
        if (inFlight || disposed) return;

        inFlight = true;
        const startedAt = epoch;

        try {
            const feed = await fetchNotificationFeed();

            // ⚠️ A FAILED POLL NEVER BLANKS THE BELL. `null` means "ask again in sixty seconds", not "you
            // have no notifications" — a five-second network blip must not quietly tell someone their
            // unread queue is empty. The useGraphNotices posture, for the same reason it holds there.
            if (feed === null) {
                stale.value = true;

                return;
            }

            // A mark-read landed while this was in flight, so this response predates it and would
            // resurrect the row. Drop it; the write's own completion schedules another refresh.
            if (startedAt !== epoch) return;

            items.value = feed.items;
            unreadCount.value = feed.unread_count;
            stale.value = false;
        } finally {
            inFlight = false;
            // True even after a failure: show the state we have, not a spinner forever.
            hasLoaded.value = true;
        }
    }

    /**
     * ⚠️ THE IDLE STOP IS A SECURITY DECISION, NOT A PERFORMANCE ONE, AND I8 DEPENDS ON IT.
     *
     * Every poll is a request through the `web` group, so it touches the session — and `config/session.php`
     * expires on 120 minutes of INACTIVITY. A bell that polls forever therefore keeps an abandoned tab
     * authenticated forever, silently deleting the idle expiry the threat model assumes and that I8's
     * step-up/re-auth windows are built on. So the timer stops after {@see NOTIFICATION_IDLE_TICKS} ticks
     * with no sign of a human, and anything that IS a sign — a navigation, the tab coming back to the
     * foreground, opening the popover — resets the counter and restarts it.
     */
    function startTimer(): void {
        if (timer !== null || disposed) return;

        timer = setInterval(() => {
            idleTicks += 1;

            if (idleTicks > NOTIFICATION_IDLE_TICKS) {
                stopTimer();

                return;
            }

            void refresh();
        }, NOTIFICATION_POLL_MS);
    }

    function stopTimer(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    /** A human did something. Refetch now, and give the poll another full idle window. */
    function wake(): void {
        idleTicks = 0;
        void refresh();
        startTimer();
    }

    /**
     * The timer is TORN DOWN while the tab is hidden rather than merely short-circuited — a backgrounded
     * tab should hold no interval at all — and coming back refetches IMMEDIATELY rather than waiting up to
     * a full minute. Someone returning to the tab expects a current bell now.
     */
    function onVisibilityChange(): void {
        if (document.visibilityState === 'hidden') {
            stopTimer();

            return;
        }

        wake();
    }

    /** Optimistic: the write is a 204 with nothing to render, so waiting for it would just delay the paint. */
    function markRead(id: string): void {
        const row = items.value.find((item) => item.id === id);

        // Idempotent, so a double click can never double-decrement the count into the negative.
        if (row === undefined || row.read_at !== null) return;

        epoch += 1;
        const readAt = new Date().toISOString();
        items.value = items.value.map((item) => (item.id === id ? { ...item, read_at: readAt } : item));
        unreadCount.value = Math.max(0, unreadCount.value - 1);

        void markNotificationRead(id);
    }

    function markAllRead(): void {
        if (unreadCount.value === 0) return;

        epoch += 1;
        const readAt = new Date().toISOString();
        items.value = items.value.map((item) => (item.read_at === null ? { ...item, read_at: readAt } : item));
        unreadCount.value = 0;

        void markAllNotificationsRead();
    }

    void refresh();
    startTimer();
    document.addEventListener('visibilitychange', onVisibilityChange);
    // `router.on()` returns its own unsubscribe (a VoidFunction). Keeping it is not tidiness: the bell
    // never unmounts, so an un-unsubscribed listener would accumulate one extra fetch per past visit —
    // after forty navigations, one navigation would fire forty requests.
    stopNavigate = router.on('navigate', () => {
        wake();
    });

    // onScopeDispose rather than onBeforeUnmount so this is also correct if the composable is ever used
    // inside an effect scope that is torn down without a component unmount (every Vitest case does exactly
    // that). `disposed` additionally silences an in-flight response resolving after teardown.
    onScopeDispose(() => {
        disposed = true;
        epoch += 1;
        stopTimer();
        document.removeEventListener('visibilitychange', onVisibilityChange);
        stopNavigate?.();
        stopNavigate = null;
    });

    return { items, unreadCount, hasLoaded, stale, refresh, wake, markRead, markAllRead };
}
