<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachments\StoreAttachmentRequest;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The authenticated media write path (Increment G6): a staff member's manual-encode upload
 * (`POST /forms/{form}/attachments`, gated `can:create,Submission,form` — the same policy as the encode
 * page) and the tenant-side read-back (`GET /attachments/{attachment}`, gated `can:view,attachment`).
 * NOTHING ABOUT THAT READ-BACK IS SIGNED, and this line said it was until M34 struck the word. The
 * controls are session auth, that policy — which since M33 resolves the attachment's KIND and its
 * OWNER rather than a bare permission — and the scan-status guard below. The repository contains
 * exactly one signed URL (email verification, User.php:146) and no `temporaryUrl`,
 * `ValidateSignature` or `hasValidSignature` anywhere in `app/` or `routes/`.
 * A thin channel adapter over {@see AttachmentStorageService}; the file stages against the form's published
 * version and is re-pointed to the submission at persist. Serving is withheld until the scan status is
 * servable (an unscanned/infected file 409s regardless of permission).
 */
final class AttachmentController extends Controller
{
    public function store(StoreAttachmentRequest $request, Form $form, AttachmentStorageService $service): JsonResponse
    {
        $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

        $attachment = $service->store(
            $request->uploadedFile(),
            $version,
            $request->fieldKey(),
            (string) $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['data' => $attachment->toAnswerRef()], 201);
    }

    public function show(Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->virus_scan_status->servable(), 409, 'This file is not yet available.');

        $mime = $attachment->mime_type ?? 'application/octet-stream';

        // Only preview known-safe media inline; everything else (an HTML file, or an SVG — which executes
        // script even when correctly typed — masquerading as a `file_upload`) is forced to download so it can
        // never run in the tenant's origin. `nosniff` additionally stops the browser second-guessing the type.
        $inline = $mime !== 'image/svg+xml'
            && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/'));

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_filename,
            ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff'],
            $inline ? 'inline' : 'attachment',
        );
    }
}
