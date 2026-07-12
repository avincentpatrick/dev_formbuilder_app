<?php

declare(strict_types=1);

namespace App\Exceptions\Forms;

use App\Exceptions\Expressions\ExpressionException;
use RuntimeException;

/**
 * A structural pre-publish validation failure (form-versioning-schema-migration.md §4). The publish is
 * refused and the SPECIFIC violation is surfaced (not a generic failure) so the builder UI can point at
 * the offending field/section. Distinct from an authorization failure (403 from FormPolicy) and from a
 * lifecycle-rule violation ({@see FormException}).
 */
final class PublishValidationException extends RuntimeException
{
    public static function validationReferencesForeignVersion(string $fieldKey): self
    {
        return new self("The validation rule on “{$fieldKey}” references a field from a different version.");
    }

    public static function sectionBelongsToForeignVersion(string $fieldKey): self
    {
        return new self("The field “{$fieldKey}” is placed in a section that belongs to a different version.");
    }

    public static function queryableFieldMissingType(string $fieldKey): self
    {
        return new self("The queryable field “{$fieldKey}” must declare an indexed data type before publishing.");
    }

    /**
     * An authored `relevant`/`constraint` expression that will not parse or references an unknown field
     * (F3's publish-time expression gate). `$detail` is the wrapped {@see ExpressionException::slug()}.
     */
    public static function expressionInvalid(?string $fieldKey, string $detail): self
    {
        $where = $fieldKey !== null ? "on “{$fieldKey}” " : '';

        return new self("The expression {$where}is invalid ({$detail}).");
    }

    /** A structured rule whose `rule_value` cannot be used at submission time (bad regex / non-numeric threshold). */
    public static function ruleValueInvalid(string $fieldKey, string $detail): self
    {
        return new self("The validation rule on “{$fieldKey}” is invalid ({$detail}).");
    }

    /** A choice field (Increment G4a) with no options or duplicate option values — unanswerable / ambiguous. */
    public static function choiceOptionsInvalid(string $fieldKey, string $detail): self
    {
        return new self("The choices on “{$fieldKey}” are invalid ({$detail}).");
    }

    /** A cascading-select field (Increment G4a) whose level/option hierarchy does not resolve. */
    public static function cascadingConfigInvalid(string $fieldKey, string $detail): self
    {
        return new self("The cascading choices on “{$fieldKey}” are invalid ({$detail}).");
    }

    /** A composite grid field (Increment G4b: matrix / likert_matrix) whose row/column/cell config is invalid. */
    public static function matrixConfigInvalid(string $fieldKey, string $detail): self
    {
        return new self("The grid on “{$fieldKey}” is invalid ({$detail}).");
    }

    /**
     * A composite grid field (Increment G4b) nested in a repeatable section — unsupported, and a value-shape
     * drift hazard (a grid inside a repeat instance is never routed through the composite pass).
     */
    public static function compositeInRepeatableSection(string $fieldKey): self
    {
        return new self("The grid field “{$fieldKey}” cannot be placed inside a repeatable section.");
    }

    /**
     * An expression references a composite grid field (Increment G4b). Grid values are object-shaped and
     * are never valid scalar operands (they would drift between the PHP and TS engines), so any reference is
     * refused at publish rather than silently coercing to `false`.
     */
    public static function expressionReferencesComposite(string $ownerKey, string $compositeKey): self
    {
        return new self("The expression on “{$ownerKey}” references the grid field “{$compositeKey}”, which cannot be used in an expression.");
    }
}
