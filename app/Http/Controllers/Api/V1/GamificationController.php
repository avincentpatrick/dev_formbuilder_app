<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MemberProgressResource;
use App\Http\Resources\Api\V1\ScoreboardResource;
use App\Models\User;
use App\Policies\PointAwardPolicy;
use App\Services\Gamification\LeaderboardService;
use App\Services\Gamification\MemberProgress;
use App\Services\Gamification\MemberStreak;
use App\Services\Gamification\Scoreboard;
use App\Services\Gamification\StreakCalculator;
use App\Services\Gamification\TeamProgressService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * The gamification read surface (K1d; gamification-design.md §10, ADR-0020 §D7).
 *
 * ── ⚠️ THE TWO ENDPOINTS ARE GATED DIFFERENTLY, AND THAT ASYMMETRY IS THE FEATURE ───────────────────────
 * §D7 mints **no thirtieth permission** and reuses the org/own split the RBAC catalog already encodes.
 * {@see me()} is readable by any authenticated member with no `can:` gate at all — it names nobody but the
 * caller. {@see leaderboard()} carries `can:viewAny,PointAward` ({@see PointAwardPolicy}), because it is the
 * one payload that puts a colleague's name next to a number.
 *
 * ⚠️ **`ApiAbilities::MANAGE_SCOPES` STATES THAT A ROUTE IN THIS GROUP WITHOUT A `can:` GATE BREAKS THE
 * TOKEN-SCOPE ARGUMENT, SO THE EXCEPTION IS ARGUED HERE RATHER THAN LEFT TO BE NOTICED.** That rule exists
 * because an ability with any-of semantics can be minted by a principal the route's real authorization would
 * refuse — so the `can:` gate is what re-checks the acting user. It does not apply to `me()`, because there
 * is no such gap to close: the resource IS the caller, the token cannot be broader than its issuer, and
 * there is no permission in the catalog that would let one member read another's standing here anyway.
 *
 * ⚠️ **NEITHER ROUTE MOUNTS `feature:gamification`, AND THAT IS DELIBERATE.** ADR-0020 §D6 grants the key on
 * every plan tier, so a plan can never withhold it and the only thing that could ever fire such a gate is a
 * tenant that switched the module off itself — for whom `RequireFeature`'s *"Upgrade your plan"* is the
 * wrong sentence entirely. Both routes carry `module:gamification` instead, which refuses with copy that
 * names something someone inside the workspace can actually undo. See gamification-design.md §9.
 */
final class GamificationController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly StreakCalculator $streaks,
        private readonly TeamProgressService $team,
    ) {}

    /**
     * Fetch the calling member's own points, badges, streak and standing.
     *
     * Readable by any member of the workspace, with no permission: ADR-0020 §D7 gives everyone their own
     * numbers and gates only the named list. `standing.rank` is a competition rank — one plus the number of
     * members strictly ahead — so a tie shares a place and the next one skips it, and `standing.of` counts
     * ACTIVE MEMBERS rather than the members who have scored, so somebody who has earned nothing is last
     * rather than absent. `rank` is null when the caller holds no active membership here.
     *
     * `streak.current` decays to zero after a full missed day while `streak.longest` only ever rises; they
     * are different measurements and neither substitutes for the other. Days are cut at UTC midnight, stated
     * as a literal rather than inherited from any configuration (ADR-0020 §D10(b)).
     */
    public function me(Request $request): MemberProgressResource
    {
        // Narrowed rather than null-checked: the route's auth + `ability:` middleware throw before this
        // runs, so a null user is unreachable. The AnalyticsQuestionController device.
        /** @var User $user */
        $user = $request->user();

        $userId = (string) $user->id;

        return MemberProgressResource::make(new MemberProgress(
            standing: $this->leaderboard->standingFor($userId),
            streak: $this->streakFor($userId),
        ));
    }

    /**
     * This member's streak, or the explicit empty one off-tenant.
     *
     * {@see LeaderboardService} carries its own guard, so this is the only place `me()` has to make the
     * off-tenant case explicit — and it must, because {@see StreakCalculator} deliberately takes the tenant
     * id as an argument rather than reading it, so that an off-tenant call is unrepresentable at the call
     * site instead of merely unlikely. Without this the reader would receive a streak of zero, which is the
     * read-side twin of the silent RLS write refusal: "you have no streak" and "nobody established tenant
     * context" would be the same output.
     */
    private function streakFor(string $userId): MemberStreak
    {
        $tenantId = TenantContext::currentTenantId();

        return $tenantId === null
            ? MemberStreak::none()
            : $this->streaks->for($tenantId, $userId);
    }

    /**
     * Fetch the workspace leaderboard and the team's collective totals.
     *
     * Gated on `dashboard.org.view` — the existing permission, not a new one (ADR-0020 §D7). Entries cover
     * every ACTIVE member, including those who have earned nothing, ranked last rather than omitted.
     *
     * ⚠️ `team` and `entries` answer different questions and will not reconcile, by design, in three places.
     * `team.responses` exceeds the responses credited across `entries` by exactly the GUEST submissions: a
     * response collected through a public link is one the workspace collected and one that credits nobody,
     * because crediting the form's owner would make the ladder a contest between public links rather than
     * between people (§D8). And `team.points` and `team.contributors` both count members who have since
     * LEFT the workspace — the award ledger is append-only, so their history outlives their membership —
     * while `entries` names active members only (§D11). None of the three is an error; a client showing them
     * together should say which is which.
     */
    public function leaderboard(): ScoreboardResource
    {
        return ScoreboardResource::make(new Scoreboard(
            ladder: $this->leaderboard->forCurrentTenant(),
            team: $this->team->forCurrentTenant(),
        ));
    }
}
