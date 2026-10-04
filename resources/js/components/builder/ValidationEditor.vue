<script setup lang="ts">
/**
 * Config sub-editor for a field's validation rules (data-dictionary §6). Each row is EITHER a structured
 * rule (type + optional operator + value) OR a free-text expression — mirroring the DB's expression-XOR-
 * rule_type CHECK. Expressions are persisted UNVALIDATED here; the expression engine (ADR-0004) evaluates
 * them later. Emits a fresh array on every change so the store records one debounced history entry.
 *
 * ⛔ WHAT M115 CHANGED, AND WHY IT IS NOT A DISPLAY TWEAK. This editor used to offer all eleven rule types
 * and all eight operators to all thirty-one field types, labelled `Gt` / `Lte` / `Neq`. Since M113 the
 * publish gate REFUSES the combinations that cannot work — `min_value` on a date fails closed, making the
 * field unanswerable — so the editor was inviting an author to build exactly the form that would then be
 * refused. It now offers what the field's `ValueShape` can actually take, from the same table the gate
 * refuses on (`ValueShape::allows()`, shipped through `BuilderPresenter::enums()` as `shapes`).
 *
 * ⛔ AND THE OPERATOR IS FILTERED BY THE *RELATED* FIELD, NOT THIS ONE. Measured at both lowerings: the
 * `operator` column is read only by the four conditional rule types, where it compares the value of the
 * field the rule NAMES (`StructuredRuleLowering::lowerCondition()` → `conditionForOperator()`, and its
 * TypeScript twin). For the other seven it is never read at all. So the control renders only where it is
 * read, and its options come from the compared field's shape.
 *
 * ⛔ THE FOUR CONDITIONAL RULES WERE UNCOMPLETABLE HERE BEFORE THIS INCREMENT. The compared-field input was
 * gated on a client-side literal naming `greater_than_field` / `less_than_field` only — so an author could
 * pick "Required when a condition holds" and had nowhere to say WHICH question. The rule then saved, passed
 * publish, and threw `missing_related_field` at evaluation, which the respondent met as a generic failure.
 * `ValidationRuleType::takesRelatedField()` replaces that literal; the publish-gate half is filed.
 *
 * ⚠️ NOTHING IS EVER HIDDEN OUT FROM UNDER A SAVED ROW. A value the filters would exclude but the row
 * already holds stays in its own `<select>`, disabled and labelled — because `MdsSelect` is a native
 * `<select>` bound with `:value`, so a model value absent from the options renders BLANK and the author
 * would be editing a rule they cannot see. Same posture as `ConditionRow.vue`'s disabled `selected()`
 * options, and the same reason.
 */
import { MdsButton, MdsIconButton, MdsSelect, MdsTextInput, MdsTextarea } from '@meridian/design-system';
import { computed } from 'vue';
import type { BuilderValidation, ComparableField, EnumOption, OperatorOption, RuleTypeOption } from './types';
import { mayCompare, operatorReads, repointPatch, ruleChangePatch } from './validation-options';

const props = withDefaults(
    defineProps<{
        validations: BuilderValidation[];
        ruleTypes: RuleTypeOption[];
        operators: OperatorOption[];
        /** The OWNING field's `ValueShape` — what may be asserted about the answer this rule constrains. */
        valueShape: string;
        /** Every other field in the draft, for the rules that name one. */
        comparableFields: ComparableField[];
        disabled?: boolean;
        /**
         * Narrow this instance to a subset of rule kinds (M116). Undefined — the Validation tab — means
         * every rule the shape allows, which is the behaviour every existing caller gets.
         *
         * ⛔ THIS IS WHY THE BASICS REVEAL IS NOT A SECOND EDITOR. The "Required when…" surface renders the
         * same row as the Validation tab minus the mode switch: the same rule select, the same compared
         * question, the same operator filtered by the RELATED field's shape, the same `is answered`
         * pseudo-option. Duplicating that would duplicate five option-building functions M115 spent an
         * increment establishing, and the two copies would disagree on the first change.
         *
         * Everything follows from the one intersection below: `addRule()`'s seed, `setMode()`'s fallback
         * and the Add button's disabled state all already read `allowedRuleTypes`.
         */
        restrictToRuleTypes?: string[];
        /** The Add button's wording, so a restricted instance can say what it adds. */
        addLabel?: string;
        /** The empty-state sentence. The default describes constraints, which is wrong under a condition. */
        emptyText?: string;
    }>(),
    {
        addLabel: 'Add rule',
        emptyText: 'No validation rules. Add one to constrain what respondents can enter.',
    },
);

const emit = defineEmits<{ 'update:validations': [value: BuilderValidation[]] }>();

/** `value: ''` on a native <select> is the placeholder slot, so an "is answered" choice reads as empty. */
const OPERATOR_ANSWERED = '';

/** Appended to an option the current field type cannot take, so a saved row stays legible. */
const UNAVAILABLE = ' — not available for this question';

type SelectOption = EnumOption & { disabled?: boolean };

const allowedRuleTypes = computed<RuleTypeOption[]>(() =>
    props.ruleTypes.filter(
        (rule) =>
            rule.shapes.includes(props.valueShape) &&
            (props.restrictToRuleTypes === undefined || props.restrictToRuleTypes.includes(rule.value)),
    ),
);

/**
 * Whether this instance may hold a raw-expression row (M116). A restricted instance may not: an expression
 * row carries `rule_type: null`, so it can never belong to a restricted partition, and offering the switch
 * would let an author move a row out of the surface that owns it and into nothing.
 *
 * ⚠️ IT GATES THE MODE SELECT ONLY, NEVER THE ROW HEAD — the remove button lives in the same element and
 * must survive.
 */
const allowsExpression = computed<boolean>(() => props.restrictToRuleTypes === undefined);

function ruleTypeOf(row: BuilderValidation): RuleTypeOption | null {
    return props.ruleTypes.find((rule) => rule.value === row.rule_type) ?? null;
}

function shapeOfRelated(row: BuilderValidation): string | null {
    return props.comparableFields.find((field) => field.key === row.related_field_key)?.value_shape ?? null;
}

/**
 * The rule <select>'s options: what this field type can take, plus whatever the row already holds. The
 * second half is what keeps a rule saved before a type change (or before this increment) visible.
 */
function ruleOptionsFor(row: BuilderValidation): SelectOption[] {
    const options: SelectOption[] = allowedRuleTypes.value.map((rule) => ({ value: rule.value, label: rule.label }));

    if (row.rule_type !== null && !options.some((option) => option.value === row.rule_type)) {
        const current = ruleTypeOf(row);
        options.unshift({
            value: row.rule_type,
            label: (current?.label ?? row.rule_type) + UNAVAILABLE,
            disabled: true,
        });
    }

    return options;
}

/**
 * The compared-question options: only the questions this rule can be judged by (M131, `R-57711a3a`) — see
 * `validation-options.ts`. Publish refuses the rest, so offering them was inviting a refusal.
 */
function relatedOptionsFor(row: BuilderValidation): SelectOption[] {
    const rule = ruleTypeOf(row);
    const offered = rule === null ? props.comparableFields : props.comparableFields.filter((field) => mayCompare(rule, props.operators, field));
    const options: SelectOption[] = offered.map((field) => ({ value: field.key, label: field.label }));

    const key = row.related_field_key;

    if (key !== null && key !== '' && !options.some((o) => o.value === key)) {
        const existing = props.comparableFields.find((field) => field.key === key);

        // A key pointing at a field that has since been deleted or renamed, or one this rule can no longer be
        // judged by: shown rather than silently dropped, because the row is broken and the author is the only one
        // who can decide what it should say.
        options.unshift(
            existing === undefined
                ? { value: key, label: `${key} (deleted)`, disabled: true }
                : { value: key, label: existing.label + UNAVAILABLE, disabled: true },
        );
    }

    return options;
}

/**
 * The operator <select>'s options — the compared field's shape decides them, so the list is empty until a
 * field is named. Where an ABSENT operator is itself a condition, that choice is offered explicitly rather
 * than left as a blank nobody can read.
 */
function operatorOptionsFor(row: BuilderValidation): SelectOption[] {
    const rule = ruleTypeOf(row);
    const shape = shapeOfRelated(row);

    if (rule === null || shape === null) {
        return [];
    }

    const options: SelectOption[] = [];

    // "is answered" is the empty operator, judged as `is_null` — offered only where that can read the question.
    if (rule.operator_may_be_empty && operatorReads('is_null', shape, props.operators)) {
        options.push({ value: OPERATOR_ANSWERED, label: 'is answered' });
    }

    for (const operator of props.operators) {
        if (operator.shapes.includes(shape)) {
            options.push({ value: operator.value, label: operator.label });
        }
    }

    if (row.operator !== null && !options.some((option) => option.value === row.operator)) {
        const current = props.operators.find((operator) => operator.value === row.operator);
        options.unshift({
            value: row.operator,
            label: (current?.label ?? row.operator) + UNAVAILABLE,
            disabled: true,
        });
    }

    return options;
}

function operatorPlaceholderFor(row: BuilderValidation): string | undefined {
    if (shapeOfRelated(row) === null) {
        return 'Choose a question first';
    }

    // `required_if` / `skip_if` with no operator THROWS at evaluation, so the absence is a broken row and
    // not a default. Say so here instead of rendering an innocent blank.
    return ruleTypeOf(row)?.operator_may_be_empty === true ? undefined : 'Choose a comparison';
}

/** Whether this row still needs a literal to compare against. `is_null` asks nothing of a value. */
function takesRuleValue(row: BuilderValidation): boolean {
    const rule = ruleTypeOf(row);

    if (rule === null) {
        return true;
    }
    if (!rule.takes_operator) {
        // A constraint's threshold, or nothing at all for a rule that compares two fields.
        return !rule.takes_related_field;
    }

    return row.operator !== null && row.operator !== OPERATOR_ANSWERED && row.operator !== 'is_null';
}

function mode(row: BuilderValidation): 'rule' | 'expression' {
    return row.expression !== null && row.expression !== '' ? 'expression' : 'rule';
}

function update(index: number, patch: Partial<BuilderValidation>): void {
    emit(
        'update:validations',
        props.validations.map((row, i) => (i === index ? { ...row, ...patch } : row)),
    );
}

/**
 * Changing the rule kind clears the columns the new kind does not read. Leaving them would persist an
 * operator on a `pattern` row and a related field on a `min_length` one — columns no lowering reads, which
 * makes them dead data that reads as intent to the next person opening the row.
 */
function setRuleType(index: number, value: string): void {
    const rule = props.ruleTypes.find((candidate) => candidate.value === value) ?? null;

    // M131: the compared question survives only if the NEW kind can be judged by it, and the operator only if
    // the new kind reads one that can read that question — `required_if` on text → `greater_than_field` used to
    // keep the text question, which publish refuses.
    update(index, { ...ruleChangePatch(props.validations[index], rule, props.comparableFields, props.operators), rule_type: value });
}

/** Re-pointing clears an operator the new question cannot take, and the value it compared against (M131). */
function setRelated(index: number, key: string | null): void {
    update(index, repointPatch(props.validations[index], key, props.comparableFields, props.operators));
}

function setMode(index: number, next: 'rule' | 'expression'): void {
    update(
        index,
        next === 'expression'
            ? { expression: '', rule_type: null, operator: null, rule_value: null, related_field_key: null }
            : { expression: null, rule_type: allowedRuleTypes.value[0]?.value ?? null },
    );
}

function addRule(): void {
    emit('update:validations', [
        ...props.validations,
        {
            rule_type: allowedRuleTypes.value[0]?.value ?? null,
            operator: null,
            rule_value: null,
            expression: null,
            error_message: null,
            related_field_key: null,
            sequence: props.validations.length,
        },
    ]);
}

function remove(index: number): void {
    emit(
        'update:validations',
        props.validations.filter((_, i) => i !== index).map((row, i) => ({ ...row, sequence: i })),
    );
}

const modeOptions: EnumOption[] = [
    { value: 'rule', label: 'Structured rule' },
    { value: 'expression', label: 'Expression' },
];
</script>

<template>
    <div class="validations">
        <div v-for="(row, i) in validations" :key="i" class="validations__row">
            <div class="validations__head">
                <MdsSelect
                    v-if="allowsExpression"
                    class="validations__mode"
                    :model-value="mode(row)"
                    :options="modeOptions"
                    :disabled="disabled"
                    :aria-label="`Rule ${i + 1} type`"
                    @update:model-value="setMode(i, $event as 'rule' | 'expression')"
                />
                <MdsIconButton
                    icon="trash"
                    :label="`Remove rule ${i + 1}`"
                    variant="danger"
                    size="sm"
                    :disabled="disabled"
                    @click="remove(i)"
                />
            </div>

            <template v-if="mode(row) === 'rule'">
                <MdsSelect
                    :model-value="row.rule_type ?? ''"
                    :options="ruleOptionsFor(row)"
                    :disabled="disabled"
                    :aria-label="`Rule ${i + 1} check`"
                    @update:model-value="setRuleType(i, $event)"
                />
                <MdsSelect
                    v-if="ruleTypeOf(row)?.takes_related_field"
                    :model-value="row.related_field_key ?? ''"
                    :options="relatedOptionsFor(row)"
                    placeholder="Choose a question"
                    :disabled="disabled"
                    :aria-label="`Rule ${i + 1} compared field`"
                    @update:model-value="setRelated(i, $event || null)"
                />
                <MdsSelect
                    v-if="ruleTypeOf(row)?.takes_operator"
                    :model-value="row.operator ?? ''"
                    :options="operatorOptionsFor(row)"
                    :placeholder="operatorPlaceholderFor(row)"
                    :disabled="disabled || shapeOfRelated(row) === null"
                    :aria-label="`Rule ${i + 1} operator`"
                    @update:model-value="update(i, { operator: $event === '' ? null : $event })"
                />
                <MdsTextInput
                    v-if="takesRuleValue(row)"
                    :model-value="row.rule_value ?? ''"
                    placeholder="Value"
                    :disabled="disabled"
                    :aria-label="`Rule ${i + 1} value`"
                    @update:model-value="update(i, { rule_value: $event || null })"
                />
            </template>

            <MdsTextarea
                v-else
                :model-value="row.expression ?? ''"
                :rows="2"
                placeholder="e.g. ${age} >= 18"
                :disabled="disabled"
                :aria-label="`Rule ${i + 1} expression`"
                @update:model-value="update(i, { expression: $event })"
            />

            <MdsTextInput
                :model-value="row.error_message ?? ''"
                placeholder="Error message shown to the respondent"
                :disabled="disabled"
                :aria-label="`Rule ${i + 1} error message`"
                @update:model-value="update(i, { error_message: $event || null })"
            />
        </div>

        <p v-if="validations.length === 0" class="validations__empty">{{ emptyText }}</p>
        <div>
            <MdsButton
                variant="tertiary"
                size="sm"
                icon-left="plus"
                :disabled="disabled || allowedRuleTypes.length === 0"
                @click="addRule"
            >
                {{ addLabel }}
            </MdsButton>
        </div>
    </div>
</template>

<style scoped>
.validations {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.validations__row {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.validations__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

.validations__mode {
    max-width: 200px;
}

.validations__empty {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
}
</style>
