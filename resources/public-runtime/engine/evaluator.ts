/**
 * The tree-walking interpreter — the mirror of `app/Services/Expressions/ExpressionEvaluator.php`
 * (technical-architecture.md §4.3 §6). Total over data: empty / missing / type-mismatch yield a defined
 * falsy result, never a throw (a `constraint` returning false is a validation RESULT, not an error). No
 * dynamic dispatch: node dispatch is on the `type` tag, operator/function dispatch is a closed switch. The
 * §6 rules here are the normative contract mirrored byte-for-byte from PHP.
 *
 * Grammar v2.0 (Increment G3) adds arithmetic (`+ - * /`, NaN-propagating, division-by-zero → NaN → blank),
 * the `>=`/`<=` numeric comparators, and the value function library (`if`, `count`, `int`, `today`, `now`).
 * A NaN arithmetic result normalises to `null` at the value boundary (a blank computed value).
 */

import { ABSENT, isEmpty, isNumericLike, toBool, toNumber, toStr, type EngineValue, type MaybeAbsent } from './coercion';
import { isEmptyStringLiteral, isStringLiteral, type Node } from './ast';
import { EvaluationContext } from './context';
import { ExpressionEvaluationError } from './errors';
import { ExpressionLexer } from './lexer';
import { ExpressionParser } from './parser';
import { FunctionRegistry } from './function-registry';

export const GRAMMAR_VERSION = '2.0';

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
        if (value === ABSENT) {
            return null;
        }

        // A NaN arithmetic result (empty/non-numeric operand or division by zero) is a blank value.
        if (typeof value === 'number' && Number.isNaN(value)) {
            return null;
        }

        return value;
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
            case 'arithmetic':
                return this.evalArithmetic(node, context);
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
            case 'lt':
            case 'gte':
            case 'lte':
                return this.numericCompare(left, right, node.op);
            case 'eq':
                return this.equals(node.left, node.right, left, right);
            case 'neq':
                return !this.equals(node.left, node.right, left, right);
            default:
                throw ExpressionEvaluationError.unevaluable(`comparison operator ${node.op}`);
        }
    }

    /** Numeric-only ordering: a non-numeric (or NaN) operand makes any of `> < >= <=` false. */
    private numericCompare(left: MaybeAbsent, right: MaybeAbsent, op: 'gt' | 'lt' | 'gte' | 'lte'): boolean {
        const a = toNumber(left);
        const b = toNumber(right);

        if (Number.isNaN(a) || Number.isNaN(b)) {
            return false;
        }

        switch (op) {
            case 'gt':
                return a > b;
            case 'lt':
                return a < b;
            case 'gte':
                return a >= b;
            case 'lte':
                return a <= b;
        }
    }

    /**
     * `+ - * /` over `toNumber` of each operand. A NaN operand (empty / non-numeric) or a division by zero
     * yields NaN — a blank value at the boundary — never a throw (the interpreter stays total).
     */
    private evalArithmetic(node: Extract<Node, { type: 'arithmetic' }>, context: EvaluationContext): number {
        const a = toNumber(this.evalNode(node.left, context));
        const b = toNumber(this.evalNode(node.right, context));

        if (Number.isNaN(a) || Number.isNaN(b)) {
            return NaN;
        }

        switch (node.op) {
            case 'add':
                return a + b;
            case 'sub':
                return a - b;
            case 'mul':
                return a * b;
            case 'div':
                return b === 0 ? NaN : a / b;
        }
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

    private evalFunction(node: Extract<Node, { type: 'call' }>, context: EvaluationContext): MaybeAbsent {
        switch (node.name) {
            case 'selected':
            case 'contains':
                return this.evalMembershipFunction(node, context);
            case 'if':
                return this.evalIf(node, context);
            case 'count':
                return this.countOf(this.evalNode(this.arg(node, 0), context));
            case 'int':
                return this.intCast(this.evalNode(this.arg(node, 0), context));
            case 'today':
                return this.today(context);
            case 'now':
                return context.hasNow() ? (context.now as string) : ABSENT;
            default:
                throw ExpressionEvaluationError.unevaluable(`function ${node.name}`);
        }
    }

    private evalMembershipFunction(node: Extract<Node, { type: 'call' }>, context: EvaluationContext): boolean {
        const value = this.evalNode(this.arg(node, 0), context);
        const needle = toStr(this.evalNode(this.arg(node, 1), context));

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

    /** `if(cond, then, else)` — short-circuit: only the taken branch is evaluated. */
    private evalIf(node: Extract<Node, { type: 'call' }>, context: EvaluationContext): MaybeAbsent {
        return toBool(this.evalNode(this.arg(node, 0), context))
            ? this.evalNode(this.arg(node, 1), context)
            : this.evalNode(this.arg(node, 2), context);
    }

    /** `count(x)` — an array's length (repeat instances / multi-select), 0 for empty, 1 for a non-empty scalar. */
    private countOf(value: MaybeAbsent): number {
        if (Array.isArray(value)) {
            return value.length;
        }

        return isEmpty(value) ? 0 : 1;
    }

    /** `int(x)` — truncation-toward-zero of x's numeric value; a non-numeric value → 0 (mirrors PHP `(int)`). */
    private intCast(value: MaybeAbsent): number {
        const n = toNumber(value);

        return Number.isNaN(n) ? 0 : Math.trunc(n);
    }

    /** `today()` — the date portion (YYYY-MM-DD) of the injected clock; Absent when no clock is set. */
    private today(context: EvaluationContext): MaybeAbsent {
        return context.hasNow() ? (context.now as string).slice(0, 10) : ABSENT;
    }

    private arg(node: Extract<Node, { type: 'call' }>, index: number): Node {
        const node2 = node.args[index];
        if (node2 === undefined) {
            throw ExpressionEvaluationError.unevaluable(`${node.name} is missing argument ${index + 1}`);
        }

        return node2;
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
