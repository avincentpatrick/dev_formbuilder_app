<script setup lang="ts">
/**
 * A single `{value, label}[]` list editor (Increment G4b) — the shared building block for the grid config
 * editors ({@link MatrixEditor}/{@link LikertMatrixEditor}): the rows, columns, and (matrix) cell-choice
 * lists are all this same value/label list. Emits a fresh array on every change so the builder store records
 * one debounced history entry (same lenient contract as ChoicesEditor/CascadingEditor — completeness +
 * distinctness are enforced at publish, not per keystroke).
 */
import { MdsButton, MdsIconButton, MdsTextInput } from '@meridian/design-system';

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    heading: string;
    singular: string;
    prefix: string;
    hint?: string;
    options: Option[];
}>();
const emit = defineEmits<{ 'update:options': [value: Option[]] }>();

function updateOption(index: number, patch: Partial<Option>): void {
    emit(
        'update:options',
        props.options.map((option, i) => (i === index ? { ...option, ...patch } : option)),
    );
}
function addOption(): void {
    const existing = new Set(props.options.map((option) => option.value));
    let n = props.options.length + 1;
    while (existing.has(`${props.prefix}_${n}`)) n++;
    emit('update:options', [...props.options, { value: `${props.prefix}_${n}`, label: `${props.singular} ${n}` }]);
}
function removeOption(index: number): void {
    emit('update:options', props.options.filter((_, i) => i !== index));
}
</script>

<template>
    <section class="grid-list">
        <h3 class="grid-list__heading">{{ heading }}</h3>
        <p v-if="hint" class="grid-list__hint">{{ hint }}</p>
        <div class="grid-list__rows">
            <div v-for="(option, i) in options" :key="i" class="grid-list__row">
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
