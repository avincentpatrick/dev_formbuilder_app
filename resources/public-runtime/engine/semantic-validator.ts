/**
 * The semantic-validation authority — the mirror of `app/Services/Validation/SemanticValidator.php`
 * (technical-architecture.md §4.1 §4.3). Given a published version's schema + a respondent's answers it
 * produces a {@link SemanticResult}: the relevance mask, per-field constraint/required errors, the
 * relevance-pruned answers, and a (Phase-1-empty) computed-values slot. PHP is the sole authority at submit
 * time; this client mirror must stay byte-identical to it (the golden `validation` suite is the guard).
 *
 * Relevance is settled to a bounded FIXED POINT: pruning a field's answer can change another field's or
 * section's relevance, so the mask is recomputed over the shrinking effective answer set until it stops
 * changing (a field hidden upstream reads as empty downstream — XLSForm/Kobo semantics). It never throws
 * for a validation FAILURE (a false constraint is a result); only a malformed rule raises.
 */

import { ABSENT, isEmpty, type EngineValue, type MaybeAbsent } from './coercion';
import { EvaluationContext, type Answers } from './context';
import { ExpressionEvaluator, makeExpressionEvaluator } from './evaluator';
import { StructuredRuleLowering, type FieldKeysById } from './lowering';
import { StructuredRuleEvaluator } from './structured-rule-evaluator';
import type { SchemaField, SemanticInput, ValidationRow } from './schema';

const DEFAULT_CONSTRAINT_MESSAGE = 'This value is not valid.';
const DEFAULT_REQUIRED_MESSAGE = 'This field is required.';

export interface SemanticError {
    fieldKey: string;
    rule: string;
    message: string;
}

export class SemanticResult {
    readonly fieldRelevance: Record<string, boolean>;
    readonly sectionRelevance: Record<string, boolean>;
    readonly errors: SemanticError[];
    readonly effectiveAnswers: Answers;
    readonly computed: Record<string, EngineValue>;

    constructor(
        fieldRelevance: Record<string, boolean>,
        sectionRelevance: Record<string, boolean>,
        errors: SemanticError[],
        effectiveAnswers: Answers,
        computed: Record<string, EngineValue>,
    ) {
        this.fieldRelevance = fieldRelevance;
        this.sectionRelevance = sectionRelevance;
        this.errors = errors;
        this.effectiveAnswers = effectiveAnswers;
        this.computed = computed;
    }

    passed(): boolean {
        return this.errors.length === 0;
    }

    errorsFor(fieldKey: string): SemanticError[] {
        return this.errors.filter((error) => error.fieldKey === fieldKey);
    }
}

type RuleFamilies = { constraint: ValidationRow[][]; required: ValidationRow[][]; skip: ValidationRow[][] };
type RuleSets = Record<string, RuleFamilies>;

export class SemanticValidator {
    private readonly evaluator: ExpressionEvaluator;
    private readonly rules: StructuredRuleEvaluator;

    constructor(evaluator: ExpressionEvaluator, rules: StructuredRuleEvaluator) {
        this.evaluator = evaluator;
        this.rules = rules;
    }

    /** The pure core (also the golden-vector runner's entry) — no I/O. */
    evaluate(input: SemanticInput): SemanticResult {
        const fieldKeyById: FieldKeysById = {};
        for (const field of input.fields) {
            fieldKeyById[field.id] = field.key;
        }

        const ruleSets = this.buildRuleSets(input);
        const [fieldRelevance, sectionRelevance] = this.settleRelevance(input, ruleSets, fieldKeyById);
        const effectiveAnswers = this.effectiveAnswers(input.answers, fieldRelevance);
        const errors = this.collectErrors(input, ruleSets, fieldRelevance, effectiveAnswers, fieldKeyById);

        return new SemanticResult(fieldRelevance, sectionRelevance, errors, effectiveAnswers, {});
    }

    private buildRuleSets(input: SemanticInput): RuleSets {
        const byField: Record<string, Record<string, ValidationRow[]>> = {};
        for (const row of input.validations) {
            const family = this.family(row);
            (byField[row.form_field_id] ??= {})[family] ??= [];
            byField[row.form_field_id][family].push(row);
        }

        const result: RuleSets = {};
        for (const fieldId of Object.keys(byField)) {
            const families = byField[fieldId];
            result[fieldId] = {
                constraint: this.toUnits(families['constraint'] ?? []),
                required: this.toUnits(families['required'] ?? []),
                skip: this.toUnits(families['skip'] ?? []),
            };
        }

        return result;
    }

    private family(row: ValidationRow): 'constraint' | 'required' | 'skip' {
        if (row.expression !== null) {
            return 'constraint';
        }

        switch (row.rule_type) {
            case 'required_if':
            case 'required_with':
                return 'required';
            case 'skip_if':
            case 'skip_with':
                return 'skip';
            default:
                return 'constraint';
        }
    }

    /** A standalone row is its own unit; rows sharing a `logic_group` fold together as one unit. */
    private toUnits(rows: ValidationRow[]): ValidationRow[][] {
        const units: ValidationRow[][] = [];
        const groups = new Map<string, ValidationRow[]>();

        for (const row of rows) {
            if (row.logic_group === null) {
                units.push([row]);
            } else {
                const group = groups.get(row.logic_group) ?? [];
                group.push(row);
                groups.set(row.logic_group, group);
            }
        }

        for (const group of groups.values()) {
            units.push(group);
        }

        return units;
    }

    private settleRelevance(input: SemanticInput, ruleSets: RuleSets, fieldKeyById: FieldKeysById): [Record<string, boolean>, Record<string, boolean>] {
        let relevant: Record<string, boolean> = {};
        for (const field of input.fields) {
            relevant[field.key] = true; // start optimistic; prune from here
        }

        const maxIterations = input.fields.length + input.sections.length + 2;
        let sectionRelevance: Record<string, boolean> = {};

        for (let iteration = 0; iteration < maxIterations; iteration++) {
            const context = new EvaluationContext(this.answersForRelevant(input.answers, relevant));

            sectionRelevance = {};
            const sectionRelevantById: Record<string, boolean> = {};
            for (const section of input.sections) {
                const ok = this.evaluateRelevance(section.relevant_expression, context);
                sectionRelevance[section.key] = ok;
                sectionRelevantById[section.id] = ok;
            }

            const next: Record<string, boolean> = {};
            for (const field of input.fields) {
                const sectionOk = field.form_section_id === null
                    ? true
                    : (sectionRelevantById[field.form_section_id] ?? true);
                const ownOk = this.evaluateRelevance(field.relevant_expression, context);
                const skipped = this.anyUnitHolds(ruleSets[field.id]?.skip ?? [], context, fieldKeyById);

                if (sectionOk && ownOk && !skipped) {
                    next[field.key] = true;
                }
            }

            if (this.sameKeySet(next, relevant)) {
                break;
            }
            relevant = next;
        }

        return [this.fullMask(input, relevant), sectionRelevance];
    }

    private fullMask(input: SemanticInput, relevant: Record<string, boolean>): Record<string, boolean> {
        const mask: Record<string, boolean> = {};
        for (const field of input.fields) {
            mask[field.key] = Object.prototype.hasOwnProperty.call(relevant, field.key);
        }

        return mask;
    }

    /** A blank expression means "always relevant" / "no condition" — short-circuit before the engine. */
    private evaluateRelevance(expression: string | null, context: EvaluationContext): boolean {
        if (expression === null || expression.trim() === '') {
            return true;
        }

        return this.evaluator.evaluateBoolean(expression, context);
    }

    private anyUnitHolds(units: ValidationRow[][], context: EvaluationContext, fieldKeyById: FieldKeysById): boolean {
        for (const unit of units) {
            const holds = unit.length === 1
                ? this.rules.conditionHolds(unit[0], context, fieldKeyById)
                : this.rules.conditionGroupHolds(unit, context, fieldKeyById);

            if (holds) {
                return true;
            }
        }

        return false;
    }

    private answersForRelevant(answers: Answers, relevant: Record<string, boolean>): Answers {
        const out: Answers = {};
        for (const [key, value] of Object.entries(answers)) {
            if (Object.prototype.hasOwnProperty.call(relevant, key)) {
                out[key] = value;
            }
        }

        return out;
    }

    private effectiveAnswers(answers: Answers, fieldRelevance: Record<string, boolean>): Answers {
        const effective: Answers = {};
        for (const [key, value] of Object.entries(answers)) {
            if (fieldRelevance[key] === true) {
                effective[key] = value;
            }
        }

        return effective;
    }

    private collectErrors(input: SemanticInput, ruleSets: RuleSets, fieldRelevance: Record<string, boolean>, effectiveAnswers: Answers, fieldKeyById: FieldKeysById): SemanticError[] {
        const errors: SemanticError[] = [];
        const context = new EvaluationContext(effectiveAnswers);

        for (const field of input.fields) {
            if (fieldRelevance[field.key] !== true) {
                continue;
            }

            const answer: MaybeAbsent = Object.prototype.hasOwnProperty.call(effectiveAnswers, field.key)
                ? effectiveAnswers[field.key]
                : ABSENT;
            const sets: Partial<RuleFamilies> = ruleSets[field.id] ?? {};

            if (isEmpty(answer)) {
                const [required, trigger] = this.requiredState(field, sets.required ?? [], context, fieldKeyById);
                if (required) {
                    const message = trigger !== null
                        ? this.resolveMessage(trigger, input.locale, DEFAULT_REQUIRED_MESSAGE)
                        : DEFAULT_REQUIRED_MESSAGE;
                    errors.push({ fieldKey: field.key, rule: 'field_required', message });
                }

                continue; // an empty field's constraints are not evaluated
            }

            const selfContext = new EvaluationContext(effectiveAnswers, answer as EngineValue);
            for (const unit of sets.constraint ?? []) {
                const passes = unit.length === 1
                    ? this.rules.passesConstraint(unit[0], field.key, answer, selfContext, fieldKeyById)
                    : this.rules.passesConstraintGroup(unit, field.key, answer, selfContext, fieldKeyById);

                if (!passes) {
                    const representative = this.representative(unit);
                    errors.push({
                        fieldKey: field.key,
                        rule: this.ruleId(representative),
                        message: this.resolveMessage(representative, input.locale, DEFAULT_CONSTRAINT_MESSAGE),
                    });
                }
            }
        }

        return errors;
    }

    private requiredState(field: SchemaField, units: ValidationRow[][], context: EvaluationContext, fieldKeyById: FieldKeysById): [boolean, ValidationRow | null] {
        if (field.is_required === 'required') {
            return [true, null];
        }

        for (const unit of units) {
            const holds = unit.length === 1
                ? this.rules.conditionHolds(unit[0], context, fieldKeyById)
                : this.rules.conditionGroupHolds(unit, context, fieldKeyById);

            if (holds) {
                return [true, this.representative(unit)];
            }
        }

        return [false, null];
    }

    private representative(unit: ValidationRow[]): ValidationRow {
        return [...unit].sort((a, b) => (a.sequence - b.sequence) || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0))[0];
    }

    private ruleId(row: ValidationRow): string {
        if (row.expression !== null) {
            return 'constraint';
        }

        return row.rule_type ?? 'constraint';
    }

    private resolveMessage(row: ValidationRow, locale: string, fallback: string): string {
        const translations = row.error_message_translations ?? {};
        if (typeof translations[locale] === 'string') {
            return translations[locale];
        }

        return row.error_message ?? fallback;
    }

    private sameKeySet(a: Record<string, boolean>, b: Record<string, boolean>): boolean {
        const aKeys = Object.keys(a);
        const bKeys = Object.keys(b);
        if (aKeys.length !== bKeys.length) {
            return false;
        }

        return aKeys.every((key) => Object.prototype.hasOwnProperty.call(b, key));
    }
}

/** Assemble a ready-to-use validator — mirrors the Pest `makeSemanticValidator()` helper. */
export function makeSemanticValidator(): SemanticValidator {
    const engine = makeExpressionEvaluator();

    return new SemanticValidator(engine, new StructuredRuleEvaluator(engine, new StructuredRuleLowering()));
}
