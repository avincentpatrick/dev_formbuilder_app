<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
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
    public function __invoke(Request $request, DashboardMetricsService $metrics): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'kpis' => $metrics->forUser($user),
            // H24a: the four docs/PRD.md:197 criteria H11 left "partially shipped", ungated for every tier.
            // H24b renders them; until then the prop is inert and pinned by a Pest assertion.
            'trends' => $metrics->trendsForUser($user),
        ]);
    }
}
