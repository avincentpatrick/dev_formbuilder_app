<?php

declare(strict_types=1);

namespace App\Exceptions\Expressions;

/**
 * A runtime integrity failure of the expression engine (technical-architecture.md §4.3). The Phase-1
 * interpreter is total over data (empty/missing/type-mismatch → a defined falsy result, never an
 * exception), so these only fire on a structurally-impossible AST or a caller-contract violation in
 * structured-rule lowering — i.e. a server-side bug the client engine should never have produced. A
 * `constraint` evaluating to `false` is a validation RESULT, not one of these.
 */
final class ExpressionEvaluationException extends ExpressionException
{
    public static function unlowerableRuleType(string $ruleType): self
    {
        return new self("Rule type “{$ruleType}” cannot be lowered by the expression engine.", 'unlowerable_rule_type');
    }

    public static function missingRelatedField(string $fieldKey): self
    {
        return (new self("The field-comparison rule on “{$fieldKey}” is missing its related field.", 'missing_related_field'))
            ->withField($fieldKey);
    }

    public static function malformedLogicGroup(string $group): self
    {
        return new self("Logic group “{$group}” has a row missing its connective operator.", 'malformed_logic_group');
    }

    public static function unevaluable(string $reason): self
    {
        return new self("The expression AST is not evaluable: {$reason}.", 'unevaluable');
    }
}
