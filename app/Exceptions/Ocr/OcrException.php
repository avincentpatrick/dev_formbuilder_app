<?php

declare(strict_types=1);

namespace App\Exceptions\Ocr;

use App\Support\Api\ApiErrorResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal at the door of the single-form OCR channel (M128) — something about the FORM or the UPLOAD
 * that means no scan is stored at all. Self-rendering through {@see ApiErrorResponse}, the
 * `AttachmentException` precedent, so the controller stays a thin adapter and the review screen reads the
 * same `{ error: { code, message } }` envelope the rest of the surface uses.
 *
 * Provider failures are NOT here. Those happen after the scan is stored, inside the reading job, where
 * there is no request to render to; they are {@see OcrProviderException} and end on the scan row.
 */
final class OcrException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** The per-form opt-in (`forms.allow_ocr_single`, `docs/ocr-pipeline-design.md` §2) is off. */
    public static function scanningOff(): self
    {
        return new self(422, 'ocr_scanning_off', 'This form does not accept scans. Turn on scanning in the form\'s settings first.');
    }

    /** The version a scan would be read against is not OCR-compatible (§2's four clauses). */
    public static function formNotEligible(): self
    {
        return new self(
            422,
            'ocr_form_not_eligible',
            // M187 (`D164`): a signature is printed on the paper but never read from a scan, so it is named here.
            'Scans of this form cannot be read automatically, because it has questions a scan cannot read (a grid, a location, a file, a signature or a repeating group). Responses must be keyed in.',
        );
    }

    /**
     * A confirmation for a scan with nothing to save (M129): still being read, or reading failed.
     *
     * ⚠️ NOT SELF-RENDERED ON THAT PATH. The confirmation is an Inertia POST from the review page, and this
     * class renders JSON; the controller catches it and answers with an errors bag instead, which is what
     * keeps the reviewer's corrections on the page (`Encode.vue` preserves state only when errors arrive).
     */
    public static function notReviewable(): self
    {
        return new self(409, 'ocr_scan_not_reviewable', 'This scan has not been read, so there is nothing to save yet.');
    }

    /**
     * A guest upload (M165, `D106`) to a form whose public link does not take a paper copy: the Scanning switch, the
     * guest-upload switch or guest access is off, or the current version cannot be read. One neutral refusal for all
     * of them, because a stranger is owed no account of the form's settings.
     */
    public static function guestUploadOff(): self
    {
        return new self(403, 'paper_upload_unavailable', 'This form is not taking paper copies right now.');
    }

    /** The form has taken its daily number of paper copies from the public link (`forms.guest_ocr_daily_cap`). */
    public static function guestDailyCapReached(): self
    {
        return new self(429, 'paper_upload_limit_reached', 'This form has received as many paper copies as it can take today. Please try again tomorrow.');
    }

    /**
     * A paper token (M166, `D116`) that is forged, for another form, or names no guest scan here. One answer for all of
     * them: a stranger probing tokens learns nothing about which part failed.
     */
    public static function paperTokenInvalid(): self
    {
        return new self(403, 'paper_token_invalid', 'This paper copy cannot be opened from here. Please send it again.');
    }

    /** A paper token past its lifetime (`guest.paper_token.ttl`). */
    public static function paperTokenExpired(): self
    {
        return new self(403, 'paper_token_expired', 'This paper copy was sent too long ago to be checked here. Our staff will check it for you.');
    }

    public static function pdfNotAlone(): self
    {
        return new self(422, 'ocr_pdf_not_alone', 'Upload a PDF scan on its own. Photos of one form can be uploaded together, one photo per page.');
    }

    public static function tooManyPages(int $max): self
    {
        return new self(422, 'ocr_too_many_pages', "A scan can have at most {$max} pages.");
    }

    public static function scanTooLarge(int $maxBytes): self
    {
        $megabytes = intdiv($maxBytes, 1_000_000);

        return new self(422, 'ocr_scan_too_large', "The pages of one scan can add up to at most {$megabytes} MB.");
    }

    public function render(): JsonResponse
    {
        return ApiErrorResponse::make($this->status, $this->errorCode, $this->getMessage());
    }
}
