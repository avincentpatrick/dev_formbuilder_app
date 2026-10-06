<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachments\StoreAttachmentRequest;
use App\Http\Requests\Forms\StoreFormChoiceListRequest;
use App\Http\Requests\Forms\StoreFormContentImageRequest;
use App\Http\Requests\Forms\StoreFormReferenceFileRequest;
use App\Http\Requests\Forms\UpdateFormReferenceFileRequest;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\FormReferenceFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
 *
 * Since M129 it also takes a form author's image for a note's content (`POST /forms/{form}/content-images`, gated
 * `can:update,form` — `R-f0c5b682`). The image belongs to the FORM, not a version (`D58` = B), and staff read it
 * back through the same `show()`, under `AttachmentPolicy`'s `FormContentImage` arm.
 *
 * Since M132 it also keeps a form's reference files (`/forms/{form}/reference-files`, gated `can:update,form` —
 * `R-bf49e4c1`): list, attach, rename and remove on the draft, through `FormReferenceFileService`.
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

    /**
     * 201 with what the editor needs to show the image at once: its id (what the block stores), where staff read it,
     * and whether it has passed its virus check — until it has, `show()` answers 409 and the editor says "checking".
     */
    public function storeFormContentImage(StoreFormContentImageRequest $request, Form $form, AttachmentStorageService $service): JsonResponse
    {
        $attachment = $service->storeFormContentImage(
            $request->uploadedImage(),
            (string) $form->tenant_id,
            (string) $form->id,
            (string) $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['data' => [
            'id' => $attachment->id,
            'url' => route('attachments.show', $attachment, false),
            'servable' => $attachment->fresh()?->virus_scan_status->servable() ?? false,
            'width' => $attachment->width,
            'height' => $attachment->height,
        ]], 201);
    }

    /**
     * The draft's reference files (M132, `R-bf49e4c1`), as the settings panel lists them — read again while a new
     * file's virus check is still running, so the panel can say when it is ready.
     */
    public function indexReferenceFiles(Form $form, FormReferenceFileService $service): JsonResponse
    {
        $draft = $form->draft_version_id === null ? null : FormVersion::query()->whereKey($form->draft_version_id)->first();

        return response()->json(['data' => $draft === null ? [] : $service->forAuthor($draft)]);
    }

    /** Attach one reference file to the form's draft: 201 with the file as the panel shows it. */
    public function storeReferenceFile(StoreFormReferenceFileRequest $request, Form $form, FormReferenceFileService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $service->attach($form, $request->uploadedReferenceFile(), $user)], 201);
    }

    /** Rename one of the draft's reference files, addressed by its attachment id (stable across publishes). */
    public function updateReferenceFile(UpdateFormReferenceFileRequest $request, Form $form, string $file, FormReferenceFileService $service): JsonResponse
    {
        return response()->json(['data' => $service->relabel($form, $file, $request->referenceLabel())]);
    }

    /** Remove one of the draft's reference files. A published version that shows it keeps it. */
    public function destroyReferenceFile(Form $form, string $file, FormReferenceFileService $service): Response
    {
        $service->detach($form, $file);

        return response()->noContent();
    }

    /**
     * The draft's choice lists (M141, `R-f69aab42`, `D95`), as the settings panel and the cascade level editor list
     * them: each list's name, file, row count and columns.
     */
    public function indexChoiceLists(Form $form, FormChoiceListService $service): JsonResponse
    {
        return response()->json(['data' => $service->forAuthor($form)]);
    }

    /** Add a choice list from a CSV file, or replace the list of the same name: 201 with the list. */
    public function storeChoiceList(StoreFormChoiceListRequest $request, Form $form, FormChoiceListService $service): JsonResponse
    {
        return response()->json(['data' => $service->upload($form, $request->uploadedList())], 201);
    }

    /** Remove a choice list from the draft. A published version that uses it keeps it. */
    public function destroyChoiceList(Form $form, string $list, FormChoiceListService $service): Response
    {
        $service->remove($form, $list);

        return response()->noContent();
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
