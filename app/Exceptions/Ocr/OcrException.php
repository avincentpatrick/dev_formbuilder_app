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
            'Scans of this form cannot be read automatically, because it has questions paper cannot carry (a grid, a location, a file or a repeating group). Responses must be keyed in.',
        );
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
