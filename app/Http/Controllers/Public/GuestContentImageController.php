<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\AttachmentKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
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

    // M138 (`R-10c9e1bc`, `D92` = A). M132 served a form's listed reference files to anyone holding its share token;
    // the user's staging smoke test found that no tool they compared offers attached files to respondents — Kobo and
    // ODK use an attached file only INSIDE the form — and `D92` hides them now and rebuilds them Kobo-style later.
    // ⛔ THE ROUTE STAYS REGISTERED, REFUSING EVERYTHING, ON PURPOSE: `AppServiceProvider` throws if its path disappears,
    // `sw.ts` and `ServiceWorkerCachePrefixRouteTest` pin its prefix, and the rebuild will serve form media through a
    // guest route again. A device that cached a schema before M138 may still ask for a file it listed; it gets this 404.
    // These notes sit outside the docblock and the method body because Scramble publishes both.
    /**
     * A reference file of a shared form — no longer served to respondents.
     *
     * Every request answers 404. Reference files are notes for the people who build the form, not files a respondent
     * downloads.
     *
     * @unauthenticated
     */
    public function referenceFile(Request $request, string $shareToken, string $file): Response
    {
        return $this->fileNotFound();
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
