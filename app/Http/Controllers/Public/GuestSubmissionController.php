<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\SubmissionSource;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Http\Requests\Public\GuestSubmissionRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Api\ApiErrorResponse;
use App\Support\Guest\GuestShareTokenService;
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
 */
final class GuestSubmissionController extends Controller
{
    use ReadsGuestShareToken;

    /**
     * Submit a guest response for a share token through the unified submission pipeline.
     *
     * @unauthenticated
     */
    public function store(
        GuestSubmissionRequest $request,
        SubmissionPipeline $pipeline,
        GuestShareTokenService $tokens,
    ): JsonResponse {
        $token = $this->shareToken($request);

        $form = Form::query()->whereKey($token->formId)->firstOrFail();

        if (! $form->allow_guest_submissions) {
            return ApiErrorResponse::make(403, 'guest_disabled', 'Guest submissions are disabled for this form.');
        }

        if ($form->current_published_version_id !== $token->formVersionId) {
            return ApiErrorResponse::make(409, 'form_updated', 'This form has been updated. Please reload and try again.');
        }

        $version = FormVersion::query()->whereKey($token->formVersionId)->firstOrFail();

        $result = $pipeline->submit(new SubmissionPayload(
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
        ));

        return response()->json([
            'data' => [
                'id' => $result->submission->id,
                'status' => $result->submission->status->value,
            ],
        ], $result->created ? 201 : 200);
    }
}
