<script setup lang="ts">
/**
 * Config sub-editor for the option list of choice-style fields (single/multi/dropdown/likert). Each row
 * is a value + display label; the runtime option rendering follows the form engine, but authors define
 * the set here. Emits a fresh array on every change so the store records one debounced history entry.
 */
import { MdsButton, MdsIconButton, MdsTextInput } from '@meridian/design-system';

interface Choice {
    value: string;
    label: string;
}

const props = defineProps<{ options: Choice[]; disabled?: boolean }>();
const emit = defineEmits<{ 'update:options': [value: Choice[]] }>();

function update(index: number, patch: Partial<Choice>): void {
    const next = props.options.map((opt, i) => (i === index ? { ...opt, ...patch } : opt));
    emit('update:options', next);
}
function add(): void {
    const n = props.options.length + 1;
    emit('update:options', [...props.options, { value: `option_${n}`, label: `Option ${n}` }]);
}
function remove(index: number): void {
    emit('update:options', props.options.filter((_, i) => i !== index));
}
</script>

<template>
    <div class="choices">
        <div class="choices__head">
            <span class="choices__col">Value</span>
            <span class="choices__col">Label</span>
            <span class="choices__spacer" aria-hidden="true"></span>
        </div>
        <div v-for="(opt, i) in options" :key="i" class="choices__row">
            <MdsTextInput
                :model-value="opt.value"
                :disabled="disabled"
                :aria-label="`Option ${i + 1} value`"
                @update:model-value="update(i, { value: $event })"
            />
            <MdsTextInput
                :model-value="opt.label"
                :disabled="disabled"
                :aria-label="`Option ${i + 1} label`"
                @update:model-value="update(i, { label: $event })"
            />
            <MdsIconButton
                icon="trash"
                :label="`Remove option ${i + 1}`"
                variant="danger"
                size="sm"
                :disabled="disabled"
                @click="remove(i)"
            />
        </div>
        <p v-if="options.length === 0" class="choices__empty">No options yet.</p>
        <div>
            <MdsButton variant="tertiary" size="sm" icon-left="plus" :disabled="disabled" @click="add">
                Add option
            </MdsButton>
        </div>
    </div>
</template>

<style scoped>
.choices {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.choices__head,
.choices__row {
    display: grid;
    grid-template-columns: 1fr 1fr 28px;
    gap: var(--mds-space-2);
    align-items: center;
}

.choices__col {
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.choices__empty {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
}
</style>
