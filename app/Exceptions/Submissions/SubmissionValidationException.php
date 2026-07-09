<?php

declare(strict_types=1);

namespace App\Exceptions\Submissions;

use App\Exceptions\Expressions\ExpressionException;
use App\Services\Validation\SemanticError;
use RuntimeException;

/**
 * A per-field Submission Pipeline failure (technical-architecture.md §4.1 Stages 1 & 3). Carries a
 * structured `fieldErrors` list — each `{field, rule, message}` — plus a stable `stage` slug
 * (`structural` | `semantic`) so the HTTP layer (F4b) can render a `422` field-error envelope without
 * parsing the message. Modelled on {@see ExpressionException}'s accessor
 * style (structured data + stable slug), not the message-only app/Exceptions/Forms/* style, because a
 * submission fails per-field, not as one lump.
 *
 * `rule` is a stable identifier the surface can branch on: a Stage-1 slug (`unknown_field`,
 * `expected_scalar`, `not_a_number`) or a Stage-3 {@see SemanticError::$rule}
 * (a ValidationRuleType value, `constraint`, or `field_required`).
 */
final class SubmissionValidationException extends RuntimeException
{
    /**
     * @param  list<array{field: string, rule: string, message: string}>  $fieldErrors
     */
    private function __construct(
        string $message,
        private readonly string $stage,
        private readonly array $fieldErrors,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<array{field: string, rule: string, message: string}>  $fieldErrors
     */
    public static function structural(array $fieldErrors): self
    {
        return new self(self::summarize($fieldErrors, 'structural'), 'structural', $fieldErrors);
    }

    /**
     * @param  list<array{field: string, rule: string, message: string}>  $fieldErrors
     */
    public static function semantic(array $fieldErrors): self
    {
        return new self(self::summarize($fieldErrors, 'semantic'), 'semantic', $fieldErrors);
    }

    /** `structural` | `semantic` — the pipeline stage that produced the errors. */
    public function stage(): string
    {
        return $this->stage;
    }

    /**
     * @return list<array{field: string, rule: string, message: string}>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * @param  list<array{field: string, rule: string, message: string}>  $fieldErrors
     */
    private static function summarize(array $fieldErrors, string $stage): string
    {
        $count = count($fieldErrors);

        return $count === 1
            ? "1 field failed {$stage} validation."
            : "{$count} fields failed {$stage} validation.";
    }
}
