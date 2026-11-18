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
/**
 * M246: `${key.row.col}` (a Grid cell) or `${key.row}` (a Likert row, `column` null) — deliberately its own node, so a
 * reader that matches `type === 'field'` misses a cell instead of reading it as the whole grid.
 */
export type CellReferenceNode = { type: 'cell'; key: string; row: string; column: string | null };
/** M246: `${.col}` — this row's cell, inside a grid column's own rules. */
export type RowCellReferenceNode = { type: 'row_cell'; column: string };
export type SelfReferenceNode = { type: 'self' };
export type ComparisonNode = { type: 'comparison'; op: ComparisonOperator; left: Node; right: Node };
export type ArithmeticNode = { type: 'arithmetic'; op: ArithmeticOperator; left: Node; right: Node };
export type LogicalNode = { type: 'logical'; op: LogicOperator; left: Node; right: Node };
export type NotNode = { type: 'not'; operand: Node };
export type FunctionCallNode = { type: 'call'; name: string; args: Node[] };

export type Node =
    | LiteralNode
    | FieldReferenceNode
    | CellReferenceNode
    | RowCellReferenceNode
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
        case 'cell':
        case 'row_cell':
        case 'self':
        case 'arithmetic':
            return 'value';
        case 'call':
            return BOOLEAN_FUNCTIONS.has(node.name) ? 'boolean' : 'value';
        default:
            return 'boolean';
    }
}

/**
 * Every distinct `${key}` field reference in an AST, in first-seen order — the mirror of PHP
 * `ExpressionParser::referencedKeys()`. M180 reads it for what a calculation depends on (`calculation-order.ts`).
 */
export function referencedKeys(node: Node): string[] {
    const seen = new Set<string>();
    const walk = (n: Node): void => {
        switch (n.type) {
            case 'field':
            case 'cell': // M246: a cell names its grid's key
                seen.add(n.key);

                return;
            case 'comparison':
            case 'arithmetic':
            case 'logical':
                walk(n.left);
                walk(n.right);

                return;
            case 'not':
                walk(n.operand);

                return;
            case 'call':
                n.args.forEach(walk);

                return;
            default:
                return;
        }
    };
    walk(node);

    return [...seen];
}

/** The path of a cell reference as written inside its braces — `key.row.col` or `key.row` (M246). */
export function cellPath(node: CellReferenceNode): string {
    return node.column === null ? `${node.key}.${node.row}` : `${node.key}.${node.row}.${node.column}`;
}

function walkNodes(node: Node, visit: (n: Node) => void): void {
    visit(node);
    switch (node.type) {
        case 'comparison':
        case 'arithmetic':
        case 'logical':
            walkNodes(node.left, visit);
            walkNodes(node.right, visit);

            return;
        case 'not':
            walkNodes(node.operand, visit);

            return;
        case 'call':
            node.args.forEach((arg) => walkNodes(arg, visit));

            return;
        default:
            return;
    }
}

/** M246: every distinct grid cell an AST names, in first-seen order — the mirror of `ExpressionParser::referencedCells()`. */
export function referencedCells(node: Node): CellReferenceNode[] {
    const cells = new Map<string, CellReferenceNode>();
    walkNodes(node, (n) => {
        if (n.type === 'cell' && !cells.has(cellPath(n))) {
            cells.set(cellPath(n), n);
        }
    });

    return [...cells.values()];
}

/** M246: every distinct column a row-relative `${.col}` names — the mirror of `ExpressionParser::referencedRowColumns()`. */
export function referencedRowColumns(node: Node): string[] {
    const columns: string[] = [];
    walkNodes(node, (n) => {
        if (n.type === 'row_cell' && !columns.includes(n.column)) {
            columns.push(n.column);
        }
    });

    return columns;
}
