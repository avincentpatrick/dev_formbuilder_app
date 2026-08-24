<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Http\Requests\Public\GuestSubmissionRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Services\Submissions\ClientUuidResolver;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Api\ApiErrorResponse;
use App\Support\Guest\GuestShareToken;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Http\JsonResponse;

/**
 * The guest submission channel (Increment F5) — the second ingest channel into the single
 * {@see SubmissionPipeline}, after manual encoding (F4b). Tenant context is set from the verified share
 * token; this controller is a thin channel adapter that builds a `source = guest` {@see SubmissionPayload}
 * (no respondent user; the guest provenance captured at submit time) and hands it to the pipeline, which is
 * unchanged. Structural/semantic failures bubble as a {@see SubmissionValidationException}
 * to the central 422 envelope; a `422 submission_invalid` body is returned exactly as the manual channel's is.
 *
 * Two guest-only guards run before the pipeline: `allow_guest_submissions` is re-checked (the revocation
 * lever), and a token minted against a version the form has since moved past (a republish) is rejected with
 * `409 form_updated` so the SPA re-mints and re-renders — the shared pipeline is never relaxed to accept a
 * non-current version.
 *
 * DRAFT-AWARE (Increment H9b): if the request's `client_submission_uuid` already has a saved GUEST draft on
 * THIS TOKEN'S FORM (the M11 scoping — see existingDraft()), the
 * finalize goes through {@see SubmissionDraftService::promote()} — never {@see SubmissionPipeline::submit()} —
 * honouring the H9a invariant (submit() must never see a same-uuid draft, or it would return the draft row as
 * an idempotent no-op and the response would never finalize). The final edits are captured with one
 * `saveDraft()` before promotion; a draft is pinned to its own version, so the current-version guard applies
 * only to a fresh, never-drafted submission.
 */
final class GuestSubmissionController extends Controller
{
    use ReadsGuestShareToken;

    /**
     * Submit a guest response for a share token through the unified submission pipeline (or, if the response
     * was saved as a draft, promote that draft).
     *
     * @unauthenticated
     */
    public function store(
        GuestSubmissionRequest $request,
        SubmissionPipeline $pipeline,
        SubmissionDraftService $drafts,
        GuestShareTokenService $tokens,
    ): JsonResponse {
        $token = $this->shareToken($request);

        $form = Form::query()->whereKey($token->formId)->firstOrFail();

        if (! $form->allow_guest_submissions) {
            return ApiErrorResponse::make(403, 'guest_disabled', 'Guest submissions are disabled for this form.');
        }

        $version = FormVersion::query()->whereKey($token->formVersionId)->firstOrFail();
        $payload = $this->payload($request, $version, $token, $tokens);

        $draft = $this->existingDraft($request->clientSubmissionUuid(), $token->formId);

        if ($draft !== null) {
            // Capture any final edits (Stage-1), then finalize the SAME row via promote() (full Stage-3, in
            // place). No form_updated check: the draft is pinned to its own version and promote() re-asserts it.
            //
            // ⚠️ THIS SAVE IS THE SHARPEST INSTANCE OF THE P3a LOST UPDATE, WHICH IS WHY THE PAYLOAD CARRIES A
            // BASELINE. It is a whole-document replace like any other draft save, but a promotion follows
            // immediately — so a stale device would not merely overwrite another device's answers, it would
            // FINALIZE the row with its own stale copy, and no later save can undo that. A mismatch throws
            // SubmissionConflictException, which bootstrap/app.php renders as 409 `draft_conflict`, and the
            // guest outbox parks the row for the existing review-and-resubmit UX exactly as it does for the
            // content 409.
            $drafts->saveDraft($payload);
            $result = $drafts->promote($draft);
        } else {
            if ($form->current_published_version_id !== $token->formVersionId) {
                return ApiErrorResponse::make(409, 'form_updated', 'This form has been updated. Please reload and try again.');
            }

            $result = $pipeline->submit($payload);
        }

        return response()->json([
            'data' => [
                'id' => $result->submission->id,
                // Increment J2e — the short handle, so the confirmation screen prints the code the TENANT can
                // actually find rather than one derived client-side that is stored nowhere. Formatted at this
                // boundary: the client never spells the grouping.
                //
                // Discloses nothing new — this same unauthenticated caller already receives the full uuid on
                // the line above, and a reference is strictly less information. ⚠️ It is a display handle and
                // never a credential: no route may resolve a submission by reference alone, which
                // `SubmissionReferenceDisclosureTest` asserts structurally.
                'reference' => SubmissionReference::format($result->submission->reference),
                'status' => $result->submission->status->value,
            ],
        ], $result->created ? 201 : 200);
    }

    /**
     * The draft this request's uuid names, or null if this is a fresh submission.
     *
     * ⚠️ SCOPED TO THE TOKEN'S FORM AND TO A GUEST ROW SINCE M11, AND BOTH HALVES ARE AUTHORIZATION. This
     * used to filter on uuid + status alone. The share token fixes the FORM and the uuid arrives in the
     * BODY, so those are two independent inputs: a guest holding form A's link could name a uuid from form
     * B in the same tenant and reach `saveDraft()` against a row that was never theirs — which, because the
     * uuid is unique per TENANT rather than per form, could not even be written and surfaced as a
     * repeatable unauthenticated 500. `respondent_user_id IS NULL` is the second half: a member's own draft
     * on this very form is not a guest's to promote either. {@see ClientUuidResolver::resolve()}.
     *
     * ⚠️ AND A FINALIZED OWN-ROW STILL RETURNS NULL, WHICH IS THE H9a INVARIANT RATHER THAN AN OMISSION.
     * The status test is what routes a replayed submit back through the pipeline, where Stage 2b answers it
     * as an idempotent 200; `promote()` would be a no-op that returns `created: false` without ever
     * comparing content.
     */
    private function existingDraft(?string $clientSubmissionUuid, string $formId): ?Submission
    {
        if ($clientSubmissionUuid === null) {
            return null;
        }

        $existing = ClientUuidResolver::resolve($clientSubmissionUuid, $formId, null);

        return $existing?->status === SubmissionStatus::Draft ? $existing : null;
    }

    private function payload(
        GuestSubmissionRequest $request,
        FormVersion $version,
        GuestShareToken $token,
        GuestShareTokenService $tokens,
    ): SubmissionPayload {
        return new SubmissionPayload(
            version: $version,
            answers: $request->answers(),
            source: SubmissionSource::Guest,
            respondentUserId: null,
            clientSubmissionUuid: $request->clientSubmissionUuid(),
            locale: $request->localeInput(),
            guestToken: $tokens->fingerprint($token->rawToken),
            guestIp: $request->ip(),
            guestUserAgent: $request->userAgent(),
            guestContactEmail: $request->guestContactEmail(),
            deviceId: $request->deviceId(),
            appVersion: $request->appVersion(),
            // Increment P3a — only consumed by the DRAFT branch below (saveDraft's update path); the
            // pipeline's own submit() ignores both fields, so setting them here is safe for either branch.
            checkBaseline: $request->claimsBaseline(),
            baseContentChecksum: $request->baseContentChecksum(),
        );
    }
}
