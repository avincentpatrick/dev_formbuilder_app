<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Onboarding\GettingStartedChecklist;
use App\Support\Entitlements\FeatureAdmission;
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
        ]);
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
