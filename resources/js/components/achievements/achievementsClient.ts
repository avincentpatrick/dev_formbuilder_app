// The achievements nav badge's transport (Increment K1e). Fifth consumer of `builderClient`, after the
// builder, `scopesClient`, `integrationsClient` and `notificationsClient` — and read-only, like three of
// those four.
//
// ── Why a sidecar fetch at all, rather than a shared Inertia prop ──────────────────────────────────────
// `routes/tenant.php`'s notification block records the mechanism and doc #28 §10 turns it into an
// instruction: an Inertia partial reload RE-DISPATCHES the current page's controller — Inertia filters
// what it SERIALIZES, not what it COMPUTES — so a streak count in `HandleInertiaRequests::share()` would
// run its query on every render of every page in the application, including `/audit-log` and
// `/submissions`, which already pay for a paginate plus a `count(*)` per navigation.
//
// ── It swallows every error and returns null, which is `notificationsClient`'s divergence, not the
//    builder's ────────────────────────────────────────────────────────────────────────────────────────
// `scopesClient` re-throws anything that is not a `BuilderRequestError`, because its callers are click
// handlers with a user watching. This one is called from a navigation subscription, where the caller has
// no way to report anything and no user is waiting on it. Three failures are ordinary here rather than
// exceptional: a dropped connection (a bare `TypeError` from `fetch`), a tenant that cannot be identified
// (a 302 to the central domain, which `builderClient` then throws a `SyntaxError` parsing), and — the one
// unique to this route — a **403 `module_disabled`** the moment somebody switches the module off while a
// tab is open. `null` means "ask again after the next navigation", and the composable holds its last known
// value rather than blanking the badge.
//
// ⚠️ THE 403 IS WHY `null` MUST NOT BE READ AS ZERO. A member whose workspace just disabled gamification
// should keep seeing whatever the badge last said until the nav item itself disappears on the next full
// page load; rendering "0" would tell them their streak had been broken, which is a claim about their
// behaviour rather than about the workspace's configuration.

import { builderClient } from '@/components/builder/builderClient';

/** The sidecar's payload. Deliberately one field — see `AchievementsController::streak()`. */
export interface MemberStreakCount {
    current: number;
}

/** This member's current streak, or null if this attempt failed for any reason at all. */
export async function fetchMemberStreak(): Promise<MemberStreakCount | null> {
    try {
        const result = await builderClient.get<MemberStreakCount>('/achievements/streak');

        return result.conflict ? null : result.data;
    } catch {
        return null;
    }
}
