<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AnalyticsReportRequest;
use App\Models\User;
use App\Services\Analytics\AnalyticsMetricsService;
use App\Support\Analytics\AnalyticsQuery;
use Illuminate\Http\JsonResponse;

/**
 * Cross-form submission aggregates (ADR-0011, `docs/PRD.md:203`, Increment H24a).
 *
 * Authorization is the route's `ability:read:analytics` (scoping the TOKEN) + a `SavedReportViewPolicy`
 * `can:viewAny` gate (re-checking the acting user's real permission) + the `feature:advanced_analytics` plan
 * gate. RLS scopes every query to the token's tenant, and `AnalyticsFormSet` narrows further to the forms
 * that user may actually see.
 *
 * Thin — every number comes from {@see AnalyticsMetricsService}, and every bound is enforced by
 * {@see AnalyticsQuery}'s constructor, so this class holds no rules of its own.
 */
final class AnalyticsReportController extends Controller
{
    public function __construct(private readonly AnalyticsMetricsService $metrics) {}

    /**
     * Aggregate submissions across forms for a bounded date range.
     *
     * The response carries the resolved query back verbatim, because a saved view resolves at read time and a
     * client must be able to tell which declaration produced these numbers — including the timezone the
     * buckets were computed in, which is never the server's session zone.
     */
    public function show(AnalyticsReportRequest $request): JsonResponse
    {
        $query = $request->toQuery();

        // Narrowed rather than null-checked: the route's `ability:` middleware throws an
        // AuthenticationException before this runs, so a null user is unreachable here. Same device as
        // DashboardController.
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'total' => $this->metrics->total($query, $user),
                'series' => $this->metrics->series($query, $user),
                'breakdown' => $this->metrics->breakdown($query, $user),
                'drafts' => $this->metrics->draftMetrics($query, $user),
                'forms_accepting' => $this->metrics->acceptingFormsCount($user),
            ],
            'meta' => [
                'query' => $query->toArray(),
                // Stated on every response: `date_trunc('week', …)` is ISO Monday-start and not
                // configurable, and buckets are computed in the query's zone rather than the server's.
                'week_starts_on' => 'monday',
            ],
        ]);
    }
}
