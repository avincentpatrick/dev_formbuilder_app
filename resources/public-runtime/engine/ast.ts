/**
 * The expression AST — the mirror of `app/Services/Expressions/Ast/*`. Modelled as a discriminated union
 * (rather than a class hierarchy) so nodes are plain data, but the semantics are identical to the PHP
 * `final readonly` node classes. `kind()` reproduces the PHP `NodeKind`: a literal / field-ref / self-ref /
 * arithmetic node yields a Value, as does a value-returning function call (`if`/`count`/`int`/`today`/`now`);
 * a comparison / logical / not, and the membership predicates (`selected`/`contains`), yield a Boolean. The
 * parser rejects a Boolean-kind node used as a comparison / arithmetic operand or a `selected()` first
 * argument (§3).
 */

import type { ArithmeticOperator, ComparisonOperator, LiteralKind, LogicOperator, NodeKind } from './enums';

export type LiteralNode = { type: 'literal'; literalKind: LiteralKind; value: number | string };
export type FieldReferenceNode = { type: 'field'; key: string };
export type SelfReferenceNode = { type: 'self' };
export type ComparisonNode = { type: 'comparison'; op: ComparisonOperator; left: Node; right: Node };
export type ArithmeticNode = { type: 'arithmetic'; op: ArithmeticOperator; left: Node; right: Node };
export type LogicalNode = { type: 'logical'; op: LogicOperator; left: Node; right: Node };
export type NotNode = { type: 'not'; operand: Node };
export type FunctionCallNode = { type: 'call'; name: string; args: Node[] };

export type Node =
    | LiteralNode
    | FieldReferenceNode
    | SelfReferenceNode
    | ComparisonNode
    | ArithmeticNode
    | LogicalNode
    | NotNode
    | FunctionCallNode;

/** The membership predicates yield Boolean; every value-returning function yields Value (grammar v2.0). */
const BOOLEAN_FUNCTIONS = new Set(['selected', 'contains']);

export function numberLiteral(value: number): LiteralNode {
    return { type: 'literal', literalKind: 'number', value };
}

export function stringLiteral(value: string): LiteralNode {
    return { type: 'literal', literalKind: 'string', value };
}

export function fieldRef(key: string): FieldReferenceNode {
    return { type: 'field', key };
}

/** A String literal whose value is the empty string — drives the emptiness test (Eq §6.2 rule 1/2). */
export function isEmptyStringLiteral(node: Node): boolean {
    return node.type === 'literal' && node.literalKind === 'string' && node.value === '';
}

/** Any String literal — forces string comparison in Eq (§6.2 rule 4). */
export function isStringLiteral(node: Node): boolean {
    return node.type === 'literal' && node.literalKind === 'string';
}

export function kind(node: Node): NodeKind {
    switch (node.type) {
        case 'literal':
        case 'field':
        case 'self':
        case 'arithmetic':
            return 'value';
        case 'call':
            return BOOLEAN_FUNCTIONS.has(node.name) ? 'boolean' : 'value';
        default:
            return 'boolean';
    }
}
