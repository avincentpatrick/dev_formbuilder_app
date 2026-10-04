<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\AttachmentKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\FormVersionReferenceFile;
use App\Support\Api\ApiErrorResponse;
use App\Support\Attachments\InlineAttachmentResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The guest read of a note's content image (M130, `R-c9f50df2`) — `GET /api/v1/public/content-images/{shareToken}/{image}`,
 * UNAUTHENTICATED; the tenant is resolved from the verified share token, as on every guest route.
 *
 * An image is served only when it is one the token's own published version SHOWS: a `form_content_image` owned by
 * the token's form (`D58` = B, the form owns it), named by a note's content in that version's frozen snapshot, and
 * past its virus check. Every other case is the same enveloped 404 — another form's image, an image the version
 * does not show, a draft's image, an unchecked or refused one, a form whose guest access is off — so the route
 * says nothing about what exists. A dead or expired token never reaches here: `EstablishGuestTenantContext`
 * refuses it first, with its own status.
 *
 * ⚠️ OFF `throttle:guest` AND OFF THE `f/` PREFIX, BOTH DELIBERATELY — the reasons are at the route.
 *
 * Since M132 it also serves a form's reference files ({@see self::referenceFile()}), on the same boundary.
 */
final class GuestContentImageController extends Controller
{
    use ReadsGuestShareToken;

    /**
     * The one query that answers "does this version show this image": a note's content, in the frozen snapshot,
     * holding an image block with this id. Versions are immutable, so the answer never changes for a version.
     */
    private const SHOWN_BY_VERSION = <<<'SQL'
        EXISTS (
            SELECT 1
            FROM jsonb_array_elements(schema_snapshot -> 'fields') AS field,
                 jsonb_array_elements(
                     CASE WHEN jsonb_typeof(field -> 'config' -> 'content') = 'array'
                          THEN field -> 'config' -> 'content'
                          ELSE '[]'::jsonb END
                 ) AS block
            WHERE field ->> 'field_type' = 'note'
              AND block ->> 'type' = 'image'
              AND block ->> 'attachment_id' = ?
        )
        SQL;

    /**
     * Read one image a note on this shared form shows.
     *
     * @unauthenticated
     */
    public function show(Request $request, string $shareToken, string $image): Response
    {
        $token = $this->shareToken($request);
        $form = Form::query()->whereKey($token->formId)->first();

        // A non-uuid id would be an invalid-input error from Postgres rather than a miss, so it is refused first.
        if ($form === null || ! $form->allow_guest_submissions || ! Str::isUuid($image)) {
            return $this->notFound();
        }

        $attachment = Attachment::query()->whereKey($image)->first();

        if ($attachment === null
            || $attachment->kind !== AttachmentKind::FormContentImage
            || $attachment->attachable_type !== 'form'
            || $attachment->attachable_id !== $form->id
            || ! $attachment->virus_scan_status->servable()) {
            return $this->notFound();
        }

        $shown = FormVersion::query()
            ->whereKey($token->formVersionId)
            ->whereRaw(self::SHOWN_BY_VERSION, [$attachment->id])
            ->exists();

        if (! $shown) {
            return $this->notFound();
        }

        $response = InlineAttachmentResponse::for($attachment);
        // An id never names different bytes, and the service worker keeps its own copy for offline use.
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        return $response;
    }

    /**
     * Read one reference file this shared form shows.
     *
     * Served only when the share token's own form version lists the file and it has passed its virus check. An image
     * is sent inline; a PDF is sent as a download. Any other request answers the same 404.
     *
     * @unauthenticated
     */
    public function referenceFile(Request $request, string $shareToken, string $file): Response
    {
        // M132 (`R-bf49e4c1`). The image read's boundary for a form's reference files: a `form_reference_file` owned
        // by the token's form, listed by the token's own version (`form_version_reference_files`, frozen with it —
        // `D61` = B), and servable. The guest page fetches it rather than navigating to it, so the service worker can
        // keep a copy once opened (`D84`). On this controller rather than its own because a new controller is a new
        // `use` line in `routes/api.php`, whose lines are cited by number. This docblock is published; keep notes here.
        $token = $this->shareToken($request);
        $form = Form::query()->whereKey($token->formId)->first();

        if ($form === null || ! $form->allow_guest_submissions || ! Str::isUuid($file)) {
            return $this->fileNotFound();
        }

        $attachment = Attachment::query()->whereKey($file)->first();

        if ($attachment === null
            || $attachment->kind !== AttachmentKind::FormReferenceFile
            || $attachment->attachable_type !== 'form'
            || $attachment->attachable_id !== $form->id
            || ! $attachment->virus_scan_status->servable()) {
            return $this->fileNotFound();
        }

        $shown = FormVersionReferenceFile::query()
            ->where('form_version_id', $token->formVersionId)
            ->where('attachment_id', $attachment->id)
            ->exists();

        if (! $shown) {
            return $this->fileNotFound();
        }

        $response = InlineAttachmentResponse::for($attachment);
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        return $response;
    }

    private function notFound(): Response
    {
        return ApiErrorResponse::make(404, 'image_not_found', 'This image is not available.');
    }

    private function fileNotFound(): Response
    {
        return ApiErrorResponse::make(404, 'file_not_found', 'This file is not available.');
    }
}
