<?php

declare(strict_types=1);

namespace App\Exceptions\Submissions;

use RuntimeException;

/**
 * A Submission Pipeline STATE/integrity violation that is not a per-field validation failure
 * (technical-architecture.md §4.1 Stage 2) — e.g. an attempt to submit against a version that is not the
 * currently published one. Distinct from {@see SubmissionValidationException}, which carries field-level
 * structural/semantic errors. No `render()`: HTTP mapping is centralised in bootstrap/app.php by the
 * consumer increment (F4b), mirroring app/Exceptions/Forms/*.
 */
final class SubmissionException extends RuntimeException
{
    public static function versionNotPublished(): self
    {
        return new self('A submission can only be created against a published form version.');
    }
}
