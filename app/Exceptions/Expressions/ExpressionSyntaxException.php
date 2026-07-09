<?php

declare(strict_types=1);

namespace App\Exceptions\Expressions;

/**
 * A compile/parse-time expression error (technical-architecture.md §4.3) — an authoring mistake, surfaced
 * at build/publish time, never mid-submission. Each factory pins a stable `slug()` the golden-file
 * `expected_error` vectors assert against. The publish consumer (F3) re-wraps these into a
 * PublishValidationException so the existing web-toast / api-422 surfacings fire.
 */
final class ExpressionSyntaxException extends ExpressionException
{
    public static function emptyExpression(): self
    {
        return new self('The expression is empty.', 'empty_expression');
    }

    public static function expressionTooLong(int $length): self
    {
        return new self("The expression is too long ({$length}).", 'expression_too_long');
    }

    public static function tooDeeplyNested(int $depth): self
    {
        return new self("The expression is nested too deeply ({$depth}).", 'too_deeply_nested');
    }

    public static function malformedReference(string $raw): self
    {
        return new self("Malformed field reference “{$raw}”.", 'malformed_reference');
    }

    public static function unterminatedString(int $position): self
    {
        return new self("Unterminated string literal at position {$position}.", 'unterminated_string');
    }

    public static function unexpectedToken(string $lexeme, int $position): self
    {
        return new self("Unexpected token “{$lexeme}” at position {$position}.", 'unexpected_token');
    }

    public static function unknownFunction(string $name): self
    {
        return new self("Unknown function “{$name}”.", 'unknown_function');
    }

    public static function arityMismatch(string $name, int $expected, int $got): self
    {
        return new self("Function “{$name}” expects {$expected} argument(s), got {$got}.", 'arity_mismatch');
    }

    public static function nonValueOperand(string $context): self
    {
        return new self("A boolean-valued sub-expression cannot be used as {$context}.", 'non_value_operand');
    }

    public static function unknownFieldReference(string $key, ?string $fieldKey): self
    {
        return (new self("Expression references an unknown field “{$key}”.", 'unknown_field_reference'))
            ->withField($fieldKey);
    }

    public static function selfReferenceInNonConstraint(?string $fieldKey): self
    {
        return (new self('The “.” self-reference is only valid in a constraint expression.', 'self_reference_in_non_constraint'))
            ->withField($fieldKey);
    }
}
