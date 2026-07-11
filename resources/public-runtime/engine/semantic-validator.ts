/**
 * The semantic-validation authority — the mirror of `app/Services/Validation/SemanticValidator.php`
 * (technical-architecture.md §4.1 §4.3). Given a published version's schema + a respondent's answers it
 * produces a {@link SemanticResult}: the relevance mask, per-field constraint/required errors, the
 * relevance-pruned answers, and (grammar v2.0 / Increment G3) the `computed` map of every relevant
 * calculated field's formula result. PHP is the sole authority at submit time; this client mirror must stay
 * byte-identical to it (the golden `validation` suite is the guard).
 *
 * Relevance is settled to a bounded FIXED POINT: pruning a field's answer can change another field's or
 * section's relevance, so the mask is recomputed over the shrinking effective answer set until it stops
 * changing (a field hidden upstream reads as empty downstream — XLSForm/Kobo semantics). It never throws
 * for a validation FAILURE (a false constraint is a result); only a malformed rule raises.
 *
 * Repeat groups (Increment G1): repeatable-section members are pulled out of the flat pass so top-level
 * relevance/answers/errors stay exactly as pre-G1, then each instance is settled + error-checked in its own
 * scope (the outside scope merged over that instance, so a member expression may reference an outside gate
 * field and a same-instance sibling). Instance count is enforced against min/max on the relevant section.
 */

import { ABSENT, isEmpty, type EngineValue, type MaybeAbsent } from './coercion';
import { EvaluationContext, type Answers } from './context';
import { ExpressionEvaluator, makeExpressionEvaluator } from './evaluator';
import { StructuredRuleLowering, type FieldKeysById } from './lowering';
import { StructuredRuleEvaluator } from './structured-rule-evaluator';
import type { InstanceAnswers, SchemaField, SemanticInput, ValidationRow } from './schema';

const DEFAULT_CONSTRAINT_MESSAGE = 'This value is not valid.';
const DEFAULT_REQUIRED_MESSAGE = 'This field is required.';

export interface SemanticError {
    fieldKey: string;
    rule: string;
    message: string;
    /** Repeat-group address (Increment G1): the owning section + 0-based instance; null on a flat error. */
    sectionKey?: string | null;
    instanceIndex?: number | null;
}

/** The stable address the surface + the 422 envelope key on: `field`, `section`, or `section[i].field`. */
export function errorPath(error: SemanticError): string {
    const sectionKey = error.sectionKey ?? null;
    const instanceIndex = error.instanceIndex ?? null;
    if (sectionKey === null) {
        return error.fieldKey;
    }
    if (instanceIndex === null) {
        return sectionKey;
    }

    return `${sectionKey}[${instanceIndex}].${error.fieldKey}`;
}

export class SemanticResult {
    readonly fieldRelevance: Record<string, boolean>;
    readonly sectionRelevance: Record<string, boolean>;
    readonly errors: SemanticError[];
    readonly effectiveAnswers: Record<string, EngineValue | InstanceAnswers[]>;
    readonly computed: Record<string, EngineValue>;
    readonly repeatFieldRelevance: Record<string, Record<string, boolean>[]>;

    constructor(
        fieldRelevance: Record<string, boolean>,
        sectionRelevance: Record<string, boolean>,
        errors: SemanticError[],
        effectiveAnswers: Record<string, EngineValue | InstanceAnswers[]>,
        computed: Record<string, EngineValue>,
        repeatFieldRelevance: Record<string, Record<string, boolean>[]> = {},
    ) {
        this.fieldRelevance = fieldRelevance;
        this.sectionRelevance = sectionRelevance;
        this.errors = errors;
        this.effectiveAnswers = effectiveAnswers;
        this.computed = computed;
        this.repeatFieldRelevance = repeatFieldRelevance;
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
        const now = input.now ?? null;

        const [topLevelFields, repeatMembersBySectionId] = this.partitionFields(input);

        const [fieldRelevance, sectionRelevance] = this.settleRelevance(input, topLevelFields, ruleSets, fieldKeyById, now);
        const flatEffective = this.effectiveAnswers(input.answers, fieldRelevance);
        const errors = this.collectErrors(topLevelFields, ruleSets, fieldRelevance, flatEffective, fieldKeyById, input.locale, now);

        const [repeatEffective, repeatErrors, repeatRelevance] = this.processRepeats(
            input,
            repeatMembersBySectionId,
            ruleSets,
            sectionRelevance,
            flatEffective,
            fieldKeyById,
            now,
        );

        const effectiveAnswers: Record<string, EngineValue | InstanceAnswers[]> = { ...flatEffective };
        for (const [sectionKey, instances] of Object.entries(repeatEffective)) {
            effectiveAnswers[sectionKey] = instances;
        }

        // Calculated fields (grammar v2.0) are computed last, over the full effective answers (flat + repeat
        // instance arrays merged above, so a calc can `count(${section})`). See computeCalculated().
        const computed = this.computeCalculated(topLevelFields, effectiveAnswers, fieldRelevance, now);

        return new SemanticResult(fieldRelevance, sectionRelevance, [...errors, ...repeatErrors], effectiveAnswers, computed, repeatRelevance);
    }

    /**
     * Split fields into the flat (top-level) set and the repeatable-section members grouped by section id.
     * A field is a repeat member iff its `form_section_id` points at a repeatable section.
     */
    private partitionFields(input: SemanticInput): [SchemaField[], Record<string, SchemaField[]>] {
        const repeatSectionIds: Record<string, boolean> = {};
        for (const section of input.sections) {
            if (section.is_repeatable === true) {
                repeatSectionIds[section.id] = true;
            }
        }

        const topLevel: SchemaField[] = [];
        const membersBySectionId: Record<string, SchemaField[]> = {};
        for (const field of input.fields) {
            const sectionId = field.form_section_id;
            if (sectionId !== null && repeatSectionIds[sectionId] === true) {
                (membersBySectionId[sectionId] ??= []).push(field);
            } else {
                topLevel.push(field);
            }
        }

        return [topLevel, membersBySectionId];
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

    private settleRelevance(input: SemanticInput, fields: SchemaField[], ruleSets: RuleSets, fieldKeyById: FieldKeysById, now: string | null): [Record<string, boolean>, Record<string, boolean>] {
        let relevant: Record<string, boolean> = {};
        for (const field of fields) {
            relevant[field.key] = true; // start optimistic; prune from here
        }

        const maxIterations = fields.length + input.sections.length + 2;
        let sectionRelevance: Record<string, boolean> = {};

        for (let iteration = 0; iteration < maxIterations; iteration++) {
            const context = new EvaluationContext(this.answersForRelevant(input.answers, relevant), undefined, now);

            sectionRelevance = {};
            const sectionRelevantById: Record<string, boolean> = {};
            for (const section of input.sections) {
                const ok = this.evaluateRelevance(section.relevant_expression, context);
                sectionRelevance[section.key] = ok;
                sectionRelevantById[section.id] = ok;
            }

            const next: Record<string, boolean> = {};
            for (const field of fields) {
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

        return [this.fullMask(fields, relevant), sectionRelevance];
    }

    private fullMask(fields: SchemaField[], relevant: Record<string, boolean>): Record<string, boolean> {
        const mask: Record<string, boolean> = {};
        for (const field of fields) {
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

    private answersForRelevant(answers: Record<string, EngineValue | InstanceAnswers[]>, relevant: Record<string, boolean>): Answers {
        const out: Answers = {};
        for (const [key, value] of Object.entries(answers)) {
            if (Object.prototype.hasOwnProperty.call(relevant, key)) {
                out[key] = value as EngineValue;
            }
        }

        return out;
    }

    private effectiveAnswers(answers: Record<string, EngineValue | InstanceAnswers[]>, fieldRelevance: Record<string, boolean>): Answers {
        const effective: Answers = {};
        for (const [key, value] of Object.entries(answers)) {
            if (fieldRelevance[key] === true) {
                effective[key] = value as EngineValue;
            }
        }

        return effective;
    }

    private collectErrors(fields: SchemaField[], ruleSets: RuleSets, fieldRelevance: Record<string, boolean>, effectiveAnswers: Answers, fieldKeyById: FieldKeysById, locale: string, now: string | null): SemanticError[] {
        const errors: SemanticError[] = [];

        for (const field of fields) {
            if (fieldRelevance[field.key] !== true) {
                continue;
            }

            this.collectFieldErrors(field, effectiveAnswers, effectiveAnswers, ruleSets, fieldKeyById, locale, now, errors, null, null);
        }

        return errors;
    }

    private collectFieldErrors(
        field: SchemaField,
        answerScope: Answers,
        contextAnswers: Answers,
        ruleSets: RuleSets,
        fieldKeyById: FieldKeysById,
        locale: string,
        now: string | null,
        errors: SemanticError[],
        sectionKey: string | null,
        instanceIndex: number | null,
    ): void {
        // A calculated field is a server-computed OUTPUT, never a respondent input — no required/constraint.
        if (field.field_type === 'calculated') {
            return;
        }

        const context = new EvaluationContext(contextAnswers, undefined, now);
        const answer: MaybeAbsent = Object.prototype.hasOwnProperty.call(answerScope, field.key) ? answerScope[field.key] : ABSENT;
        const sets: Partial<RuleFamilies> = ruleSets[field.id] ?? {};

        if (isEmpty(answer)) {
            const [required, trigger] = this.requiredState(field, sets.required ?? [], context, fieldKeyById);
            if (required) {
                const message = trigger !== null
                    ? this.resolveMessage(trigger, locale, DEFAULT_REQUIRED_MESSAGE)
                    : DEFAULT_REQUIRED_MESSAGE;
                errors.push({ fieldKey: field.key, rule: 'field_required', message, sectionKey, instanceIndex });
            }

            return; // an empty field's constraints are not evaluated
        }

        const selfContext = new EvaluationContext(contextAnswers, answer as EngineValue, now);
        for (const unit of sets.constraint ?? []) {
            const passes = unit.length === 1
                ? this.rules.passesConstraint(unit[0], field.key, answer, selfContext, fieldKeyById)
                : this.rules.passesConstraintGroup(unit, field.key, answer, selfContext, fieldKeyById);

            if (!passes) {
                const representative = this.representative(unit);
                errors.push({
                    fieldKey: field.key,
                    rule: this.ruleId(representative),
                    message: this.resolveMessage(representative, locale, DEFAULT_CONSTRAINT_MESSAGE),
                    sectionKey,
                    instanceIndex,
                });
            }
        }
    }

    private processRepeats(
        input: SemanticInput,
        repeatMembersBySectionId: Record<string, SchemaField[]>,
        ruleSets: RuleSets,
        sectionRelevance: Record<string, boolean>,
        baseEffective: Answers,
        fieldKeyById: FieldKeysById,
        now: string | null,
    ): [Record<string, InstanceAnswers[]>, SemanticError[], Record<string, Record<string, boolean>[]>] {
        const effective: Record<string, InstanceAnswers[]> = {};
        const errors: SemanticError[] = [];
        const relevance: Record<string, Record<string, boolean>[]> = {};

        for (const section of input.sections) {
            if (section.is_repeatable !== true) {
                continue;
            }

            const sectionKey = section.key;
            if (sectionRelevance[sectionKey] !== true) {
                continue; // hidden repeatable section: drop the whole group, enforce nothing
            }

            const members = repeatMembersBySectionId[section.id] ?? [];
            const rawInstances = input.answers[sectionKey];
            const instances: InstanceAnswers[] = Array.isArray(rawInstances)
                ? (rawInstances.filter((instance) => typeof instance === 'object' && instance !== null && !Array.isArray(instance)) as InstanceAnswers[])
                : [];
            const count = instances.length;

            const max = section.max_instances ?? null;
            if (max !== null && count > max) {
                errors.push({ fieldKey: sectionKey, rule: 'max_instances', message: this.maxInstancesMessage(max), sectionKey, instanceIndex: null });
            }

            const min = section.min_instances ?? null;
            if (min !== null && min > 0 && count < min) {
                errors.push({ fieldKey: sectionKey, rule: 'min_instances', message: this.minInstancesMessage(min), sectionKey, instanceIndex: null });
            }

            instances.forEach((instanceAnswers, index) => {
                const [instanceRelevance, instanceEffective] = this.settleInstanceRelevance(members, ruleSets, baseEffective, instanceAnswers, fieldKeyById, now);

                const contextAnswers: Answers = { ...baseEffective, ...instanceEffective };
                for (const field of members) {
                    if (instanceRelevance[field.key] !== true) {
                        continue;
                    }
                    this.collectFieldErrors(field, instanceEffective, contextAnswers, ruleSets, fieldKeyById, input.locale, now, errors, sectionKey, index);
                }

                (effective[sectionKey] ??= []).push(instanceEffective);
                (relevance[sectionKey] ??= []).push(instanceRelevance);
            });
        }

        return [effective, errors, relevance];
    }

    private settleInstanceRelevance(
        members: SchemaField[],
        ruleSets: RuleSets,
        baseAnswers: Answers,
        instanceAnswers: InstanceAnswers,
        fieldKeyById: FieldKeysById,
        now: string | null,
    ): [Record<string, boolean>, InstanceAnswers] {
        let relevant: Record<string, boolean> = {};
        for (const field of members) {
            relevant[field.key] = true; // start optimistic; prune from here
        }

        const maxIterations = members.length + 2;

        for (let iteration = 0; iteration < maxIterations; iteration++) {
            const prunedInstance = this.pickRelevant(instanceAnswers, relevant);
            const context = new EvaluationContext({ ...baseAnswers, ...prunedInstance }, undefined, now);

            const next: Record<string, boolean> = {};
            for (const field of members) {
                const ownOk = this.evaluateRelevance(field.relevant_expression, context);
                const skipped = this.anyUnitHolds(ruleSets[field.id]?.skip ?? [], context, fieldKeyById);
                if (ownOk && !skipped) {
                    next[field.key] = true;
                }
            }

            if (this.sameKeySet(next, relevant)) {
                break;
            }
            relevant = next;
        }

        const mask: Record<string, boolean> = {};
        for (const field of members) {
            mask[field.key] = Object.prototype.hasOwnProperty.call(relevant, field.key);
        }

        return [mask, this.pickRelevant(instanceAnswers, mask)];
    }

    private pickRelevant(answers: InstanceAnswers, mask: Record<string, boolean>): InstanceAnswers {
        const out: InstanceAnswers = {};
        for (const [key, value] of Object.entries(answers)) {
            if (mask[key] === true) {
                out[key] = value;
            }
        }

        return out;
    }

    /**
     * Evaluate every relevant top-level calculated field's formula over the full effective answers (flat +
     * repeat instance arrays, so a calc can `count(${section})`). A hidden calc, a non-calc field, and a
     * blank formula contribute nothing; a blank/NaN result is omitted rather than stored as null. Calculated
     * fields inside a repeatable section are NOT computed in G3 (they are repeat members, not top-level).
     */
    private computeCalculated(fields: SchemaField[], effectiveAnswers: Record<string, EngineValue | InstanceAnswers[]>, fieldRelevance: Record<string, boolean>, now: string | null): Record<string, EngineValue> {
        const computed: Record<string, EngineValue> = {};
        const context = new EvaluationContext(effectiveAnswers as Answers, undefined, now);

        for (const field of fields) {
            if (field.field_type !== 'calculated') {
                continue;
            }
            if (fieldRelevance[field.key] !== true) {
                continue;
            }

            const formula = field.calculate ?? null;
            if (formula === null || formula.trim() === '') {
                continue;
            }

            const value = this.evaluator.evaluate(formula, context);
            if (value !== null) {
                computed[field.key] = value;
            }
        }

        return computed;
    }

    private minInstancesMessage(min: number): string {
        return `Provide at least ${min} ${min === 1 ? 'entry' : 'entries'}.`;
    }

    private maxInstancesMessage(max: number): string {
        return `Provide at most ${max} ${max === 1 ? 'entry' : 'entries'}.`;
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
