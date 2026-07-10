/**
 * Evaluates one structured `form_field_validations` row (or a folded `logic_group`) — the mirror of
 * `app/Services/Validation/StructuredRuleEvaluator.php` (technical-architecture.md §4.3 §6.7). Three
 * families:
 *
 *  1. Free-text `expression` rows + the two field-vs-field comparisons → the expression evaluator (lowered
 *     to the SAME AST a parsed string would yield, so structured ≡ expression).
 *  2. Self-vs-literal constraints (`min_value` / `max_value` / `min_length` / `max_length` / `pattern`) →
 *     DIRECT coercion primitives (the grammar has no `>=`/`<=`, length, or regex). An empty answer PASSES
 *     every one of these — a constraint is only checked on an answered field; emptiness is the required
 *     check's job.
 *  3. Conditional-family conditions → lowered and read as a boolean gate (NOT a pass/fail).
 *
 * A false result is a validation RESULT, never a throw; only a structurally-impossible rule raises.
 */

import { isEmpty, isNumericLike, toBool, toNumber, toStr, type MaybeAbsent } from './coercion';
import type { EvaluationContext } from './context';
import type { LogicOperator } from './enums';
import { ExpressionEvaluationError } from './errors';
import type { ExpressionEvaluator } from './evaluator';
import { StructuredRuleLowering, type FieldKeysById } from './lowering';
import type { ValidationRow } from './schema';

const PATTERN_DELIMITER_FLAGS = 'u';

/** PHP-style `(int)` cast: parse the leading base-10 integer, else 0 (`"3"→3`, `""→0`, `"3.9"→3`, `"abc"→0`). */
function phpIntCast(value: string): number {
    const n = parseInt(value, 10);

    return Number.isNaN(n) ? 0 : n;
}

/** Code-point length, matching PHP `mb_strlen($s, 'UTF-8')` (NOT JS `String.length`, which counts UTF-16 units). */
function codePointLength(value: string): number {
    return [...value].length;
}

export class StructuredRuleEvaluator {
    private readonly evaluator: ExpressionEvaluator;
    private readonly lowering: StructuredRuleLowering;

    constructor(evaluator: ExpressionEvaluator, lowering: StructuredRuleLowering) {
        this.evaluator = evaluator;
        this.lowering = lowering;
    }

    /**
     * Compile a stored `pattern` `rule_value` into an anchored full-match (the HTML `pattern`-attribute
     * convention: the whole answer must match, Unicode-aware). Only UNescaped `/` delimiters are escaped so
     * an already-escaped `\/` is left alone. Shared with the (server) publish gate so both accept/reject
     * identically. Mirrors StructuredRuleEvaluator::compilePattern.
     */
    static compilePattern(pattern: string): RegExp {
        const body = pattern.replace(/(?<!\\)\//g, '\\/');

        return new RegExp(`^(?:${body})$`, PATTERN_DELIMITER_FLAGS);
    }

    /**
     * One constraint-producing rule → true = the answer passes. `context` must carry `self` = the field's
     * own answer so a free-text expression's `.` reference resolves.
     */
    passesConstraint(row: ValidationRow, fieldKey: string, answer: MaybeAbsent, context: EvaluationContext, fieldKeysById: FieldKeysById): boolean {
        // A free-text expression supersedes the structured columns (the DB XOR CHECK); a blank one is a no-op.
        if (row.expression !== null) {
            return row.expression.trim() === '' || toBool(this.evaluator.evaluate(row.expression, context));
        }

        switch (row.rule_type) {
            case 'greater_than_field':
            case 'less_than_field':
                return toBool(this.evaluator.evaluateNode(this.lowering.lower(row, fieldKeysById), context));
            case 'min_value':
                return isEmpty(answer) || (isNumericLike(answer) && toNumber(answer) >= toNumber(this.ruleValue(row)));
            case 'max_value':
                return isEmpty(answer) || (isNumericLike(answer) && toNumber(answer) <= toNumber(this.ruleValue(row)));
            case 'min_length':
                return isEmpty(answer) || codePointLength(toStr(answer)) >= phpIntCast(this.ruleValue(row));
            case 'max_length':
                return isEmpty(answer) || codePointLength(toStr(answer)) <= phpIntCast(this.ruleValue(row));
            case 'pattern':
                return isEmpty(answer) || this.matchesPattern(this.ruleValue(row), toStr(answer));
            // Conditional-family rows are not constraints; reaching here is a caller-contract violation.
            default:
                throw ExpressionEvaluationError.unlowerableRuleType(this.ruleLabel(row));
        }
    }

    /**
     * A `logic_group` of constraint rows → one boolean, folded flat + left-associative by `[sequence, id]`
     * with each non-first row's own `logic_operator` (over the boolean RESULTS — self-vs-literal rows are
     * not AST-lowerable, so they cannot fold through the engine).
     */
    passesConstraintGroup(rows: ValidationRow[], fieldKey: string, answer: MaybeAbsent, context: EvaluationContext, fieldKeysById: FieldKeysById): boolean {
        const sorted = this.sortRows(rows);

        let result = this.passesConstraint(sorted[0], fieldKey, answer, context, fieldKeysById);

        for (const row of sorted.slice(1)) {
            const connective = this.groupConnective(row.logic_operator, row.id);
            const rhs = this.passesConstraint(row, fieldKey, answer, context, fieldKeysById);
            result = connective === 'and' ? result && rhs : result || rhs;
        }

        return result;
    }

    /** A single conditional-family row → does its condition hold (over the current answer context)? */
    conditionHolds(row: ValidationRow, context: EvaluationContext, fieldKeysById: FieldKeysById): boolean {
        return toBool(this.evaluator.evaluateNode(this.lowering.lowerCondition(row, fieldKeysById), context));
    }

    /** A conditional `logic_group` → does the folded compound condition hold? */
    conditionGroupHolds(rows: ValidationRow[], context: EvaluationContext, fieldKeysById: FieldKeysById): boolean {
        return toBool(this.evaluator.evaluateNode(this.lowering.lowerConditionGroup(rows, fieldKeysById), context));
    }

    private matchesPattern(pattern: string, subject: string): boolean {
        if (pattern === '') {
            return false; // no pattern → cannot match (fail-closed)
        }

        try {
            // A regex that fails to compile is treated as a non-match, never truthy (mirrors PHP's `false`).
            return StructuredRuleEvaluator.compilePattern(pattern).test(subject);
        } catch {
            return false;
        }
    }

    private ruleValue(row: ValidationRow): string {
        return row.rule_value ?? '';
    }

    private ruleLabel(row: ValidationRow): string {
        return row.rule_type ?? 'expression';
    }

    private groupConnective(operator: LogicOperator | null, rowId: string): LogicOperator {
        if (operator === null) {
            throw ExpressionEvaluationError.malformedLogicGroup(rowId);
        }

        return operator;
    }

    private sortRows(rows: ValidationRow[]): ValidationRow[] {
        return [...rows].sort((a, b) => (a.sequence - b.sequence) || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
    }
}
