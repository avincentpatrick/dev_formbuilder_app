/**
 * String-literal union mirrors of the PHP backed enums the engine relies on. Using the raw backed VALUES
 * (not TS `enum`s) keeps the mirror JSON-native — a golden vector's `"eq"` / `"required_if"` string maps
 * straight onto these types with no conversion layer, exactly as PHP's `Enum::from()` does in the runner.
 */

/** app/Enums/ComparisonOperator.php (the `gte`/`lte` cases added with grammar v2.0). */
export type ComparisonOperator = 'gt' | 'lt' | 'gte' | 'lte' | 'eq' | 'neq' | 'is_null' | 'contains';

/** app/Services/Expressions/ArithmeticOperator.php — the four binary arithmetic operators (grammar v2.0). */
export type ArithmeticOperator = 'add' | 'sub' | 'mul' | 'div';

/** app/Enums/LogicOperator.php */
export type LogicOperator = 'and' | 'or';

/** app/Enums/ValidationRuleType.php (the 13 cases in 3 families; `at_least_field` / `at_most_field` added by M178). */
export type ValidationRuleType =
    | 'min_value'
    | 'max_value'
    | 'min_length'
    | 'max_length'
    | 'pattern'
    | 'required_if'
    | 'required_with'
    | 'skip_if'
    | 'skip_with'
    | 'greater_than_field'
    | 'less_than_field'
    | 'at_least_field'
    | 'at_most_field';

/** app/Enums/RequiredMode.php */
export type RequiredMode = 'required' | 'optional' | 'conditional';

/** app/Services/Expressions/LiteralKind.php — a literal is a number or a raw string. */
export type LiteralKind = 'number' | 'string';

/** app/Services/Expressions/NodeKind.php — a node yields a plain value or a boolean. */
export type NodeKind = 'value' | 'boolean';

/** The four field-comparison rules — those that ORDER the owner's answer against another question's. */
export type FieldComparisonRuleType = 'greater_than_field' | 'less_than_field' | 'at_least_field' | 'at_most_field';

/**
 * The comparison each field-comparison rule makes — the mirror of `ValidationRuleType::relatedComparison()` for
 * these four (M178). The lowering reads it, so "at least" cannot lower to a strict `>`.
 */
export const FIELD_COMPARISON_OPERATOR: Readonly<Record<FieldComparisonRuleType, ComparisonOperator>> = {
    greater_than_field: 'gt',
    less_than_field: 'lt',
    at_least_field: 'gt',
    at_most_field: 'lte',
};
