<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AnalyticsReportRequest;
use App\Models\User;
use App\Services\Analytics\AnswerValueAggregator;
use Illuminate\Http\JsonResponse;

/**
 * Answer-value aggregation over the typed answer index (ADR-0011 §D3/§D4, Increment H24a).
 *
 * Same gate triplet as {@see AnalyticsReportController}: `ability:read:analytics` + `can:viewAny` on
 * `SavedReportView` + `feature:advanced_analytics`.
 *
 * **This surface is disclosure-heavy on purpose.** The typed index is opt-in (`is_queryable` defaults false
 * everywhere), top-level-scalar-only, written only at finalize and NEVER backfilled — so for essentially
 * every form alive today the honest answer is "nothing is recorded for this question", and §D3 requires that
 * to be said rather than rendered as an empty chart. Both endpoints therefore return refusals and coverage
 * numbers as first-class data, not as error states.
 */
final class AnalyticsQuestionController extends Controller
{
    public function __construct(private readonly AnswerValueAggregator $aggregator) {}

    /**
     * List the questions in scope, each marked reportable or refused with a reason.
     *
     * This is what lets H24b's field picker ENCODE §D3's three properties rather than discover them: a grid
     * or a media field can be flagged "Indexed for reporting" and publish successfully while never producing
     * a row, so the picker must be able to say "this question cannot be reported on" instead of offering it.
     */
    public function index(AnalyticsReportRequest $request): JsonResponse
    {
        // Narrowed rather than null-checked: the route's `ability:` middleware throws an
        // AuthenticationException before this runs, so a null user is unreachable here. Same device as
        // DashboardController.
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->aggregator->questions($request->toQuery(), $user),
        ]);
    }

    /**
     * Aggregate one question's answers, or state why it cannot be aggregated.
     *
     * A key whose `indexed_data_type` changed between versions is a REFUSAL naming that version, never an
     * average over whichever rows survived the type filter — the silently-partial answer
     * `docs/form-versioning-schema-migration.md` §7 predicted and §D4 now forbids.
     */
    public function show(AnalyticsReportRequest $request, string $key): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->aggregator->aggregate($request->toQuery(), $user, $key),
        ]);
    }
}
