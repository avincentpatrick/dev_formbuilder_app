import { effectScope, nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The achievements nav badge's feed (Increment K1e).
 *
 * ⚠️ WHAT THIS FILE IS FOR. Three of the four behaviours below are ones the composable gets RIGHT while
 * looking identical from the outside when it gets them wrong — which is exactly the shape a component test
 * cannot see:
 *
 *  1. IT MUST NOT FETCH WHEN THE DESTINATION IS HIDDEN. The sidecar route carries `module:gamification`,
 *     and `bootstrap/app.php` answers a web ModuleDisabledException with `back()->with('toast', …)` —
 *     the branch keys on the request PATH, not the Accept header. So an unguarded fetch by a workspace
 *     that switched gamification off writes a SESSION FLASH, and the next Inertia render pops
 *     "switched off for this workspace" as a toast the member did nothing to provoke, on a random page,
 *     once per navigation forever. The fetch itself degrades silently; only the absence of the REQUEST
 *     can be asserted.
 *  2. A FAILED FETCH MUST NOT BLANK THE BADGE. `null` means "ask again next navigation", never "your
 *     streak is zero" — a five-second network blip must not tell somebody their fourteen-day run ended.
 *  3. IT MUST UNSUBSCRIBE. The sidebar never unmounts, so a leaked listener accumulates one extra request
 *     per past visit: after forty navigations, one navigation fires forty requests.
 */

const mocks = vi.hoisted(() => ({
    fetchMemberStreak: vi.fn(),
    handlers: [] as (() => void)[],
    unsubscribe: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (_event: string, handler: () => void) => {
            mocks.handlers.push(handler);

            return mocks.unsubscribe;
        },
    },
}));

vi.mock('@/components/achievements/achievementsClient', () => ({
    fetchMemberStreak: mocks.fetchMemberStreak,
}));

const { useMemberStreak } = await import('../useMemberStreak');

/** Fire the navigation subscription the way Inertia would. */
function navigate(): void {
    mocks.handlers.forEach((handler) => handler());
}

beforeEach(() => {
    mocks.fetchMemberStreak.mockReset();
    mocks.unsubscribe.mockReset();
    mocks.handlers.length = 0;
});

describe('useMemberStreak', () => {
    it('reads the streak once on creation and exposes it', async () => {
        mocks.fetchMemberStreak.mockResolvedValue({ current: 7 });

        const scope = effectScope();
        const streak = scope.run(() => useMemberStreak(() => true))!;

        await nextTick();
        await nextTick();

        expect(mocks.fetchMemberStreak).toHaveBeenCalledTimes(1);
        expect(streak.current.value).toBe(7);

        scope.stop();
    });

    it('starts at null rather than zero, so the badge does not flash a broken streak on load', async () => {
        // The two are different facts and the badge renders neither: null is "we do not know yet", zero is
        // "this member's streak is genuinely broken". A `ref(0)` default would paint a bubble reading 0 for
        // one frame on every page load — or, worse, suppress the real one.
        mocks.fetchMemberStreak.mockImplementation(() => new Promise(() => {}));

        const scope = effectScope();
        const streak = scope.run(() => useMemberStreak(() => true))!;

        expect(streak.current.value).toBeNull();

        scope.stop();
    });

    it('does NOT ask at all while the destination is hidden', async () => {
        // ⚠️ THE PHANTOM-TOAST GUARD. See this file's header: an unguarded fetch against a module-disabled
        // workspace leaves a session flash behind, and the member collects a toast they did not provoke on
        // a random page, once per navigation. Nothing inside the composable misbehaves while it happens.
        mocks.fetchMemberStreak.mockResolvedValue({ current: 7 });

        const scope = effectScope();
        const streak = scope.run(() => useMemberStreak(() => false))!;

        await nextTick();
        navigate();
        await nextTick();

        expect(mocks.fetchMemberStreak).not.toHaveBeenCalled();
        expect(streak.current.value).toBeNull();

        scope.stop();
    });

    it('re-reads the predicate per attempt, so switching the module back on recovers', async () => {
        // Read once at setup instead and a workspace that re-enabled gamification would show no badge
        // until the next FULL page load, which on a persistent Inertia layout can be days.
        mocks.fetchMemberStreak.mockResolvedValue({ current: 3 });
        let enabled = false;

        const scope = effectScope();
        const streak = scope.run(() => useMemberStreak(() => enabled))!;

        await nextTick();
        expect(mocks.fetchMemberStreak).not.toHaveBeenCalled();

        enabled = true;
        navigate();
        await nextTick();
        await nextTick();

        expect(mocks.fetchMemberStreak).toHaveBeenCalledTimes(1);
        expect(streak.current.value).toBe(3);

        scope.stop();
    });

    it('refetches on every navigation, which is the only thing that keeps it current', async () => {
        // ⚠️ AND IT HOLDS NO INTERVAL, WHICH IS A SECURITY DECISION RATHER THAN A COST ONE. Every poll is a
        // request through the `web` group and touches the session, and `config/session.php` expires on 120
        // minutes of INACTIVITY — `useNotificationFeed` needed a timer because a notification arrives from
        // OUTSIDE the session, and had to add an idle-stop to avoid keeping abandoned tabs alive forever.
        // A streak only changes when this member acts, and acting IS a navigation.
        mocks.fetchMemberStreak.mockResolvedValue({ current: 1 });

        const scope = effectScope();
        scope.run(() => useMemberStreak(() => true));

        await nextTick();
        await nextTick();
        navigate();
        await nextTick();
        await nextTick();
        navigate();
        await nextTick();
        await nextTick();

        expect(mocks.fetchMemberStreak).toHaveBeenCalledTimes(3);

        scope.stop();
    });

    it('holds the last known value when a fetch fails, and never reports zero for it', async () => {
        mocks.fetchMemberStreak.mockResolvedValueOnce({ current: 12 });

        const scope = effectScope();
        const streak = scope.run(() => useMemberStreak(() => true))!;

        await nextTick();
        await nextTick();
        expect(streak.current.value).toBe(12);

        // A dropped connection, a 302 to the central domain, or the module being switched off mid-session
        // all arrive here as null.
        mocks.fetchMemberStreak.mockResolvedValue(null);
        navigate();
        await nextTick();
        await nextTick();

        expect(streak.current.value).toBe(12);

        scope.stop();
    });

    it('reports a genuine zero, which is the whole reason null is kept distinct from it', async () => {
        // The complement of the case above: a server-reported 0 IS written, so somebody whose run ended
        // stops seeing a stale bubble. Without this pair, "hold the last value" could be implemented as
        // "ignore falsy answers" and both would look correct.
        mocks.fetchMemberStreak.mockResolvedValueOnce({ current: 4 });

        const scope = effectScope();
        const streak = scope.run(() => useMemberStreak(() => true))!;

        await nextTick();
        await nextTick();

        mocks.fetchMemberStreak.mockResolvedValue({ current: 0 });
        navigate();
        await nextTick();
        await nextTick();

        expect(streak.current.value).toBe(0);

        scope.stop();
    });

    it('dedupes concurrent attempts, so two responses cannot land out of order', async () => {
        let settle: (value: { current: number } | null) => void = () => {};
        mocks.fetchMemberStreak.mockImplementation(
            () => new Promise((resolve) => {
                settle = resolve;
            }),
        );

        const scope = effectScope();
        scope.run(() => useMemberStreak(() => true));

        await nextTick();
        // The creation fetch is still in flight; a navigation in the same window must not start a second.
        navigate();
        navigate();

        expect(mocks.fetchMemberStreak).toHaveBeenCalledTimes(1);

        settle({ current: 2 });
        scope.stop();
    });

    it('unsubscribes from the router when its scope is torn down', () => {
        mocks.fetchMemberStreak.mockResolvedValue({ current: 1 });

        const scope = effectScope();
        scope.run(() => useMemberStreak(() => true));
        scope.stop();

        expect(mocks.unsubscribe).toHaveBeenCalledTimes(1);
    });

    it('ignores a response that lands after teardown', async () => {
        let settle: (value: { current: number } | null) => void = () => {};
        mocks.fetchMemberStreak.mockImplementation(
            () => new Promise((resolve) => {
                settle = resolve;
            }),
        );

        const scope = effectScope();
        const streak = scope.run(() => useMemberStreak(() => true))!;

        await nextTick();
        scope.stop();
        settle({ current: 9 });
        await nextTick();
        await nextTick();

        expect(streak.current.value).toBeNull();
    });
});
