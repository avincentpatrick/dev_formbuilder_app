<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Http\Requests\Attachments\StoreAttachmentRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Attachments\AttachmentStorageService;
use App\Support\Api\ApiErrorResponse;
use Illuminate\Http\JsonResponse;

/**
 * The guest media write path (Increment G6): a respondent's upload from the public form runtime
 * (`POST /api/v1/public/f/{shareToken}/attachments`, UNAUTHENTICATED; tenant resolved from the verified
 * share token, `throttle:guest`). Mirrors {@see GuestSubmissionController}'s guards — guest access must be
 * enabled and the token must target the current published version — then hands the file to the same
 * {@see AttachmentStorageService} the authenticated channel uses, with a null uploader.
 *
 * There is no guest read-back: a just-captured file previews from a local `blob:` URL, so no server round-trip.
 */
final class GuestAttachmentController extends Controller
{
    use ReadsGuestShareToken;

    /**
     * Upload a guest attachment for a share token to the form's current published version.
     *
     * @unauthenticated
     */
    public function store(StoreAttachmentRequest $request, AttachmentStorageService $service): JsonResponse
    {
        $token = $this->shareToken($request);

        $form = Form::query()->whereKey($token->formId)->firstOrFail();

        if (! $form->allow_guest_submissions) {
            return ApiErrorResponse::make(403, 'guest_disabled', 'Guest submissions are disabled for this form.');
        }

        if ($form->current_published_version_id !== $token->formVersionId) {
            return ApiErrorResponse::make(409, 'form_updated', 'This form has been updated. Please reload and try again.');
        }

        $version = FormVersion::query()->whereKey($token->formVersionId)->firstOrFail();

        $attachment = $service->store($request->uploadedFile(), $version, $request->fieldKey(), null);

        return response()->json(['data' => $attachment->toAnswerRef()], 201);
    }
}
