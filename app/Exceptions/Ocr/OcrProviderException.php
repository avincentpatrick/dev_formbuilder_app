<?php

declare(strict_types=1);

namespace App\Exceptions\Ocr;

use RuntimeException;

/**
 * The OCR provider did not return a reading for a page (M128). Thrown by the client and caught by the
 * reading job, which turns it into the scan's own state: a RETRYABLE failure is recorded as an attempt
 * and the job releases itself, and anything else marks the scan failed with {@see $errorCode} and the
 * message below. Never rendered to a request, because none exists inside a queued job.
 *
 * Every message says what to do, because `docs/ocr-pipeline-design.md` §6 asks for "actionable guidance"
 * and a reviewer looking at a failed scan has nobody to ask.
 */
final class OcrProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** No key is configured — nothing was sent. */
    public static function notConfigured(): self
    {
        return new self('provider_not_configured', false, 'Scanning is not set up on this server yet: the reading service has no key. Ask an administrator to add it.');
    }

    /**
     * The key is valid but its Google Cloud project has no billing account — refused even inside the free
     * tier. Measured on 2026-10-03 against the platform's own key, which is why it has its own code rather
     * than reading as a generic credential failure: the fix is a billing setting, not a new key.
     */
    public static function billingDisabled(): self
    {
        return new self('provider_billing_disabled', false, 'The reading service refused the scan because billing is not enabled on its Google Cloud project. Ask an administrator to enable billing, then upload the scan again.');
    }

    public static function unauthorized(int $status): self
    {
        return new self('provider_unauthorized', false, "The reading service rejected this server's credentials (HTTP {$status}). Ask an administrator to check the key.");
    }

    /** The provider could not read the file itself — not a field, the whole page. */
    public static function unreadableFile(string $detail): self
    {
        return new self('unreadable_file', false, "This page could not be read as an image or PDF ({$detail}). Scan or photograph it again and upload the new copy.");
    }

    /** Worth trying again: rate-limited, temporarily unavailable, or no answer in time. */
    public static function unavailable(string $detail): self
    {
        return new self('provider_unavailable', true, "The reading service is temporarily unavailable ({$detail}). The scan is kept; try again shortly.");
    }
}
