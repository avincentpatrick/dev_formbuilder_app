<script setup lang="ts">
/**
 * Config sub-editor for a field's validation rules (data-dictionary §6). Each row is EITHER a structured
 * rule (type + optional operator + value) OR a free-text expression — mirroring the DB's expression-XOR-
 * rule_type CHECK. Expressions are persisted UNVALIDATED here; the expression engine (ADR-0004) evaluates
 * them later. Emits a fresh array on every change so the store records one debounced history entry.
 */
import { MdsButton, MdsIconButton, MdsSelect, MdsTextInput, MdsTextarea } from '@meridian/design-system';
import type { BuilderValidation, EnumOption } from './types';

const props = defineProps<{
    validations: BuilderValidation[];
    ruleTypes: EnumOption[];
    operators: EnumOption[];
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:validations': [value: BuilderValidation[]] }>();

const FIELD_COMPARISON = new Set(['greater_than_field', 'less_than_field']);

function mode(row: BuilderValidation): 'rule' | 'expression' {
    return row.expression !== null && row.expression !== '' ? 'expression' : 'rule';
}

function update(index: number, patch: Partial<BuilderValidation>): void {
    emit(
        'update:validations',
        props.validations.map((row, i) => (i === index ? { ...row, ...patch } : row)),
    );
}

function setMode(index: number, next: 'rule' | 'expression'): void {
    update(
        index,
        next === 'expression'
            ? { expression: '', rule_type: null, operator: null, rule_value: null, related_field_key: null }
            : { expression: null, rule_type: props.ruleTypes[0]?.value ?? null },
    );
}

function addRule(): void {
    emit('update:validations', [
        ...props.validations,
        {
            rule_type: props.ruleTypes[0]?.value ?? null,
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
                    :options="ruleTypes"
                    :disabled="disabled"
                    :aria-label="`Rule ${i + 1} check`"
                    @update:model-value="update(i, { rule_type: $event })"
                />
                <MdsSelect
                    :model-value="row.operator ?? ''"
                    :options="operators"
                    placeholder="Operator (optional)"
                    :disabled="disabled"
                    :aria-label="`Rule ${i + 1} operator`"
                    @update:model-value="update(i, { operator: $event || null })"
                />
                <MdsTextInput
                    v-if="row.rule_type && FIELD_COMPARISON.has(row.rule_type)"
                    :model-value="row.related_field_key ?? ''"
                    placeholder="Compared field key"
                    :disabled="disabled"
                    :aria-label="`Rule ${i + 1} compared field key`"
                    @update:model-value="update(i, { related_field_key: $event || null })"
                />
                <MdsTextInput
                    v-else
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

        <p v-if="validations.length === 0" class="validations__empty">
            No validation rules. Add one to constrain what respondents can enter.
        </p>
        <div>
            <MdsButton variant="tertiary" size="sm" icon-left="plus" :disabled="disabled" @click="addRule">
                Add rule
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
