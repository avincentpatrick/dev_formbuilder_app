<script setup lang="ts" generic="T extends { value: string; label: string }">
/**
 * A single `{value, label}[]` list editor (Increment G4b) — the shared building block for the grid config
 * editors ({@link MatrixEditor}/{@link LikertMatrixEditor}): the rows, columns, and (matrix) cell-choice
 * lists are all this same value/label list. Emits a fresh array on every change so the builder store records
 * one debounced history entry (same lenient contract as ChoicesEditor/CascadingEditor — completeness +
 * distinctness are enforced at publish, not per keystroke).
 *
 * M183 (`R-5033e87e`): generic over the entry, so an edit keeps every key an entry already had (a matrix column's
 * type, choices and required flag), and a `details` slot draws an entry's own settings under its row.
 */
import { MdsButton, MdsIconButton, MdsTextInput } from '@meridian/design-system';

import { addGridEntry, patchGridEntry, removeGridEntry } from './grid-options';

const props = defineProps<{
    heading: string;
    singular: string;
    prefix: string;
    hint?: string;
    options: T[];
}>();
const emit = defineEmits<{ 'update:options': [value: T[]] }>();
defineSlots<{ details?: (props: { option: T; index: number }) => unknown }>();

// M185 (`R-7418e6bd`): the add / rename / remove rules live in `grid-options.ts`, which the Preview's grid editing shares.
function updateOption(index: number, patch: Partial<Pick<T, 'value' | 'label'>>): void {
    emit('update:options', patchGridEntry(props.options, index, patch));
}
function addOption(): void {
    emit('update:options', addGridEntry(props.options, props.prefix, props.singular));
}
function removeOption(index: number): void {
    emit('update:options', removeGridEntry(props.options, index));
}
</script>

<template>
    <section class="grid-list">
        <h3 class="grid-list__heading">{{ heading }}</h3>
        <p v-if="hint" class="grid-list__hint">{{ hint }}</p>
        <div class="grid-list__rows">
            <div v-for="(option, i) in options" :key="i" class="grid-list__item">
                <div class="grid-list__row">
                    <MdsTextInput
                        :model-value="option.value"
                        :aria-label="`${singular} ${i + 1} value`"
                        @update:model-value="updateOption(i, { value: $event })"
                    />
                    <MdsTextInput
                        :model-value="option.label"
                        :aria-label="`${singular} ${i + 1} label`"
                        @update:model-value="updateOption(i, { label: $event })"
                    />
                    <MdsIconButton
                        icon="trash"
                        :label="`Remove ${singular.toLowerCase()} ${i + 1}`"
                        variant="danger"
                        size="sm"
                        @click="removeOption(i)"
                    />
                </div>
                <slot name="details" :option="option" :index="i" />
            </div>
            <p v-if="options.length === 0" class="grid-list__empty">Nothing yet.</p>
        </div>
        <div>
            <MdsButton variant="tertiary" size="sm" icon-left="plus" @click="addOption">Add {{ singular.toLowerCase() }}</MdsButton>
        </div>
    </section>
</template>

<style scoped>
.grid-list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.grid-list__heading {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

.grid-list__hint {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.grid-list__rows {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.grid-list__item {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.grid-list__row {
    display: grid;
    grid-template-columns: 1fr 1fr 28px;
    gap: var(--mds-space-2);
    align-items: center;
}

.grid-list__empty {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
}
</style>
