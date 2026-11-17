<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

use App\Enums\ComparisonOperator;
use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Services\Forms\ExpressionValidationGate;
use App\Services\Xlsform\XlsformExpressionTranslator;
use App\Services\Xlsform\XlsformRequiredFormula;

/**
 * One resolved, DB-free validation row for import (Increment G7b). Exactly one of {@see self::$expression} and
 * {@see self::$ruleType} is set, which is the `form_field_validations` XOR CHECK.
 *
 * An imported `constraint` is an expression row (docs/xlsform-interop-spec.md §2), checked at publish by the
 * {@see ExpressionValidationGate}, not at import. M244 adds the structured rows import now makes:
 *   - a whole `regex(., '…')` or `string-length(.) op N` (or one `and`-part of a constraint) becomes a `pattern`,
 *     `min_length` or `max_length` row ({@see XlsformExpressionTranslator::constraint()}, `D228`);
 *   - a `required` formula becomes `required_if`/`required_with` rows ({@see XlsformRequiredFormula}).
 *
 * A required row names its related question as the parsed {@see FieldSpec}, never its key, so the link survives the
 * parser renaming either key; {@see self::$group} numbers an all-of unit within one field, and the importer gives each
 * number its own `logic_group` uuid.
 */
final class ValidationSpec
{
    /**
     * @param  array<string, string>|null  $errorMessageTranslations
     */
    public function __construct(
        public readonly ?string $expression = null,
        public readonly ?string $errorMessage = null,
        public readonly ?array $errorMessageTranslations = null,
        public readonly int $sequence = 0,
        public readonly ?ValidationRuleType $ruleType = null,
        public readonly ?ComparisonOperator $operator = null,
        public readonly ?string $ruleValue = null,
        public readonly ?FieldSpec $relatedField = null,
        public readonly ?int $group = null,
        public readonly ?LogicOperator $logicOperator = null,
    ) {}

    /**
     * The same row, carrying `$message` and its translations.
     *
     * @param  array<string, string>|null  $translations
     */
    public function withMessage(?string $message, ?array $translations): self
    {
        return new self(
            expression: $this->expression,
            errorMessage: $message,
            errorMessageTranslations: $translations,
            sequence: $this->sequence,
            ruleType: $this->ruleType,
            operator: $this->operator,
            ruleValue: $this->ruleValue,
            relatedField: $this->relatedField,
            group: $this->group,
            logicOperator: $this->logicOperator,
        );
    }

    /** The same row at `$sequence`. */
    public function at(int $sequence): self
    {
        return new self(
            expression: $this->expression,
            errorMessage: $this->errorMessage,
            errorMessageTranslations: $this->errorMessageTranslations,
            sequence: $sequence,
            ruleType: $this->ruleType,
            operator: $this->operator,
            ruleValue: $this->ruleValue,
            relatedField: $this->relatedField,
            group: $this->group,
            logicOperator: $this->logicOperator,
        );
    }
}
