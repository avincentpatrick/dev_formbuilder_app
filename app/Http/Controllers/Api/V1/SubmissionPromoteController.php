<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Services\Submissions\SubmissionDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finalize a draft submission (Increment H9b) — the authenticated encoder/confirm seam over
 * {@see SubmissionDraftService::promote()}, and the route the OCR review-and-confirm flow (H18/H19) reuses.
 * The `{submission}` is route-model-bound under RLS; the route carries `ability:write:submissions` (token
 * scope) + `can:promote,submission` (the acting user's real permission). `promote()` runs the full Stage-3
 * exactly once and flips the SAME row `draft → submitted` in place, recording the acting encoder as the actor.
 * A non-draft target is an idempotent no-op (200, unchanged status).
 */
final class SubmissionPromoteController extends Controller
{
    /**
     * Promote a draft to a submitted response.
     */
    public function store(Request $request, Submission $submission, SubmissionDraftService $drafts): JsonResponse
    {
        $result = $drafts->promote($submission, actorId: (string) $request->user()?->getAuthIdentifier());

        return response()->json([
            'data' => [
                'id' => $result->submission->id,
                'status' => $result->submission->status->value,
            ],
        ]);
    }
}
