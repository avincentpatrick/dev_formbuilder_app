<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

use App\Services\Forms\ExpressionValidationGate;

/**
 * One resolved, DB-free validation row for import (Increment G7b). An imported `constraint` ALWAYS becomes
 * an expression-based row (docs/xlsform-interop-spec.md §2) — the `expression` column is populated and
 * `rule_type` is left null, satisfying the `form_field_validations` XOR CHECK. A `${key}` reference inside
 * the expression is plain text (never decomposed into a structured `related_form_field_id`); its resolution
 * is checked at publish by the {@see ExpressionValidationGate}, not at import.
 */
final class ValidationSpec
{
    /**
     * @param  array<string, string>|null  $errorMessageTranslations
     */
    public function __construct(
        public readonly string $expression,
        public readonly ?string $errorMessage = null,
        public readonly ?array $errorMessageTranslations = null,
        public readonly int $sequence = 0,
    ) {}
}
