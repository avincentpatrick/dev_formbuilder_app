/**
 * Lowers a structured `form_field_validations` row into the SAME AST the parser would emit — the mirror of
 * `app/Services/Expressions/StructuredRuleLowering.php` (technical-architecture.md §4.3 §6.7). Rows are
 * discriminated by `rule_type`. The two field-vs-field comparisons (`greater_than_field` /
 * `less_than_field`) and the conditional family (`required_if` / `_with` / `skip_if` / `_with`) lower here;
 * self-vs-literal constraints are handled directly by the structured-rule evaluator (no AST).
 *
 * Compound `logic_group` folding is FLAT and LEFT-ASSOCIATIVE (rows carry no parentheses): fold ascending
 * by `sequence`, ties by `id`; each row (from the second) joins with ITS OWN `logic_operator`.
 */

import type { ComparisonOperator } from './enums';
import { ExpressionEvaluationError } from './errors';
import type { Node } from './ast';
import type { ValidationRow } from './schema';
import * as Ast from './ast-builders';

export type FieldKeysById = Record<string, string>;

export class StructuredRuleLowering {
    lower(row: ValidationRow, fieldKeysById: FieldKeysById): Node {
        switch (row.rule_type) {
            case 'greater_than_field':
                return this.fieldComparison(row, fieldKeysById, true);
            case 'less_than_field':
                return this.fieldComparison(row, fieldKeysById, false);
            default:
                throw ExpressionEvaluationError.unlowerableRuleType(this.ruleTypeLabel(row));
        }
    }

    /**
     * Lower a conditional-family row to its CONDITION AST — the boolean the caller reads to gate
     * requiredness or field skipping. The condition compares the RELATED field, not the owning field. An
     * `_if` row always dispatches by `operator`; a `_with` row defaults to `isNotNull(related)` when no
     * operator is set.
     */
    lowerCondition(row: ValidationRow, fieldKeysById: FieldKeysById): Node {
        const relatedKey = this.relatedKeyOrThrow(row, fieldKeysById);
        const value = row.rule_value ?? '';

        switch (row.rule_type) {
            case 'required_if':
            case 'skip_if':
                return this.conditionForOperator(row.operator, relatedKey, value, row.id);
            case 'required_with':
            case 'skip_with':
                return row.operator === null
                    ? Ast.isNotNull(relatedKey)
                    : this.conditionForOperator(row.operator, relatedKey, value, row.id);
            default:
                throw ExpressionEvaluationError.unlowerableRuleType(this.ruleTypeLabel(row));
        }
    }

    /** Fold a conditional `logic_group`'s rows into one condition AST (flat, left-associative, `[sequence, id]`). */
    lowerConditionGroup(rows: ValidationRow[], fieldKeysById: FieldKeysById): Node {
        if (rows.length === 0) {
            throw ExpressionEvaluationError.unevaluable('empty logic group');
        }

        const sorted = this.sortRows(rows);
        let accumulator = this.lowerCondition(sorted[0], fieldKeysById);

        for (const row of sorted.slice(1)) {
            const operator = this.requireOperator(row.logic_operator, row.id);
            accumulator = { type: 'logical', op: operator, left: accumulator, right: this.lowerCondition(row, fieldKeysById) };
        }

        return accumulator;
    }

    private conditionForOperator(operator: ComparisonOperator | null, relatedKey: string, value: string, rowId: string): Node {
        switch (operator) {
            case 'eq':
            case 'neq':
            case 'gt':
            case 'lt':
                return Ast.comparison(operator, relatedKey, value);
            case 'is_null':
                return Ast.isNull(relatedKey);
            case 'contains':
                return Ast.contains(relatedKey, value);
            default:
                throw ExpressionEvaluationError.unevaluable(`conditional rule ${rowId} has no operator`);
        }
    }

    private relatedKeyOrThrow(row: ValidationRow, fieldKeysById: FieldKeysById): string {
        const relatedId = row.related_form_field_id;
        const relatedKey = relatedId !== null ? (fieldKeysById[relatedId] ?? null) : null;

        if (relatedKey === null) {
            throw ExpressionEvaluationError.missingRelatedField(fieldKeysById[row.form_field_id] ?? row.form_field_id);
        }

        return relatedKey;
    }

    private requireOperator(operator: 'and' | 'or' | null, rowId: string): 'and' | 'or' {
        if (operator === null) {
            throw ExpressionEvaluationError.malformedLogicGroup(rowId);
        }

        return operator;
    }

    private ruleTypeLabel(row: ValidationRow): string {
        return row.rule_type ?? 'expression';
    }

    private fieldComparison(row: ValidationRow, fieldKeysById: FieldKeysById, greater: boolean): Node {
        const fieldKey = fieldKeysById[row.form_field_id] ?? null;
        const relatedId = row.related_form_field_id;
        const relatedKey = relatedId !== null ? (fieldKeysById[relatedId] ?? null) : null;

        if (fieldKey === null || relatedKey === null) {
            throw ExpressionEvaluationError.missingRelatedField(fieldKey ?? row.form_field_id);
        }

        return greater ? Ast.fieldGt(fieldKey, relatedKey) : Ast.fieldLt(fieldKey, relatedKey);
    }

    private sortRows(rows: ValidationRow[]): ValidationRow[] {
        return [...rows].sort((a, b) => (a.sequence - b.sequence) || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
    }
}
