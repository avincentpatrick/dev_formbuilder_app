<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Gamification\LeaderboardService;
use App\Services\Gamification\StreakCalculator;
use App\Services\Onboarding\GettingStartedChecklist;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Entitlements\FeatureAdmission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated tenant landing page (H11) — renders the dashboard shell with real, visibility-scoped
 * KPI counts from {@see DashboardMetricsService}. Deliberately no `can:` gate: every role lands here after
 * login, and the per-role scoping (org-wide vs own-forms, and whether the Members tile appears) is the
 * service's job, not the route's. A thin adapter — all aggregation lives in the service.
 */
final class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardMetricsService $metrics,
        GettingStartedChecklist $checklist,
        EntitlementService $entitlements,
        LeaderboardService $leaderboard,
        StreakCalculator $streaks,
        TenantSettingRegistry $settings,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $kpis = $metrics->forUser($user);

        return Inertia::render('Dashboard', [
            'kpis' => $kpis,
            // H24a: the four docs/PRD.md:197 criteria H11 left "partially shipped", ungated for every tier.
            // H24b renders them; until then the prop is inert and pinned by a Pest assertion.
            'trends' => $metrics->trendsForUser($user),
            // J5b — null when the card should not appear at all (dismissed, or every step done). The page
            // reads the null; it does not re-derive the condition.
            'checklist' => $checklist->forUser($user, $kpis),
            'start' => $this->firstRunChoices($user, $entitlements),
            // K1e — null when the workspace has switched gamification off, which the page reads as "omit
            // the card". Not folded into DashboardMetricsService: see gamificationProgress().
            'progress' => $this->gamificationProgress($user, $leaderboard, $streaks, $settings),
        ]);
    }

    /**
     * This member's own gamification progress for the dashboard card (Increment K1e), or null.
     *
     * ⚠️ **NOT IN {@see DashboardMetricsService}, AND THAT IS THE DECISION RATHER THAN AN OVERSIGHT.** That
     * service is the aggregator every dashboard pays for unconditionally; putting a gamification read
     * inside it would charge four queries to every workspace that has switched the module OFF, and the
     * null below is the whole point. Composed here, where the toggle is already being consulted.
     *
     * ⚠️ **THE GATE IS `moduleEnabled()`, NOT `EntitlementService::feature()`, AND THE TWO GENUINELY
     * DISAGREE IN ONE REACHABLE STATE.** `RequireModule` — the middleware on `/achievements` — reads the
     * toggle directly and passes when there is no plan catalog at all; `feature()` returns **false** in
     * that same state, because `currentPlan()?->featureEnabled() ?? false` cannot tell *denied* from
     * *nothing to ask*. Reading `feature()` here would therefore withhold a card whose destination the
     * route would happily serve — J4b2's stranded-reader defect, which `firstRunChoices()` above already
     * carries {@see FeatureAdmission} to avoid on the other axis. This is the exact mirror of the gate the
     * link leads to, which is the only correctness standard that matters for an affordance.
     *
     * ⚠️ **AND THE SIDEBAR's ACHIEVEMENTS ITEM DELIBERATELY READS THE OTHER AXIS, SO DO NOT "ALIGN" THEM.**
     * `nav-model.ts` gates that item on `feature: 'gamification'`, because the client has the entitlement
     * snapshot and no module map — and for this one key the snapshot IS the toggle, since §D6 grants it on
     * every tier. The two therefore agree everywhere except the unseeded-plan state, where this card
     * appears and that nav item does not. That divergence is the already-filed, already-decided
     * fail-open row (user ruling 2026-08-18, `docs/feature-backlog.md`), shared identically with the five
     * other plan-gated destinations; it is not this row's to fix, and matching the WRONG side would make
     * the card disappear from a page whose link still works.
     *
     * ⚠️ **COST, STATED RATHER THAN DISCOVERED: FOUR INDEXED READS** — {@see LeaderboardService::standingFor()}
     * ranks the WHOLE tenant (a roster read plus two grouped aggregates) because a rank is *how many people
     * are ahead of you* and is not a property of one member, plus one distinct-day walk for the streak. A
     * cheaper single-member points query was available and refused: sharing the one ranking is what makes
     * the number on this card and the number on `/achievements` the same number BY CONSTRUCTION rather than
     * by two implementations agreeing today. The dashboard already pays more than this for its trend
     * breakdowns, and every read here is served by an index whose leading column is `tenant_id`.
     *
     * @return array{points: int, badges: int, streak: int, rank: int|null, of: int}|null
     */
    private function gamificationProgress(
        User $user,
        LeaderboardService $leaderboard,
        StreakCalculator $streaks,
        TenantSettingRegistry $settings,
    ): ?array {
        if (! $settings->moduleEnabled('gamification')) {
            return null;
        }

        $tenantId = TenantContext::currentTenantId();

        // StreakCalculator takes the tenant id rather than reading it, so off-tenant has to be made
        // explicit here or not at all — an RLS-filtered read with no tenant GUC returns no rows rather
        // than raising, so "no streak" and "no tenant context" would otherwise be the same output.
        if ($tenantId === null) {
            return null;
        }

        $userId = (string) $user->id;
        $standing = $leaderboard->standingFor($userId);

        return [
            'points' => $standing->points,
            'badges' => $standing->badges,
            // `current`, never `longest`: the card is a nudge about now, and MemberStreak records that
            // showing one and labelling it the other tells somebody they lost something they still hold.
            'streak' => $streaks->for($tenantId, $userId)->current,
            'rank' => $standing->rank,
            'of' => $standing->of,
        ];
    }

    /**
     * Which of onboarding §2's two first-run choices this reader may actually be offered (Increment J5c).
     *
     * ⚠️ **RESOLVED HERE, NOT IN THE PAGE, AND NOT FROM THE `entitlements` PROP THE CLIENT ALREADY HAS.**
     * `useEntitlements()` reads `EntitlementService::snapshot()`, which is built from `feature()` — and
     * `feature()` disagrees with the `feature:` middleware by a SIGN FLIP in one reachable state. (That
     * class is named in prose rather than with a doc reference, because in J5a a fully-qualified one made
     * Pint's `fully_qualified_strict_types` fixer add a real `use` for it: **a formatter can introduce a
     * dependency for the sake of a comment.**) The middleware
     * admits a request when there is no plan catalog at all (`currentPlan() !== null && ! feature($key)`,
     * which its own docblock calls a "dev/test has no plans" pass-through); `feature()` returns **false** in
     * that same state, because `currentPlan()?->featureEnabled() ?? false` cannot tell *denied* from
     * *nothing to ask*. So a client-side gate here would withhold the template card on a request the route
     * would have served — J4b2's stranded-reader defect, one surface over. {@see FeatureAdmission} is that
     * mirror, extracted in J5a for exactly this caller.
     *
     * The unseeded-catalog state is not hypothetical: a deploy that migrates before it seeds, a restored
     * database, and every feature test that does not seed a plan.
     *
     * ⚠️ **BOTH of `/forms/templates`'s GATES, NOT JUST THE FEATURE ONE.** The route carries
     * `can:create,Form` as well, and a card offering a destination whose policy refuses the reader is the
     * same defect with a different middleware. The blank arm asks the same policy alone, because
     * `POST /forms` carries the same one and no feature gate.
     *
     * @return array{can_create: bool, can_use_templates: bool}
     */
    private function firstRunChoices(User $user, EntitlementService $entitlements): array
    {
        $canCreate = $user->can('create', Form::class);

        return [
            'can_create' => $canCreate,
            'can_use_templates' => $canCreate && FeatureAdmission::admits($entitlements, 'form_templates'),
        ];
    }
}
