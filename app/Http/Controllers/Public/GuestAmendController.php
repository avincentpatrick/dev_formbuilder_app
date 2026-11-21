<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Exceptions\Submissions\SubmissionEditException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EstablishGuestAmendContext;
use App\Http\Requests\Public\GuestAmendRequest;
use App\Models\SubmissionAnswer;
use App\Services\Submissions\RespondentAmendService;
use App\Support\Api\ApiErrorResponse;
use App\Support\Guest\GuestShareToken;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Change my answers" (M248, `D232`): a respondent reads, then changes, the response they sent, through the amend link
 * the thank-you page gave them or their device remembered. Tenant context and the response's id come from the verified
 * token ({@see EstablishGuestAmendContext}); every rule is {@see RespondentAmendService}'s.
 *
 * Refusals the guest page puts into words: 404 `response_not_found`; 403 `amend_not_allowed`, `form_closed` or
 * `form_not_open` (rendered in bootstrap/app.php); 409 `response_under_review` or `response_changed`; 422 for answers
 * that fail the form's own rules.
 */
final class GuestAmendController extends Controller
{
    /**
     * Read a sent response to change it.
     *
     * @unauthenticated
     */
    public function show(Request $request, RespondentAmendService $amends, GuestShareTokenService $tokens): JsonResponse
    {
        $token = $this->amendToken($request);
        $found = $amends->find($token);

        if ($found === null) {
            return ApiErrorResponse::make(404, 'response_not_found', 'This response is no longer available.');
        }

        [$form, $submission, $version] = $found;

        try {
            $amends->assertAmendable($form, $submission);
        } catch (SubmissionEditException $e) {
            return $this->refusal($e);
        }

        // The answers and their baseline in ONE read, so a change landing between two reads cannot hand this page a
        // baseline for answers it was never shown (the P3a rule the resume read follows).
        $stored = SubmissionAnswer::query()
            ->where('submission_id', $submission->id)
            ->first(['answers', 'answers_content_checksum']);
        $answers = $stored?->answers;

        // A share token for the response's OWN version, so the page renders the schema the answers belong to.
        $minted = $tokens->mint($token->tenantId, $token->formId, $version->id);

        return response()->json([
            'data' => [
                'id' => $submission->id,
                // The short reference the respondent can quote to the form's owner. A display handle, never a credential.
                'reference' => SubmissionReference::format($submission->reference),
                'form_version_id' => $version->id,
                'answers' => is_array($answers) && $answers !== [] ? $answers : (object) [],
                // The baseline the change must carry back, so a change made meanwhile on another device is not overwritten.
                'content_checksum' => $stored?->answers_content_checksum,
                // The clock the form's date rules are checked against: the day the response was sent, as the server checks it.
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'locale' => $submission->locale,
                // When the form stops taking changes, or null when it has no closing date.
                'amend_until' => $form->closes_at?->toIso8601String(),
                // When the respondent last changed it, or null when they never did.
                'amended_at' => $submission->respondent_amended_at?->toIso8601String(),
                'share_token' => $minted->token,
                'share_token_expires_at' => gmdate('c', $minted->expiresAt),
            ],
        ]);
    }

    /**
     * Change a sent response.
     *
     * @unauthenticated
     */
    public function update(GuestAmendRequest $request, RespondentAmendService $amends): JsonResponse
    {
        $found = $amends->find($this->amendToken($request));

        if ($found === null) {
            return ApiErrorResponse::make(404, 'response_not_found', 'This response is no longer available.');
        }

        [$form, $submission, $version] = $found;

        try {
            $amended = $amends->amend($form, $submission, $version, $request->answers(), $request->baseContentChecksum());
        } catch (SubmissionEditException $e) {
            return $this->refusal($e);
        }

        $stored = SubmissionAnswer::query()->where('submission_id', $amended->id)->first(['answers_content_checksum']);

        return response()->json([
            'data' => [
                'id' => $amended->id,
                'reference' => SubmissionReference::format($amended->reference),
                'status' => $amended->status->value,
                // The baseline for a further change from this page.
                'content_checksum' => $stored?->answers_content_checksum,
                'amended_at' => $amended->respondent_amended_at?->toIso8601String(),
            ],
        ]);
    }

    private function refusal(SubmissionEditException $e): JsonResponse
    {
        return $e->reason() === 'concurrently_modified'
            ? ApiErrorResponse::make(409, 'response_changed', 'These answers were changed somewhere else. Open the link again to see the latest answers.')
            : ApiErrorResponse::make(409, 'response_under_review', $e->getMessage());
    }

    private function amendToken(Request $request): GuestShareToken
    {
        $token = $request->attributes->get('guestAmendToken');

        assert($token instanceof GuestShareToken && $token->submissionId !== null);

        return $token;
    }
}
