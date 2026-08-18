import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { NotificationFeed, NotificationRow } from '@/components/notifications/types';

/**
 * The bell's feed engine (Increment I4) — the file where every polling hazard lives, and therefore the
 * file that has to prove each one is handled.
 *
 * `AppLayout` is a PERSISTENT Inertia layout, so the bell mounts once and never unmounts for the life of
 * the session. That is what makes an interval safe, and it is also what makes each of these a real bug
 * rather than a hypothetical: an un-unsubscribed router listener accumulates one extra fetch per past
 * visit; a poll that blanks on failure tells someone their inbox is empty during a five-second blip; a
 * response that started before a mark-read resurrects the row it just cleared.
 *
 * The CLIENT seam is mocked, not `builderClient` — mocking two layers down would let a broken client pass.
 */
const mocks = vi.hoisted(() => ({
    fetchFeed: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
    on: vi.fn(),
    unsubscribe: vi.fn(),
}));

vi.mock('@/components/notifications/notificationsClient', () => ({
    fetchNotificationFeed: mocks.fetchFeed,
    markNotificationRead: mocks.markRead,
    markAllNotificationsRead: mocks.markAllRead,
}));

vi.mock('@inertiajs/vue3', () => ({ router: { on: mocks.on } }));

// Imported AFTER the mocks so the composable resolves them.
const { useNotificationFeed, NOTIFICATION_POLL_MS, NOTIFICATION_IDLE_TICKS } = await import(
    '@/composables/useNotificationFeed'
);

function row(overrides: Partial<NotificationRow> = {}): NotificationRow {
    return {
        id: 'n1',
        type: 'submission_received',
        title: 'New submission',
        description: 'A new response arrived on Clinic Intake.',
        url: '/submissions/s1',
        action_label: 'View submission',
        read_at: null,
        created_at: '2026-08-06T11:00:00Z',
        ...overrides,
    };
}

function feed(overrides: Partial<NotificationFeed> = {}): NotificationFeed {
    return { unread_count: 1, items: [row()], ...overrides };
}

type Feed = ReturnType<typeof useNotificationFeed>;

/** Lifecycle hooks need a mount; the composable's own scope is what `onScopeDispose` hangs off. */
function host(): { wrapper: VueWrapper; feed: Feed } {
    let captured: Feed | null = null;

    const wrapper = mount(
        defineComponent({
            setup() {
                captured = useNotificationFeed();

                return () => null;
            },
        }),
    );

    return { wrapper, feed: captured as unknown as Feed };
}

/** Fire the callback the composable handed to `router.on('navigate', …)`. */
function navigate(): void {
    const call = mocks.on.mock.calls.find(([event]) => event === 'navigate');
    (call?.[1] as () => void)();
}

beforeEach(() => {
    vi.useFakeTimers();
    mocks.fetchFeed.mockReset().mockResolvedValue(feed());
    mocks.markRead.mockReset().mockResolvedValue(true);
    mocks.markAllRead.mockReset().mockResolvedValue(true);
    mocks.on.mockReset().mockReturnValue(mocks.unsubscribe);
    mocks.unsubscribe.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('the first load', () => {
    it('publishes the server’s count and rows', async () => {
        const { wrapper, feed: f } = host();
        await vi.advanceTimersByTimeAsync(0);

        expect(f.unreadCount.value).toBe(1);
        expect(f.items.value).toHaveLength(1);
        expect(f.hasLoaded.value).toBe(true);
        wrapper.unmount();
    });
});

describe('the poll', () => {
    it('polls again after the interval', async () => {
        const { wrapper } = host();
        await vi.advanceTimersByTimeAsync(0);
        expect(mocks.fetchFeed).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_MS);
        expect(mocks.fetchFeed).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });

    it('CLEARS the timer on teardown', async () => {
        const { wrapper } = host();
        await vi.advanceTimersByTimeAsync(0);
        wrapper.unmount();

        // Behavioural, not a clearInterval spy: what matters is that no further request is made, however
        // the teardown is spelled.
        await vi.advanceTimersByTimeAsync(3 * NOTIFICATION_POLL_MS);
        expect(mocks.fetchFeed).toHaveBeenCalledTimes(1);
    });

    it('unsubscribes from router navigation on teardown', async () => {
        const { wrapper } = host();
        await vi.advanceTimersByTimeAsync(0);
        wrapper.unmount();

        // Without this the bell — which never unmounts in production, but is re-created in every test and
        // in any future re-mount — would accumulate one extra fetch per past visit.
        expect(mocks.unsubscribe).toHaveBeenCalled();
    });

    it('refetches when Inertia navigates', async () => {
        const { wrapper } = host();
        await vi.advanceTimersByTimeAsync(0);

        navigate();
        await vi.advanceTimersByTimeAsync(0);

        expect(mocks.fetchFeed).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });

    it('never runs two requests at once', async () => {
        mocks.fetchFeed.mockReset().mockReturnValue(new Promise(() => {}));
        const { wrapper } = host();

        navigate();
        await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_MS);

        // A navigate, a tick and a visibility change can all land in the same frame; two responses landing
        // out of order would write a stale count.
        expect(mocks.fetchFeed).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('gives up after a long idle, and starts again the moment a human appears', async () => {
        // A poll touches the session, so an eternal one keeps an abandoned tab authenticated forever and
        // deletes the 120-minute idle expiry the threat model assumes. I8's step-up work depends on this.
        const { wrapper } = host();
        await vi.advanceTimersByTimeAsync(0);

        await vi.advanceTimersByTimeAsync((NOTIFICATION_IDLE_TICKS + 2) * NOTIFICATION_POLL_MS);
        const afterIdle = mocks.fetchFeed.mock.calls.length;

        await vi.advanceTimersByTimeAsync(5 * NOTIFICATION_POLL_MS);
        expect(mocks.fetchFeed).toHaveBeenCalledTimes(afterIdle);

        navigate();
        await vi.advanceTimersByTimeAsync(0);
        expect(mocks.fetchFeed).toHaveBeenCalledTimes(afterIdle + 1);
        wrapper.unmount();
    });

    it('tears the timer down while the tab is hidden and refetches the instant it returns', async () => {
        const { wrapper } = host();
        await vi.advanceTimersByTimeAsync(0);

        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.advanceTimersByTimeAsync(3 * NOTIFICATION_POLL_MS);
        expect(mocks.fetchFeed).toHaveBeenCalledTimes(1);

        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(0);

        // Immediately, not up to a minute later: someone returning to the tab expects a current bell now.
        expect(mocks.fetchFeed).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });
});

describe('failure', () => {
    it('KEEPS the last known feed when a poll fails, and flags it stale', async () => {
        const { wrapper, feed: f } = host();
        await vi.advanceTimersByTimeAsync(0);

        mocks.fetchFeed.mockResolvedValue(null);
        await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_MS);

        // Blanking would turn "we could not check" into "you have nothing", which is the one reading this
        // must never produce.
        expect(f.items.value).toHaveLength(1);
        expect(f.unreadCount.value).toBe(1);
        expect(f.stale.value).toBe(true);
        wrapper.unmount();
    });

    it('reports loaded even after a first attempt that failed, so the popover is not a spinner forever', async () => {
        mocks.fetchFeed.mockReset().mockResolvedValue(null);
        const { wrapper, feed: f } = host();
        await vi.advanceTimersByTimeAsync(0);

        expect(f.hasLoaded.value).toBe(true);
        wrapper.unmount();
    });
});

describe('the optimistic writes', () => {
    it('marks a row read immediately, before the request resolves', async () => {
        mocks.markRead.mockReturnValue(new Promise(() => {}));
        const { wrapper, feed: f } = host();
        await vi.advanceTimersByTimeAsync(0);

        f.markRead('n1');

        // Synchronously — the row is bold until this lands, and a round trip of bold is a flicker.
        expect(f.items.value[0]!.read_at).not.toBeNull();
        expect(f.unreadCount.value).toBe(0);
        expect(mocks.markRead).toHaveBeenCalledWith('n1');
        wrapper.unmount();
    });

    it('ignores a mark-read on a row that is already read, so the count cannot go negative', async () => {
        mocks.fetchFeed.mockResolvedValue(feed({ unread_count: 0, items: [row({ read_at: '2026-08-06T11:05:00Z' })] }));
        const { wrapper, feed: f } = host();
        await vi.advanceTimersByTimeAsync(0);

        f.markRead('n1');

        expect(f.unreadCount.value).toBe(0);
        expect(mocks.markRead).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('discards a poll response that started before an optimistic write', async () => {
        let resolveSlow: (value: NotificationFeed) => void = () => {};
        const { wrapper, feed: f } = host();
        await vi.advanceTimersByTimeAsync(0);

        mocks.fetchFeed.mockReturnValue(new Promise<NotificationFeed>((resolve) => { resolveSlow = resolve; }));
        await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_MS);

        f.markRead('n1');
        // The in-flight response predates the write and still describes the row as unread.
        resolveSlow(feed());
        await vi.advanceTimersByTimeAsync(0);

        expect(f.items.value[0]!.read_at).not.toBeNull();
        expect(f.unreadCount.value).toBe(0);
        wrapper.unmount();
    });

    it('marks everything read in a single request', async () => {
        mocks.fetchFeed.mockResolvedValue(feed({
            unread_count: 2,
            items: [row({ id: 'a' }), row({ id: 'b' })],
        }));
        const { wrapper, feed: f } = host();
        await vi.advanceTimersByTimeAsync(0);

        f.markAllRead();

        expect(f.unreadCount.value).toBe(0);
        expect(f.items.value.every((item) => item.read_at !== null)).toBe(true);
        expect(mocks.markAllRead).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('does not call the bulk route when nothing is unread', async () => {
        mocks.fetchFeed.mockResolvedValue(feed({ unread_count: 0, items: [] }));
        const { wrapper, feed: f } = host();
        await vi.advanceTimersByTimeAsync(0);

        f.markAllRead();

        expect(mocks.markAllRead).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
