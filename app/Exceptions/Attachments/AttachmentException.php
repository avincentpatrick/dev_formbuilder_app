<?php

declare(strict_types=1);

namespace App\Exceptions\Attachments;

use App\Support\Api\ApiErrorResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An upload-time rejection from the attachment ingestion path (Increment G6) — a bad MIME type, an
 * over-size file, or an unknown/non-media target field. Self-rendering via {@see ApiErrorResponse} so
 * both the authenticated and the guest upload controllers stay thin channel adapters and return the same
 * `{ error: { code, message } }` envelope the rest of the API surface uses.
 */
final class AttachmentException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(422, 'attachment_too_large', "This file exceeds the field's maximum size of {$maxBytes} bytes.");
    }

    public static function mimeRejected(string $mime): self
    {
        return new self(422, 'attachment_mime_rejected', "Files of type {$mime} are not accepted for this field.");
    }

    public static function fieldNotFound(): self
    {
        return new self(422, 'attachment_field_unknown', 'The target field does not exist or does not accept media.');
    }

    public static function storeFailed(): self
    {
        return new self(500, 'attachment_store_failed', 'The file could not be stored. Please try again.');
    }

    public function render(): JsonResponse
    {
        return ApiErrorResponse::make($this->status, $this->errorCode, $this->getMessage());
    }
}
