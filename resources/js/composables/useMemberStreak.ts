import { onScopeDispose, ref } from 'vue';
import type { Ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { fetchMemberStreak } from '@/components/achievements/achievementsClient';

/**
 * The achievements nav item's count badge: this member's current activity streak (Increment K1e).
 *
 * ⚠️ USE THIS EXACTLY ONCE, from `Sidebar.vue`. It registers a router subscription, which is safe there
 * and only there because `AppLayout` is a PERSISTENT Inertia layout — `app.ts` imports it at module scope
 * specifically so its identity is stable and Inertia keeps ONE instance for the session. The sidebar
 * therefore mounts once and survives every visit, which is what makes "refresh on navigate" a
 * subscription rather than a remount. The `useNotificationFeed` contract, for its reasons.
 *
 * ── ⚠️ NO INTERVAL, AND THAT IS A SECURITY DECISION BEFORE IT IS A PERFORMANCE ONE ─────────────────────
 * `useNotificationFeed` polls on a timer and stops after `NOTIFICATION_IDLE_TICKS` idle ticks,
 * because **every poll is a request through the `web` group and therefore touches the session**, and
 * `config/session.php` expires on 120 minutes of INACTIVITY — a feed that polled forever would keep an
 * abandoned tab authenticated forever, silently deleting the idle expiry the threat model assumes and
 * that I8's step-up windows are built on. That composable had to solve the problem because a notification
 * arrives from OUTSIDE the session. **A streak does not.** It changes only when this member does
 * something, and everything they can do is a navigation, so refetching on navigation is not a cheaper
 * approximation of polling — it is strictly more correct, and it adds no second keep-alive to maintain.
 *
 * ⚠️ Do not "improve" this with `usePoll()` / `router.poll()` either. Both drive `router.reload()`, a full
 * Inertia visit that re-requests the current page's props — which is the exact cost a sidecar exists to
 * avoid, and it would arrive with the keep-alive problem attached.
 *
 * ── ⚠️ `enabled` IS NOT AN OPTIMISATION. WITHOUT IT, A TENANT WITH THE MODULE OFF GETS A PHANTOM TOAST ──
 * The sidecar route carries `module:gamification`, and `bootstrap/app.php` renders `ModuleDisabledException`
 * on a NON-`api/v1/*` path as `back()->with('toast', …)` — the branch keys on the request PATH, not on the
 * `Accept` header, so a `fetch` gets a 302 and a session flash rather than a JSON 403. The fetch itself
 * degrades harmlessly (it follows the redirect, receives HTML, and `builderClient` throws a `SyntaxError`
 * this module swallows) — **but the flash is already written**, so the next Inertia render pops
 * "Points, badges and streaks is switched off for this workspace" as a toast the member did nothing to
 * provoke, on a random page, once per navigation forever.
 *
 * The caller therefore passes the same visibility the Achievements nav item is rendered under, and this
 * never asks for a number the workspace has said it does not want. Stated at this length because the
 * failure is invisible from here: everything in THIS file behaves correctly while it happens.
 */
export function useMemberStreak(enabled: () => boolean): { current: Ref<number | null> } {
    /**
     * `null` until the first attempt succeeds, and it NEVER returns to null on a failure.
     *
     * The distinction the badge depends on: null is "we do not know yet", zero is "this member's streak is
     * genuinely broken". A failed fetch must produce neither — it holds the last known value, because a
     * five-second network blip telling somebody their fourteen-day run had ended would be a false claim
     * about them rather than about the request. The `useGraphNotices` / `useNotificationFeed` posture.
     */
    const current = ref<number | null>(null);

    let inFlight = false;
    let disposed = false;
    let stopNavigate: (() => void) | null = null;

    async function refresh(): Promise<void> {
        // DEDUPE. The initial call and a navigation fired in the same tick would otherwise both go out,
        // and two responses landing out of order would write a stale count. `enabled()` is re-read on every
        // attempt rather than once at setup, so a tenant that switches the module back on starts reporting
        // at the next navigation instead of at the next full page load.
        if (inFlight || disposed || !enabled()) return;

        inFlight = true;

        try {
            const streak = await fetchMemberStreak();

            // See `current` above: a null response is "ask again next navigation", never "zero".
            if (streak !== null && !disposed) {
                current.value = streak.current;
            }
        } finally {
            inFlight = false;
        }
    }

    void refresh();

    // `router.on()` returns its own unsubscribe (a VoidFunction). Keeping it is not tidiness: the sidebar
    // never unmounts, so an un-unsubscribed listener would accumulate one extra fetch per past visit —
    // after forty navigations, one navigation would fire forty requests. Measured on the bell, inherited.
    stopNavigate = router.on('navigate', () => {
        void refresh();
    });

    // onScopeDispose rather than onBeforeUnmount so this is also correct inside an effect scope torn down
    // without a component unmount, which is what every Vitest case does. `disposed` additionally silences
    // an in-flight response resolving after teardown.
    onScopeDispose(() => {
        disposed = true;
        stopNavigate?.();
        stopNavigate = null;
    });

    return { current };
}
