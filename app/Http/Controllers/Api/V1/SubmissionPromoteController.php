<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Services\Submissions\SubmissionDraftService;
use App\Support\OpenApi\SubmissionRefusalResponseExtension;
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
     *
     * @throws SubmissionConflictException `draft_conflict` — a draft save landed between promotion's read of the answer document and the lock it finalizes under.
     * @throws SubmissionException `submission_version_superseded` — the pinned form version is no longer published.
     */
    public function store(Request $request, Submission $submission, SubmissionDraftService $drafts): JsonResponse
    {
        // ⛔ M67 — THE TWO @throws TAGS ABOVE ARE THE DOCUMENTED CONTRACT, NOT COMMENTARY, AND THIS NOTE IS
        // DELIBERATELY NOT IN THE DOCBLOCK WITH THEM. Scramble publishes an action's docblock DESCRIPTION as
        // the operation's `description` in `openapi.json`, so the first draft of this explanation shipped
        // several paragraphs about Scramble's inference machinery into the public API document — and then
        // drifted the Contract gate the moment Pint realigned it. Implementation notes go in the body; the
        // docblock says what the endpoint does and what it throws.
        //
        // WHY THE TAGS ARE LOAD-BEARING: Scramble infers a route's responses from the CONTROLLER, and this
        // action throws nothing itself — every refusal comes out of `SubmissionDraftService::promote()` one
        // frame down. So the 409 was absent for the whole life of the route while three of its causes were
        // normal outcomes, and M12 could add a fourth with the document staying byte-identical.
        // `Infer\Handler\PhpDocHandler::leave()` reads these tags into the method's exception list;
        // {@see SubmissionRefusalResponseExtension} renders them. ⚠️ DELETING EITHER HALF SILENTLY
        // UN-DOCUMENTS THE STATUS — proven by mutation, because a missing response reads exactly like a
        // route that cannot refuse.
        $result = $drafts->promote($submission, actorId: (string) $request->user()?->getAuthIdentifier());

        return response()->json([
            'data' => [
                'id' => $result->submission->id,
                'status' => $result->submission->status->value,
            ],
        ]);
    }
}
