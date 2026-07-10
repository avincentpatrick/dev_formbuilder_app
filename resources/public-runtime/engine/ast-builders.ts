/**
 * Static AST-construction helpers — the mirror of `app/Services/Expressions/AstBuilders.php`
 * (technical-architecture.md §4.3 §6.7). Producing the SAME AST a parsed expression would yield means a
 * structured rule and its `${…}` string equivalent evaluate identically: `required_if age eq 18` lowers to
 * the same tree as `${age} = 18`, so a Number literal lands in Eq rule 5 (numeric) and a String literal in
 * Eq rule 4 (string).
 */

import { isNumericLike } from './coercion';
import {
    fieldRef,
    numberLiteral,
    stringLiteral,
    type ComparisonNode,
    type FunctionCallNode,
    type LiteralNode,
    type NotNode,
} from './ast';
import type { ComparisonOperator } from './enums';

export function fieldGt(fieldKey: string, relatedKey: string): ComparisonNode {
    return { type: 'comparison', op: 'gt', left: fieldRef(fieldKey), right: fieldRef(relatedKey) };
}

export function fieldLt(fieldKey: string, relatedKey: string): ComparisonNode {
    return { type: 'comparison', op: 'lt', left: fieldRef(fieldKey), right: fieldRef(relatedKey) };
}

/** IsNull ≡ "field equals the empty string" (Eq rule 1/2 → isEmpty). */
export function isNull(fieldKey: string): ComparisonNode {
    return { type: 'comparison', op: 'eq', left: fieldRef(fieldKey), right: stringLiteral('') };
}

/** The internal `contains` node (array membership, else scalar substring) — never parseable. */
export function contains(fieldKey: string, value: string): FunctionCallNode {
    return { type: 'call', name: 'contains', args: [fieldRef(fieldKey), stringLiteral(value)] };
}

/** The literal a raw structured `rule_value` token would parse to (Number if numeric-like, else String). */
export function literalFor(ruleValue: string): LiteralNode {
    return isNumericLike(ruleValue) ? numberLiteral(Number(ruleValue)) : stringLiteral(ruleValue);
}

/** A field-vs-literal comparison for the conditional family; `op ∈ {eq, neq, gt, lt}` only. */
export function comparison(op: ComparisonOperator, fieldKey: string, ruleValue: string): ComparisonNode {
    return { type: 'comparison', op, left: fieldRef(fieldKey), right: literalFor(ruleValue) };
}

/** "field is answered" — `not(isNull(field))`; the default condition for required_with / skip_with. */
export function isNotNull(fieldKey: string): NotNode {
    return { type: 'not', operand: isNull(fieldKey) };
}
