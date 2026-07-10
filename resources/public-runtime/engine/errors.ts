/**
 * The expression error taxonomy — the mirror of `app/Exceptions/Expressions/*`. Every factory pins a
 * stable snake_case `slug` (never the human message) which the golden `expected_error` vectors assert
 * against, so the two engines must agree on WHICH error a malformed expression produces, not just that it
 * failed. `ExpressionSyntaxError` is a compile/parse-time authoring mistake; `ExpressionEvaluationError` is
 * a structurally-impossible runtime state (a server/lowering bug, not a validation outcome).
 */

/** A compile/parse-time authoring error. Mirrors ExpressionSyntaxException + its stable slugs. */
export class ExpressionSyntaxError extends Error {
    readonly slug: string;
    readonly fieldKey: string | null;

    constructor(message: string, slug: string, fieldKey: string | null = null) {
        super(message);
        this.name = 'ExpressionSyntaxError';
        this.slug = slug;
        this.fieldKey = fieldKey;
    }

    static emptyExpression(): ExpressionSyntaxError {
        return new ExpressionSyntaxError('The expression is empty.', 'empty_expression');
    }

    static expressionTooLong(length: number): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`The expression is too long (${length}).`, 'expression_too_long');
    }

    static tooDeeplyNested(depth: number): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`The expression is nested too deeply (${depth}).`, 'too_deeply_nested');
    }

    static malformedReference(raw: string): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`Malformed field reference “${raw}”.`, 'malformed_reference');
    }

    static unterminatedString(position: number): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`Unterminated string literal at position ${position}.`, 'unterminated_string');
    }

    static unexpectedToken(lexeme: string, position: number): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`Unexpected token “${lexeme}” at position ${position}.`, 'unexpected_token');
    }

    static unknownFunction(name: string): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`Unknown function “${name}”.`, 'unknown_function');
    }

    static arityMismatch(name: string, expected: number, got: number): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`Function “${name}” expects ${expected} argument(s), got ${got}.`, 'arity_mismatch');
    }

    static nonValueOperand(context: string): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`A boolean-valued sub-expression cannot be used as ${context}.`, 'non_value_operand');
    }

    static unknownFieldReference(key: string, fieldKey: string | null): ExpressionSyntaxError {
        return new ExpressionSyntaxError(`Expression references an unknown field “${key}”.`, 'unknown_field_reference', fieldKey);
    }

    static selfReferenceInNonConstraint(fieldKey: string | null): ExpressionSyntaxError {
        return new ExpressionSyntaxError('The “.” self-reference is only valid in a constraint expression.', 'self_reference_in_non_constraint', fieldKey);
    }
}

/** A runtime-integrity error — should be impossible post-parse (a lowering/data bug), never a validation result. */
export class ExpressionEvaluationError extends Error {
    readonly slug: string;

    constructor(message: string, slug: string) {
        super(message);
        this.name = 'ExpressionEvaluationError';
        this.slug = slug;
    }

    static unlowerableRuleType(label: string): ExpressionEvaluationError {
        return new ExpressionEvaluationError(`Rule type “${label}” cannot be lowered to an expression.`, 'unlowerable_rule_type');
    }

    static missingRelatedField(fieldKey: string): ExpressionEvaluationError {
        return new ExpressionEvaluationError(`Rule on field “${fieldKey}” references a missing related field.`, 'missing_related_field');
    }

    static malformedLogicGroup(rowId: string): ExpressionEvaluationError {
        return new ExpressionEvaluationError(`Logic group row “${rowId}” has no connective.`, 'malformed_logic_group');
    }

    static unevaluable(detail: string): ExpressionEvaluationError {
        return new ExpressionEvaluationError(`Unevaluable node: ${detail}.`, 'unevaluable');
    }
}
