<?php

declare(strict_types=1);

namespace App\Listeners\Gamification;

use App\Enums\PointRule;
use App\Events\MemberJoined;
use App\Services\Gamification\PointsRecorder;
use App\Services\Tenancy\TenantMembershipService;

/**
 * A welcome award for joining the workspace (Increment K1a) — the one rule bounded to once per membership,
 * which it achieves by keying on the member themselves rather than by any check in PHP.
 *
 * Its purpose is partly structural: without it a brand-new member's achievements page is empty, and an
 * empty scoreboard is the surface people decide the feature is broken on before they have done anything to
 * fill it.
 *
 * ── ⚠️ THIS IS THE ONE LISTENER THAT RUNS INSIDE ITS EMITTER'S TRANSACTION ──────────────────────────────
 * {@see MemberJoined} is raised INSIDE {@see TenantMembershipService::joinOpenTenant()}'s transaction, not
 * post-commit, because that method is the one membership write with no ambient tenant context: it uses
 * `TenantContext::applyLocal()`, whose `SET LOCAL` GUC dies at commit, so a post-commit RLS write would be
 * refused outright. Two consequences that had to be checked rather than assumed, and are pinned by
 * `MemberJoinedAwardTest`:
 *
 *   1. **The insert is inside the caller's transaction**, so a raising duplicate would poison a real
 *      registration. It cannot raise — {@see PointsRecorder} uses `ON CONFLICT DO NOTHING` precisely so
 *      there is no exception to catch. This listener is the sharpest case for that choice in the codebase.
 *   2. **The gate has to resolve a plan from inside that borrowed context.** `EntitlementService` is
 *      `scoped()` and memoizes — keyed BY TENANT (`$this->plans[$tenantId]`), so cross-tenant staleness is
 *      structurally impossible and is not the risk here. The residual one is narrower: `resolvePlan()` run
 *      with no GUC returns null, `feature()` reads that as false, and a memoized null would silence the
 *      award with no error anywhere. It does not happen — the borrow is established before the event is
 *      raised, and the free-plan fallback carries `gamification` besides — but "does not happen" is a claim
 *      about a sequence, so a test drives the real `joinOpenTenant()` path end to end rather than the
 *      listener in isolation. Only the real path can prove it.
 */
final class AwardPointsForMemberJoined
{
    public function __construct(private readonly PointsRecorder $points) {}

    public function handle(MemberJoined $event): void
    {
        $this->points->award(
            PointRule::MemberJoined,
            $event->userId,
            'member',
            $event->userId,
        );
    }
}
