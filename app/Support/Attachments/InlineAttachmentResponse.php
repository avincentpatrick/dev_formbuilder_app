<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one stored file for a page that has already decided who may see it (M129 — the scan review's page
 * images, served by the scan's own route rather than `GET /attachments/{attachment}`).
 *
 * The rules are `AttachmentController::show()`'s: nothing until the virus check has passed (409), `nosniff`
 * always, and inline only for a raster image — a PDF, and an SVG above all, is sent as a download so it can
 * never run in the tenant's origin. ⚠️ A SECOND COPY OF THOSE RULES, AND THAT IS A FILED ROW: the scan route
 * keeps the attachment policy and controller with one item of `M129`, and folding `show()` onto this helper
 * is the remedy recorded there.
 */
final class InlineAttachmentResponse
{
    public static function for(Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->virus_scan_status->servable(), 409, 'This file is not yet available.');

        $mime = $attachment->mime_type ?? 'application/octet-stream';
        $inline = $mime !== 'image/svg+xml' && str_starts_with($mime, 'image/');

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_filename,
            ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff'],
            $inline ? 'inline' : 'attachment',
        );
    }
}
