<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Http\Requests\Attachments\StoreAttachmentRequest;
use App\Http\Requests\Ocr\StoreOcrScanRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Ocr\OcrScanService;
use App\Support\Api\ApiErrorResponse;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Http\JsonResponse;

/**
 * The guest media write path (Increment G6): a respondent's upload from the public form runtime
 * (`POST /api/v1/public/f/{shareToken}/attachments`, UNAUTHENTICATED; tenant resolved from the verified
 * share token, `throttle:guest`). Mirrors {@see GuestSubmissionController}'s guards — guest access must be
 * enabled and the token must target the current published version — then hands the file to the same
 * {@see AttachmentStorageService} the authenticated channel uses, with a null uploader.
 *
 * No guest read-back of an upload: a `blob:` URL previews it. (A note's image is {@see GuestContentImageController}.)
 *
 * A filled paper copy is the second guest upload here (M165, `D106`, {@see storePaperCopy()}) — on this class
 * because a new controller is a new `use` line in `routes/api.php`, which moves the lines documents cite.
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

        if (! $form->allowsGuestAccess()) {
            return ApiErrorResponse::make(403, 'guest_disabled', 'Guest submissions are disabled for this form.');
        }

        if ($form->current_published_version_id !== $token->formVersionId) {
            return ApiErrorResponse::make(409, 'form_updated', 'This form has been updated. Please reload and try again.');
        }

        $version = FormVersion::query()->whereKey($token->formVersionId)->firstOrFail();

        $attachment = $service->store($request->uploadedFile(), $version, $request->fieldKey(), null);

        return response()->json(['data' => $attachment->toAnswerRef()], 201);
    }

    /**
     * Upload a filled paper copy of the form — photos or a scan of its printed pages — for staff to check.
     *
     * The pages are read automatically and wait in the form's scans list until a staff member reviews and saves
     * them as a response. The answer carries only a reference to quote: nothing that was read is ever sent back.
     * The token's version is not checked against the current one, because the paper names its own version and
     * paper printed before a republish is still a valid copy.
     *
     * @unauthenticated
     */
    public function storePaperCopy(StoreOcrScanRequest $request, OcrScanService $scans): JsonResponse
    {
        $token = $this->shareToken($request);

        $form = Form::query()->whereKey($token->formId)->firstOrFail();

        $scan = $scans->storeFromGuest($form, $request->pages());

        return response()->json(['data' => ['reference' => SubmissionReference::format((string) $scan->reference)]], 202);
    }
}
