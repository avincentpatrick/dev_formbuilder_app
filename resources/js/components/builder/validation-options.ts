/**
 * What a validation rule's compared question may be, read off TRANSMITTED facts (M131, `R-57711a3a`).
 *
 * ⛔ THE GATE WAS THE ONLY PLACE THIS WAS TRUE, AND IT IS THE BACKSTOP, NOT THE EDITOR. Since M123 publish
 * refuses a rule whose compared question's kind its comparison cannot take — a field comparison against a note,
 * a date or a choice; an `_if` rule naming a note, which no operator can read. The editor kept offering all of
 * them, so an author built a rule, saved it, and met the refusal only at publish. These functions make the
 * editor offer what the gate accepts, from the same two facts the server judges with:
 *
 *   - a rule that READS an operator (`takes_operator`, the four conditionals) may name any question SOME operator
 *     can compare — the union of every operator's `shapes`, which is what excludes a note;
 *   - a rule that does not (the two field comparisons) compares with exactly one operator, its
 *     `related_comparison` (`ValidationRuleType::relatedComparison()`, transmitted) — `gt` or `lt`, whose shapes
 *     are number, duration and scale, so a likert scale stays offered and a date does not.
 *
 * Filtering by the rule's OWN `shapes` instead would hide a likert scale publish accepts: those are the owning
 * field's shapes, not the compared one's.
 */
import type { BuilderValidation, ComparableField, OperatorOption, RuleTypeOption } from './types';

/** The value shapes a rule's compared question may have; empty for a rule that names no question. */
export function relatedShapes(rule: RuleTypeOption, operators: OperatorOption[]): Set<string> {
    if (!rule.takes_related_field) return new Set();

    if (rule.takes_operator) {
        return new Set(operators.flatMap((operator) => operator.shapes));
    }

    const comparison = operators.find((operator) => operator.value === rule.related_comparison);

    return new Set(comparison?.shapes ?? []);
}

/** Whether the rule may name this question at all. */
export function mayCompare(rule: RuleTypeOption, operators: OperatorOption[], field: ComparableField): boolean {
    return relatedShapes(rule, operators).has(field.value_shape);
}

/** Whether a stored operator can read a question of this shape. A null operator reads nothing, so it always can. */
export function operatorReads(operator: string | null, shape: string, operators: OperatorOption[]): boolean {
    if (operator === null) return true;

    return operators.find((candidate) => candidate.value === operator)?.shapes.includes(shape) ?? false;
}

/**
 * The patch that re-points a row at another question: an operator the new question cannot take is cleared, and
 * the value it compared against goes with it — a `= 'yes'` left behind on a number question is a rule nobody
 * wrote. An operator the new question CAN take is kept.
 */
export function repointPatch(
    row: BuilderValidation,
    key: string | null,
    fields: ComparableField[],
    operators: OperatorOption[],
): Partial<BuilderValidation> {
    const shape = fields.find((field) => field.key === key)?.value_shape ?? null;

    if (shape === null || operatorReads(row.operator, shape, operators)) {
        return { related_field_key: key };
    }

    return { related_field_key: key, operator: null, rule_value: null };
}

/**
 * What survives a change of rule kind: the compared question only if the NEW rule may name it, and the operator
 * only if the new rule reads one and it can read that question. `setRuleType()` used to keep the question across
 * every kind that takes one, so `required_if` → `greater_than_field` kept a text question publish refuses.
 */
export function ruleChangePatch(
    row: BuilderValidation,
    rule: RuleTypeOption | null,
    fields: ComparableField[],
    operators: OperatorOption[],
): Partial<BuilderValidation> {
    const related = fields.find((field) => field.key === row.related_field_key) ?? null;
    const keepRelated = rule !== null && rule.takes_related_field && related !== null && mayCompare(rule, operators, related);
    const keepOperator =
        rule !== null && rule.takes_operator && keepRelated && operatorReads(row.operator, related.value_shape, operators);

    return {
        rule_type: rule?.value ?? null,
        related_field_key: keepRelated ? row.related_field_key : null,
        operator: keepOperator ? row.operator : null,
    };
}
