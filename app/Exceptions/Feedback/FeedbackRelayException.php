<?php

declare(strict_types=1);

namespace App\Exceptions\Feedback;

use RuntimeException;

/**
 * The relay refused a batch before writing any of it (M179, `R-e727ca94`; ADR-0023 §D7). `code()` is the support
 * API's machine-readable error code; the platform API answers 422 with it.
 */
final class FeedbackRelayException extends RuntimeException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function unknownTenant(string $tenant): self
    {
        return new self("No workspace is called \"{$tenant}\".", 'unknown_tenant');
    }

    public static function unknownReporter(): self
    {
        return new self('The reporter must be an active member of that workspace.', 'unknown_reporter');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
