<script setup lang="ts">
// The filters of a chart (M236). Each row is a field, a comparison and — for the comparisons that take one —
// a value: a pick-list where the server knows the field's values, a number for a number, and plain text
// otherwise.
//
// The component owns no state. It emits the whole next list on every change, so the builder holds one
// declaration and there is nothing here to fall out of step with it.
import { computed } from 'vue';
import { MdsButton, MdsCheckbox, MdsIconButton, MdsNumberInput, MdsSelect, MdsTextInput } from '@meridian/design-system';
import { fieldGroups, isFilterable, operatorApplies } from './definition';
import type { BuilderOptions, Field, Filter, FilterOperator } from './types';

const props = defineProps<{
    filters: Filter[];
    fields: Field[];
    operators: BuilderOptions['operators'];
    max: number;
}>();

const emit = defineEmits<{ change: [filters: Filter[]] }>();

const groups = computed(() => fieldGroups(props.fields, isFilterable));
const byKey = computed(() => new Map(props.fields.map((field) => [field.key, field])));
const canAdd = computed(() => props.filters.length < props.max && groups.value.length > 0);

function operatorsFor(fieldKey: string): { value: string; label: string }[] {
    const field = byKey.value.get(fieldKey);

    return field === undefined
        ? []
        : props.operators
              .filter((operator) => operatorApplies(operator.value, field.type))
              .map((operator) => ({ value: operator.value, label: operator.label }));
}

function takesValues(operator: FilterOperator): boolean {
    return props.operators.find((o) => o.value === operator)?.takes_values ?? true;
}

function replace(index: number, next: Filter): void {
    emit('change', props.filters.map((filter, i) => (i === index ? next : filter)));
}

function add(): void {
    const first = props.fields.find(isFilterable);

    if (first === undefined) return;

    const operator = (operatorsFor(first.key)[0]?.value ?? 'in') as FilterOperator;

    emit('change', [...props.filters, { field: first.key, operator, values: [] }]);
}

function remove(index: number): void {
    emit('change', props.filters.filter((_, i) => i !== index));
}

/** A new field resets the comparison and the value: neither means anything on another field. */
function setField(index: number, fieldKey: string): void {
    const operator = (operatorsFor(fieldKey)[0]?.value ?? 'in') as FilterOperator;

    replace(index, { field: fieldKey, operator, values: [] });
}

function setOperator(index: number, operator: string): void {
    const filter = props.filters[index];
    const next = operator as FilterOperator;
    const keepsValues = takesValues(next) && takesValues(filter.operator) && (next === 'in' || next === 'not_in') === (filter.operator === 'in' || filter.operator === 'not_in');

    replace(index, { ...filter, operator: next, values: keepsValues ? filter.values : [] });
}

function toggleValue(index: number, value: string, on: boolean): void {
    const filter = props.filters[index];
    const values = on ? [...filter.values.filter((v) => v !== value), value] : filter.values.filter((v) => v !== value);

    replace(index, { ...filter, values });
}

/** Free text is a comma-separated list: "north, south" filters on the two. */
function setText(index: number, text: string): void {
    const values = text
        .split(',')
        .map((value) => value.trim())
        .filter((value) => value !== '');

    replace(index, { ...props.filters[index], values });
}

function setNumber(index: number, value: number | null): void {
    replace(index, { ...props.filters[index], values: value === null ? [] : [String(value)] });
}
</script>

<template>
    <div class="explore-filters">
        <p v-if="filters.length === 0" class="explore-filters__none">No filters: every row in the range is counted.</p>

        <fieldset v-for="(filter, index) in filters" :key="index" class="explore-filters__row">
            <legend class="explore-filters__legend">Filter {{ index + 1 }}</legend>

            <div class="explore-filters__head">
                <MdsSelect
                    :model-value="filter.field"
                    :groups="groups"
                    :aria-label="`Filter ${index + 1}: field`"
                    @update:model-value="(v: string) => setField(index, v)"
                />
                <MdsIconButton icon="trash" :label="`Remove filter ${index + 1}`" size="sm" @click="remove(index)" />
            </div>

            <MdsSelect
                :model-value="filter.operator"
                :options="operatorsFor(filter.field)"
                :aria-label="`Filter ${index + 1}: comparison`"
                @update:model-value="(v: string) => setOperator(index, v)"
            />

            <template v-if="takesValues(filter.operator)">
                <MdsNumberInput
                    v-if="byKey.get(filter.field)?.type === 'number'"
                    :model-value="filter.values.length > 0 ? Number(filter.values[0]) : null"
                    :aria-label="`Filter ${index + 1}: value`"
                    @update:model-value="(v: number | null) => setNumber(index, v)"
                />

                <div
                    v-else-if="(byKey.get(filter.field)?.options ?? []).length > 0"
                    class="explore-filters__choices"
                    role="group"
                    :aria-label="`Filter ${index + 1}: values`"
                >
                    <MdsCheckbox
                        v-for="option in byKey.get(filter.field)?.options ?? []"
                        :key="option.value"
                        :label="option.label"
                        :model-value="filter.values.includes(option.value)"
                        @update:model-value="(on: boolean) => toggleValue(index, option.value, on)"
                    />
                </div>

                <MdsTextInput
                    v-else
                    :model-value="filter.values.join(', ')"
                    placeholder="One value, or several separated by commas"
                    :aria-label="`Filter ${index + 1}: values`"
                    @update:model-value="(v: string) => setText(index, v)"
                />
            </template>
        </fieldset>

        <MdsButton v-if="canAdd" variant="tertiary" size="sm" icon-left="plus" @click="add">Add a filter</MdsButton>
    </div>
</template>

<style scoped>
.explore-filters {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--mds-space-3);
}

.explore-filters__none {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.explore-filters__row {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    width: 100%;
    min-width: 0;
    margin: 0;
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

.explore-filters__legend {
    padding: 0 var(--mds-space-1);
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.explore-filters__head {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: var(--mds-space-2);
}

.explore-filters__choices {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    max-height: 12rem;
    overflow-y: auto;
}
</style>
