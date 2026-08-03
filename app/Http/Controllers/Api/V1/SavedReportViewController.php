<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SavedReportViewRequest;
use App\Http\Resources\Api\V1\SavedReportViewResource;
use App\Models\SavedReportView;
use App\Models\User;
use App\Services\Analytics\SavedReportViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A user's saved analytics reports (`docs/PRD.md:204`, ADR-0011 §D8, Increment H24a).
 *
 * Gate triplet: `ability:read:analytics` + a `SavedReportViewPolicy` `can:` gate + `feature:advanced_analytics`.
 *
 * **The policy gate is doing real work on the single-row routes.** `saved_report_views` uses `strict` RLS,
 * which scopes to the TENANT — so without the policy's owner check, any member could read or delete a
 * colleague's view. The list route's equivalent is `SavedReportView::scopeOwnedBy()` inside the service.
 */
final class SavedReportViewController extends Controller
{
    public function __construct(private readonly SavedReportViewService $views) {}

    /** The requesting user's saved reports, newest name order, each with its resolution state. */
    public function index(Request $request): JsonResponse
    {
        // Narrowed rather than null-checked: the route's `ability:` middleware throws an
        // AuthenticationException before this runs, so a null user is unreachable here. Same device as
        // DashboardController.
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => SavedReportViewResource::collection($this->views->forUser($user)),
        ]);
    }

    /** Save a report declaration under a name. */
    public function store(SavedReportViewRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $view = $this->views->create(
            $user,
            $request->string('name')->toString(),
            $request->array('definition'),
        );

        return SavedReportViewResource::make($view)->response()->setStatusCode(201);
    }

    /** One saved report, with its declaration resolved (or the reason it cannot be). */
    public function show(SavedReportView $savedReportView): SavedReportViewResource
    {
        return SavedReportViewResource::make($savedReportView);
    }

    /** Rename a saved report, replace its declaration, or both. */
    public function update(SavedReportViewRequest $request, SavedReportView $savedReportView): SavedReportViewResource
    {
        return SavedReportViewResource::make($this->views->update(
            $savedReportView,
            $request->has('name') ? $request->string('name')->toString() : null,
            $request->has('definition') ? $request->array('definition') : null,
        ));
    }

    /** Delete a saved report. */
    public function destroy(SavedReportView $savedReportView): Response
    {
        $this->views->delete($savedReportView);

        return response()->noContent();
    }
}
