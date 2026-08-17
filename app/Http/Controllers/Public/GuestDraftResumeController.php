<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestResumeToken;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Support\Api\ApiErrorResponse;
use App\Support\Guest\GuestShareTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restores a guest draft from a resume link (Increment H9b). Tenant context + the target draft
 * `submissions.id` are both carried by the verified resume token ({@see EstablishGuestDraftContext}); the
 * draft is loaded by that id under RLS, so a token minted for one tenant can never read another's draft.
 *
 * Returns the saved answers + completeness so the SPA can restore state, plus a freshly-minted short-lived
 * SHARE token for the pinned version — so from here the resumed session drives the ordinary guest schema /
 * draft-save / submit endpoints exactly like a first-time fill, and H9b needs no resume-token-keyed write
 * routes. A draft that has been promoted (finalized) or reaped is gone → 404 `draft_not_found`.
 *
 * Gated on `feature:save_and_resume` at the route, matching the draft-save channel.
 */
final class GuestDraftResumeController extends Controller
{
    use ReadsGuestResumeToken;

    /**
     * Fetch a guest draft's saved state for a resume token.
     *
     * @unauthenticated
     */
    public function show(Request $request, GuestShareTokenService $tokens): JsonResponse
    {
        $token = $this->resumeToken($request);

        $draft = Submission::query()
            ->whereKey($token->submissionId)
            ->where('status', SubmissionStatus::Draft)
            ->first();

        if ($draft === null) {
            return ApiErrorResponse::make(404, 'draft_not_found', 'This draft is no longer available.');
        }

        // Increment P3a — the answers and the lost-update baseline are read in ONE query on purpose. Taking
        // them separately would let another device's save land between the two reads, handing this device a
        // baseline that matches answers it was never shown — a guard that certifies the exact state it failed
        // to prevent. `first()` gives both from one row version.
        $stored = SubmissionAnswer::query()
            ->where('submission_id', $draft->id)
            ->first(['answers', 'answers_content_checksum']);

        $answers = $stored?->answers;

        $minted = $tokens->mint($token->tenantId, $token->formId, $token->formVersionId);

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'completeness_percent' => $draft->completeness_percent,
                'client_submission_uuid' => $draft->client_submission_uuid,
                'form_version_id' => $draft->form_version_id,
                'answers' => is_array($answers) && $answers !== [] ? $answers : (object) [],
                // Increment P3a — the resuming device's opening lost-update baseline, so its first save can
                // be told apart from a save based on answers another device has since replaced. Legitimately
                // null for a draft stored before the checksum column existed; a null base compares equal to a
                // null stored value, so such a draft resumes and saves exactly as it did before.
                'content_checksum' => $stored?->answers_content_checksum,
                // H10 — the two-tier (Dexie↔server) reconciliation needs a server-side "last saved" to break
                // newest-wins ties, and the SPA restores the exact step + locale so a resumed session comes back
                // in the language and position the respondent left it.
                'last_saved_at' => optional($draft->last_saved_at)?->toIso8601String(),
                'draft_current_step' => $draft->draft_current_step,
                'locale' => $draft->locale,
                'share_token' => $minted->token,
                'share_token_expires_at' => gmdate('c', $minted->expiresAt),
            ],
        ]);
    }
}
