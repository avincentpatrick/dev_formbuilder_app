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

import { ABSENT, isEmpty, toNumber, toStr, type EngineValue, type MaybeAbsent } from './coercion';
import { EvaluationContext, type Answers } from './context';
import { ExpressionEvaluator, makeExpressionEvaluator } from './evaluator';
import { StructuredRuleLowering, type FieldKeysById } from './lowering';
import { StructuredRuleEvaluator } from './structured-rule-evaluator';
import type { CompositeAnswer, GeoAnswer, InstanceAnswers, SchemaField, SemanticInput, ValidationRow } from './schema';

/** The full effective-answers map: scalars/lists, repeat instances (G1), composite grids (G4b), geo (G5b1). */
type EffectiveAnswers = Record<string, EngineValue | InstanceAnswers[] | CompositeAnswer | GeoAnswer>;

const DEFAULT_CONSTRAINT_MESSAGE = 'This value is not valid.';
const DEFAULT_REQUIRED_MESSAGE = 'This field is required.';

export interface SemanticError {
    fieldKey: string;
    rule: string;
    message: string;
    /** Repeat-group address (Increment G1): the owning section + 0-based instance; null on a flat error. */
    sectionKey?: string | null;
    instanceIndex?: number | null;
    /** Sub-field address (Increment G4): a cascading level index (later a matrix cell); null on a whole-field error. */
    cellPath?: string | null;
}

/**
 * The stable address the surface + the 422 envelope key on: `field`, `section`, `section[i].field`, or any
 * of those suffixed with `.cellPath` for a sub-field (composite) failure.
 */
export function errorPath(error: SemanticError): string {
    const base = baseAddress(error);
    const cellPath = error.cellPath ?? null;

    return cellPath === null ? base : `${base}.${cellPath}`;
}

function baseAddress(error: SemanticError): string {
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
    readonly effectiveAnswers: EffectiveAnswers;
    readonly computed: Record<string, EngineValue>;
    readonly repeatFieldRelevance: Record<string, Record<string, boolean>[]>;

    constructor(
        fieldRelevance: Record<string, boolean>,
        sectionRelevance: Record<string, boolean>,
        errors: SemanticError[],
        effectiveAnswers: EffectiveAnswers,
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

        const effectiveAnswers: EffectiveAnswers = { ...flatEffective };
        for (const [sectionKey, instances] of Object.entries(repeatEffective)) {
            effectiveAnswers[sectionKey] = instances;
        }

        // Composite (grid) fields (Increment G4b) are validated + pruned in their own pass, never routed
        // through the scalar collectFieldErrors path (isEmpty diverges on an empty object between the engines:
        // PHP isEmpty([]) === true vs TS isEmpty({}) === false). The pruned object replaces the raw answer.
        const compositeErrors = this.processComposites(topLevelFields, fieldRelevance, effectiveAnswers, ruleSets, fieldKeyById, input.locale, now);

        // Geospatial fields (Increment G5b1) are validated in their own pass (same reason as composites: an
        // object answer must not hit scalar isEmpty). Structural geometry checks only — no grammar change; an
        // empty envelope is deleted so effective answers stay byte-identical across the engines ({} vs []).
        const geoErrors = this.processGeo(topLevelFields, fieldRelevance, effectiveAnswers, ruleSets, fieldKeyById, input.locale, now);

        // Calculated fields (grammar v2.0) are computed last, over the full effective answers (flat + repeat
        // instance arrays merged above, so a calc can `count(${section})`). See computeCalculated().
        const computed = this.computeCalculated(topLevelFields, effectiveAnswers, fieldRelevance, now);

        return new SemanticResult(fieldRelevance, sectionRelevance, [...errors, ...repeatErrors, ...compositeErrors, ...geoErrors], effectiveAnswers, computed, repeatRelevance);
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

    private answersForRelevant(answers: EffectiveAnswers, relevant: Record<string, boolean>): Answers {
        const out: Answers = {};
        for (const [key, value] of Object.entries(answers)) {
            if (Object.prototype.hasOwnProperty.call(relevant, key)) {
                out[key] = value as EngineValue;
            }
        }

        return out;
    }

    private effectiveAnswers(answers: EffectiveAnswers, fieldRelevance: Record<string, boolean>): Answers {
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

        // Composite (grid) fields (Increment G4b) hold object answers — validated by processComposites, never
        // here, where isEmpty on the whole object would diverge from the PHP authority.
        if (field.field_type === 'matrix' || field.field_type === 'likert_matrix') {
            return;
        }

        // Geospatial fields (Increment G5b1) hold an object-valued GeoJSON envelope — validated by processGeo,
        // never here, for the same reason (isEmpty on the object diverges from the PHP authority).
        if (field.field_type === 'geopoint' || field.field_type === 'geotrace' || field.field_type === 'geoshape') {
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

        this.collectMembershipErrors(field, answer, errors, sectionKey, instanceIndex);
    }

    /**
     * Choice-membership + cascading integrity for an answered, relevant choice-family field (Increment G4a) —
     * the byte-identical mirror of PHP `SemanticValidator::collectMembershipErrors`. A single/dropdown/
     * likert_scale/multi_select answer must be drawn from `field.options`; a cascading answer's per-level
     * values must each be a known option at that level whose `parent` matches the previous level's choice.
     * Enforced only when the field declares an option set (an unconfigured field checks nothing, keeping
     * every pre-G4a golden vector byte-identical). Errors carry the repeat address + (cascading) a per-level
     * `cellPath`.
     */
    private collectMembershipErrors(field: SchemaField, answer: MaybeAbsent, errors: SemanticError[], sectionKey: string | null, instanceIndex: number | null): void {
        switch (field.field_type) {
            case 'single_select':
            case 'dropdown':
            case 'likert_scale': {
                const allowed = field.options ?? [];
                if (allowed.length === 0 || Array.isArray(answer)) {
                    return;
                }
                if (!allowed.includes(toStr(answer))) {
                    errors.push({ fieldKey: field.key, rule: 'choice_not_in_options', message: 'The selected option is not available.', sectionKey, instanceIndex });
                }

                return;
            }

            case 'multi_select': {
                const allowed = field.options ?? [];
                if (allowed.length === 0 || !Array.isArray(answer)) {
                    return;
                }
                for (const element of answer) {
                    if (!allowed.includes(toStr(element))) {
                        errors.push({ fieldKey: field.key, rule: 'choice_not_in_options', message: 'A selected option is not available.', sectionKey, instanceIndex });

                        return; // one membership error per field is enough
                    }
                }

                return;
            }

            case 'cascading_select':
                this.collectCascadeErrors(field, answer, errors, sectionKey, instanceIndex);

                return;

            default:
                return;
        }
    }

    /**
     * Cascading select (G4a) — the mirror of PHP `collectCascadeErrors`. The answer is an ordered list of one
     * chosen value per level; each must be a known option at its positional level, and (below the root) its
     * option's `parent` must equal the previous level's chosen value. Per-level errors are addressed by the
     * 0-based level index (`cellPath`). Skipped when the field declares no levels/options; an interior blank
     * level is not itself a violation.
     */
    private collectCascadeErrors(field: SchemaField, answer: MaybeAbsent, errors: SemanticError[], sectionKey: string | null, instanceIndex: number | null): void {
        const cascade = field.cascade ?? null;
        if (cascade === null || cascade.levels.length === 0 || cascade.options.length === 0 || !Array.isArray(answer)) {
            return;
        }

        const byLevel: Record<string, Record<string, string | null>> = {};
        for (const option of cascade.options) {
            const level = toStr(option.level);
            const value = toStr(option.value);
            const parent = option.parent === null || option.parent === undefined ? null : toStr(option.parent);
            (byLevel[level] ??= {})[value] = parent;
        }

        answer.forEach((rawValue, i) => {
            const value = toStr(rawValue);
            if (value === '') {
                return;
            }

            const levelKey = cascade.levels[i] ?? null;
            const optionsAtLevel = levelKey !== null ? (byLevel[levelKey] ?? {}) : {};
            if (!Object.prototype.hasOwnProperty.call(optionsAtLevel, value)) {
                errors.push({ fieldKey: field.key, rule: 'cascading_choice_invalid', message: 'This selection is not available at this level.', sectionKey, instanceIndex, cellPath: String(i) });

                return;
            }

            const expectedParent = optionsAtLevel[value];
            const previous = i > 0 ? toStr(answer[i - 1]) : null;
            if (i > 0 && expectedParent !== null && expectedParent !== previous) {
                errors.push({ fieldKey: field.key, rule: 'cascading_parent_mismatch', message: 'This selection does not belong under the parent choice.', sectionKey, instanceIndex, cellPath: String(i) });
            }
        });
    }

    /**
     * Validate every relevant top-level composite (grid) field (Increment G4b) — the byte-identical mirror of
     * PHP `SemanticValidator::processComposites`. `matrix` holds `{row:{col:cell}}`, `likert_matrix` holds
     * `{row:score}`. The object is NEVER routed through the scalar path (`isEmpty` diverges on an empty
     * object between the engines). Instead this reads the field's declared rows/columns/cells, prunes the
     * submitted object to the KNOWN cells (dropping empties, coercing each surviving scalar) IN CONFIG ORDER
     * — so both engines emit identical key ordering — and applies required-completeness (a required grid
     * demands every row (likert) / every row×column cell (matrix) be answered → per-cell `field_required`
     * addressed by `cellPath`) + cell-membership (`choice_not_in_options`). The pruned object replaces the
     * field's raw effective answer, or the key is deleted when nothing survives. Object-emptiness is decided
     * by counting surviving cells, never by `isEmpty(object)`.
     */
    private processComposites(
        fields: SchemaField[],
        fieldRelevance: Record<string, boolean>,
        effectiveAnswers: EffectiveAnswers,
        ruleSets: RuleSets,
        fieldKeyById: FieldKeysById,
        locale: string,
        now: string | null,
    ): SemanticError[] {
        const errors: SemanticError[] = [];
        const context = new EvaluationContext(effectiveAnswers as Answers, undefined, now);

        for (const field of fields) {
            if (field.field_type !== 'matrix' && field.field_type !== 'likert_matrix') {
                continue;
            }
            if (fieldRelevance[field.key] !== true) {
                continue; // hidden composite: already pruned from effectiveAnswers, enforce nothing
            }

            const sets: Partial<RuleFamilies> = ruleSets[field.id] ?? {};
            const [required, trigger] = this.requiredState(field, sets.required ?? [], context, fieldKeyById);
            const requiredMessage = trigger !== null
                ? this.resolveMessage(trigger, locale, DEFAULT_REQUIRED_MESSAGE)
                : DEFAULT_REQUIRED_MESSAGE;

            const raw = effectiveAnswers[field.key];
            const pruned = field.field_type === 'matrix'
                ? this.processMatrix(field, raw, required, requiredMessage, errors)
                : this.processLikertMatrix(field, raw, required, requiredMessage, errors);

            if (Object.keys(pruned).length === 0) {
                delete effectiveAnswers[field.key];
            } else {
                effectiveAnswers[field.key] = pruned;
            }
        }

        return errors;
    }

    /**
     * Likert-matrix (`{row:score}`): each declared row must carry a score drawn from the column scale.
     * Returns the pruned `{row:score}` (empty when nothing survives). `cellPath = "<rowKey>"`.
     */
    private processLikertMatrix(field: SchemaField, raw: unknown, required: boolean, requiredMessage: string, errors: SemanticError[]): Record<string, EngineValue> {
        const rowKeys = this.compositeValues(field, 'rows');
        const scoreSet = new Set(this.compositeValues(field, 'columns'));
        const rawMap = this.asObjectMap(raw);

        const pruned: Record<string, EngineValue> = {};
        for (const rowKey of rowKeys) {
            const has = Object.prototype.hasOwnProperty.call(rawMap, rowKey);
            const score = (has ? rawMap[rowKey] : ABSENT) as MaybeAbsent;
            if (!has || isEmpty(score)) {
                if (required) {
                    errors.push({ fieldKey: field.key, rule: 'field_required', message: requiredMessage, sectionKey: null, instanceIndex: null, cellPath: rowKey });
                }

                continue;
            }

            const value = toStr(score);
            pruned[rowKey] = value;
            if (scoreSet.size > 0 && !scoreSet.has(value)) {
                errors.push({ fieldKey: field.key, rule: 'choice_not_in_options', message: 'The selected option is not available.', sectionKey: null, instanceIndex: null, cellPath: rowKey });
            }
        }

        return pruned;
    }

    /**
     * Matrix (`{row:{col:cell}}`): each declared row×column cell may carry a value drawn from the shared cell
     * choice pool. Returns the pruned `{row:{col:cell}}` (rows with no surviving cell dropped).
     * `cellPath = "<rowKey>.<colKey>"`.
     */
    private processMatrix(field: SchemaField, raw: unknown, required: boolean, requiredMessage: string, errors: SemanticError[]): Record<string, Record<string, EngineValue>> {
        const rowKeys = this.compositeValues(field, 'rows');
        const colKeys = this.compositeValues(field, 'columns');
        const cellSet = new Set(this.compositeValues(field, 'cells'));
        const rawMap = this.asObjectMap(raw);

        const pruned: Record<string, Record<string, EngineValue>> = {};
        for (const rowKey of rowKeys) {
            const rowMap = this.asObjectMap(rawMap[rowKey]);
            const prunedRow: Record<string, EngineValue> = {};
            for (const colKey of colKeys) {
                const has = Object.prototype.hasOwnProperty.call(rowMap, colKey);
                const cell = (has ? rowMap[colKey] : ABSENT) as MaybeAbsent;
                if (!has || isEmpty(cell)) {
                    if (required) {
                        errors.push({ fieldKey: field.key, rule: 'field_required', message: requiredMessage, sectionKey: null, instanceIndex: null, cellPath: `${rowKey}.${colKey}` });
                    }

                    continue;
                }

                const value = toStr(cell);
                prunedRow[colKey] = value;
                if (cellSet.size > 0 && !cellSet.has(value)) {
                    errors.push({ fieldKey: field.key, rule: 'choice_not_in_options', message: 'The selected option is not available.', sectionKey: null, instanceIndex: null, cellPath: `${rowKey}.${colKey}` });
                }
            }

            if (Object.keys(prunedRow).length > 0) {
                pruned[rowKey] = prunedRow;
            }
        }

        return pruned;
    }

    /**
     * The ordered `grid.<key>` value list (rows/columns/cells) for a composite field, de-duplicated + empties
     * dropped — the mirror of PHP `compositeValues` (which reads `config.<key>[].value`). Drives both the
     * config-ordered prune and the membership sets.
     */
    private compositeValues(field: SchemaField, key: 'rows' | 'columns' | 'cells'): string[] {
        const list = field.grid?.[key] ?? [];
        const seen = new Set<string>();
        const out: string[] = [];
        for (const value of list) {
            if (value !== '' && !seen.has(value)) {
                seen.add(value);
                out.push(value);
            }
        }

        return out;
    }

    /** Coerce a raw answer to an object map, mirror-safe: a non-object / list / null becomes `{}` (empty). */
    private asObjectMap(value: unknown): Record<string, unknown> {
        return value !== null && typeof value === 'object' && !Array.isArray(value)
            ? (value as Record<string, unknown>)
            : {};
    }

    /**
     * Geospatial pass (Increment G5b1 / ADR-0006), the object-valued sibling of processComposites and the
     * mirror of PHP `processGeo`. Each relevant geo field's GeoJSON envelope is validated structurally
     * (never through scalar isEmpty): the geometry `type` must match the field, coordinates must be in
     * range, a line needs ≥ 2 points, a polygon ring needs ≥ 4 points and must be closed. A
     * structurally-empty envelope is a required error (if required) and is deleted so effective answers
     * stay identical across the engines ({} in TS vs [] in PHP). Whole-field faults → no cellPath.
     */
    private processGeo(
        fields: SchemaField[],
        fieldRelevance: Record<string, boolean>,
        effectiveAnswers: EffectiveAnswers,
        ruleSets: RuleSets,
        fieldKeyById: FieldKeysById,
        locale: string,
        now: string | null,
    ): SemanticError[] {
        const errors: SemanticError[] = [];
        const context = new EvaluationContext(effectiveAnswers as Answers, undefined, now);

        for (const field of fields) {
            if (field.field_type !== 'geopoint' && field.field_type !== 'geotrace' && field.field_type !== 'geoshape') {
                continue;
            }
            if (fieldRelevance[field.key] !== true) {
                continue; // hidden geo: already pruned from effectiveAnswers, enforce nothing
            }

            const sets: Partial<RuleFamilies> = ruleSets[field.id] ?? {};
            const [required, trigger] = this.requiredState(field, sets.required ?? [], context, fieldKeyById);
            const requiredMessage = trigger !== null
                ? this.resolveMessage(trigger, locale, DEFAULT_REQUIRED_MESSAGE)
                : DEFAULT_REQUIRED_MESSAGE;

            const raw = effectiveAnswers[field.key];
            if (!this.validateGeo(field, raw, required, requiredMessage, errors)) {
                delete effectiveAnswers[field.key]; // empty envelope — keep engines byte-identical
            }
        }

        return errors;
    }

    /**
     * Validate one geo envelope; append any faults. Returns whether the answer is a non-empty envelope
     * (kept in effective answers) vs structurally empty (the caller deletes it). `geopoint` → `Point`,
     * `geotrace` → `LineString` (≥ 2 pts), `geoshape` → closed `Polygon` (≥ 4 pts, first == last).
     */
    private validateGeo(field: SchemaField, raw: unknown, required: boolean, requiredMessage: string, errors: SemanticError[]): boolean {
        const expectedType = field.field_type === 'geotrace' ? 'LineString' : field.field_type === 'geoshape' ? 'Polygon' : 'Point';

        // Structural emptiness — decided WITHOUT scalar isEmpty (which diverges on {} across engines).
        const envelope = this.asObjectMap(raw);
        const coordinates = envelope.coordinates;
        if (!Array.isArray(coordinates) || coordinates.length === 0) {
            if (required) {
                errors.push({ fieldKey: field.key, rule: 'field_required', message: requiredMessage, sectionKey: null, instanceIndex: null });
            }

            return false;
        }

        if (envelope.type !== expectedType) {
            errors.push({ fieldKey: field.key, rule: 'geo_type_mismatch', message: 'This location value has the wrong geometry type.', sectionKey: null, instanceIndex: null });

            return true;
        }

        let positions: unknown[];
        if (expectedType === 'Point') {
            positions = [coordinates];
        } else if (expectedType === 'LineString') {
            positions = coordinates;
            if (positions.length < 2) {
                errors.push({ fieldKey: field.key, rule: 'geo_too_few_points', message: 'A line needs at least two points.', sectionKey: null, instanceIndex: null });
            }
        } else { // Polygon — validate the first (outer) ring
            const ring = coordinates[0];
            positions = Array.isArray(ring) ? ring : [];
            if (positions.length < 4) {
                errors.push({ fieldKey: field.key, rule: 'geo_too_few_points', message: 'An area needs at least four points to close a ring.', sectionKey: null, instanceIndex: null });
            } else if (!this.samePosition(positions[0], positions[positions.length - 1])) {
                errors.push({ fieldKey: field.key, rule: 'geo_not_closed', message: 'The area boundary must be closed: the first and last points must match.', sectionKey: null, instanceIndex: null });
            }
        }

        for (const position of positions) {
            if (!this.positionInRange(position)) {
                errors.push({ fieldKey: field.key, rule: 'geo_out_of_range', message: 'A coordinate is out of range (longitude ±180, latitude ±90).', sectionKey: null, instanceIndex: null });
                break;
            }
        }

        return true;
    }

    /**
     * A `[lon, lat]` position is in range when both ordinates are numeric and within WGS84 bounds. A
     * non-array position or a non-numeric ordinate (→ NaN via the shared Coercion primitive, identical in
     * the PHP authority) is out of range.
     */
    private positionInRange(position: unknown): boolean {
        if (!Array.isArray(position)) {
            return false;
        }

        const lon = toNumber(position[0] as MaybeAbsent);
        const lat = toNumber(position[1] as MaybeAbsent);

        if (Number.isNaN(lon) || Number.isNaN(lat)) {
            return false;
        }

        return lon >= -180 && lon <= 180 && lat >= -90 && lat <= 90;
    }

    /**
     * Two positions are equal when their numeric lon/lat coincide (NaN never equals NaN, so a malformed
     * endpoint reads as unequal — identical to the PHP authority).
     */
    private samePosition(a: unknown, b: unknown): boolean {
        if (!Array.isArray(a) || !Array.isArray(b)) {
            return false;
        }

        return toNumber(a[0] as MaybeAbsent) === toNumber(b[0] as MaybeAbsent)
            && toNumber(a[1] as MaybeAbsent) === toNumber(b[1] as MaybeAbsent);
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
    private computeCalculated(fields: SchemaField[], effectiveAnswers: EffectiveAnswers, fieldRelevance: Record<string, boolean>, now: string | null): Record<string, EngineValue> {
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
