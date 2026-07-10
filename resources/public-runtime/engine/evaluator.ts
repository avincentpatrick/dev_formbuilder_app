/**
 * The tree-walking interpreter — the mirror of `app/Services/Expressions/ExpressionEvaluator.php`
 * (technical-architecture.md §4.3 §6). Total over data: empty / missing / type-mismatch yield a defined
 * falsy result, never a throw (a `constraint` returning false is a validation RESULT, not an error). No
 * dynamic dispatch: node dispatch is on the `type` tag, operator/function dispatch is a closed switch. The
 * §6 rules here are the normative contract mirrored byte-for-byte from PHP.
 */

import { ABSENT, isEmpty, isNumericLike, toBool, toNumber, toStr, type EngineValue, type MaybeAbsent } from './coercion';
import { isEmptyStringLiteral, isStringLiteral, type Node } from './ast';
import { EvaluationContext } from './context';
import { ExpressionEvaluationError } from './errors';
import { ExpressionLexer } from './lexer';
import { ExpressionParser } from './parser';
import { FunctionRegistry } from './function-registry';

export const GRAMMAR_VERSION = '1.0';

export class ExpressionEvaluator {
    private readonly parser: ExpressionParser;

    constructor(parser: ExpressionParser) {
        this.parser = parser;
    }

    /** Parse + interpret; the raw node value with the internal Absent sentinel normalised to null. */
    evaluate(expression: string, context: EvaluationContext): EngineValue {
        return this.normalize(this.evalNode(this.parser.parse(expression), context));
    }

    /** Interpret a pre-parsed AST (raw value, Absent normalised to null). */
    evaluateNode(ast: Node, context: EvaluationContext): EngineValue {
        return this.normalize(this.evalNode(ast, context));
    }

    /** The authoritative relevant/constraint form: toBool(evaluate-internal(...)). */
    evaluateBoolean(expression: string, context: EvaluationContext): boolean {
        return toBool(this.evalNode(this.parser.parse(expression), context));
    }

    private normalize(value: MaybeAbsent): EngineValue {
        return value === ABSENT ? null : value;
    }

    private evalNode(node: Node, context: EvaluationContext): MaybeAbsent {
        switch (node.type) {
            case 'literal':
                return node.value;
            case 'field':
                return context.has(node.key) ? context.answers[node.key] : ABSENT;
            case 'self':
                return context.hasSelf() ? context.selfValue() : ABSENT;
            case 'not':
                return !toBool(this.evalNode(node.operand, context));
            case 'logical':
                return this.evalLogical(node, context);
            case 'comparison':
                return this.evalComparison(node, context);
            case 'call':
                return this.evalFunction(node, context);
            default:
                throw ExpressionEvaluationError.unevaluable((node as { type: string }).type);
        }
    }

    private evalLogical(node: Extract<Node, { type: 'logical' }>, context: EvaluationContext): boolean {
        // Short-circuit: the right operand is not evaluated when the left already decides the result.
        return node.op === 'and'
            ? toBool(this.evalNode(node.left, context)) && toBool(this.evalNode(node.right, context))
            : toBool(this.evalNode(node.left, context)) || toBool(this.evalNode(node.right, context));
    }

    private evalComparison(node: Extract<Node, { type: 'comparison' }>, context: EvaluationContext): boolean {
        const left = this.evalNode(node.left, context);
        const right = this.evalNode(node.right, context);

        switch (node.op) {
            case 'gt':
                return this.numericCompare(left, right, true);
            case 'lt':
                return this.numericCompare(left, right, false);
            case 'eq':
                return this.equals(node.left, node.right, left, right);
            case 'neq':
                return !this.equals(node.left, node.right, left, right);
            default:
                throw ExpressionEvaluationError.unevaluable(`comparison operator ${node.op}`);
        }
    }

    private numericCompare(left: MaybeAbsent, right: MaybeAbsent, greater: boolean): boolean {
        const a = toNumber(left);
        const b = toNumber(right);

        if (Number.isNaN(a) || Number.isNaN(b)) {
            return false;
        }

        return greater ? a > b : a < b;
    }

    private equals(leftNode: Node, rightNode: Node, left: MaybeAbsent, right: MaybeAbsent): boolean {
        // 1/2: an empty string literal on either side is an emptiness test.
        if (isEmptyStringLiteral(leftNode)) {
            return isEmpty(right);
        }

        if (isEmptyStringLiteral(rightNode)) {
            return isEmpty(left);
        }

        // 3: arrays compare only via emptiness (above) or selected(); anything else is false.
        if (Array.isArray(left) || Array.isArray(right)) {
            return false;
        }

        // 4: a quoted string literal on either side forces string comparison.
        if (isStringLiteral(leftNode) || isStringLiteral(rightNode)) {
            return toStr(left) === toStr(right);
        }

        // 5: both numeric-like → exact numeric equality (safe in Phase 1: no arithmetic).
        if (isNumericLike(left) && isNumericLike(right)) {
            return toNumber(left) === toNumber(right);
        }

        // 6: fall back to string equality.
        return toStr(left) === toStr(right);
    }

    private evalFunction(node: Extract<Node, { type: 'call' }>, context: EvaluationContext): boolean {
        const target = node.args[0];
        const literalNode = node.args[1];

        if (target === undefined || literalNode === undefined) {
            throw ExpressionEvaluationError.unevaluable(`${node.name} requires two arguments`);
        }

        const value = this.evalNode(target, context);
        const needle = toStr(this.evalNode(literalNode, context));

        switch (node.name) {
            case 'selected':
                return this.membership(value, needle);
            // Internal, lowering-only: array membership, else substring on a scalar.
            case 'contains':
                return Array.isArray(value)
                    ? this.membership(value, needle)
                    : !isEmpty(value) && toStr(value).includes(needle);
            default:
                throw ExpressionEvaluationError.unevaluable(`function ${node.name}`);
        }
    }

    private membership(value: MaybeAbsent, needle: string): boolean {
        if (Array.isArray(value)) {
            return value.map((item) => toStr(item)).includes(needle);
        }

        if (isEmpty(value)) {
            return false;
        }

        return toStr(value) === needle;
    }
}

/** Assemble a ready-to-use evaluator — mirrors the Pest `makeExpressionEvaluator()` helper. */
export function makeExpressionEvaluator(): ExpressionEvaluator {
    return new ExpressionEvaluator(new ExpressionParser(new ExpressionLexer(), new FunctionRegistry()));
}
