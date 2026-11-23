<script setup lang="ts">
/**
 * Config sub-editor for the option list of choice-style fields (single/multi/dropdown/likert). Each row
 * is a value + display label; the runtime option rendering follows the form engine, but authors define
 * the set here. Emits a fresh array on every change so the store records one debounced history entry.
 *
 * M249 (`dropdown-placeholder`, `D233`): with `allowShortLabel` (the server's `short_label_types`), each row also takes a
 * SHORT LABEL — what a closed dropdown shows while that choice is chosen. A blank one removes the key, with any imported
 * translations of it, so a choice never given one saves exactly as before.
 */
import { computed } from 'vue';
import { MdsButton, MdsIconButton, MdsTextInput } from '@meridian/design-system';

interface Choice {
    value: string;
    label: string;
    short_label?: string;
    short_label_translations?: Record<string, string> | null;
}

const props = defineProps<{ options: Choice[]; disabled?: boolean; allowShortLabel?: boolean }>();
const emit = defineEmits<{ 'update:options': [value: Choice[]] }>();

const shortLabels = computed<boolean>(() => props.allowShortLabel === true);

function setShortLabel(index: number, text: string): void {
    const next = props.options.map((opt, i) => {
        if (i !== index) return opt;
        if (text !== '') return { ...opt, short_label: text };
        const { short_label: _short, short_label_translations: _translations, ...rest } = opt;
        return rest;
    });
    emit('update:options', next);
}

function update(index: number, patch: Partial<Choice>): void {
    const next = props.options.map((opt, i) => (i === index ? { ...opt, ...patch } : opt));
    emit('update:options', next);
}
function add(): void {
    // Pick a value not already in use — publish requires distinct option values (G4a), so a naive
    // length+1 (which can collide after a remove) must not reintroduce a duplicate.
    const existing = new Set(props.options.map((o) => o.value));
    let n = props.options.length + 1;
    while (existing.has(`option_${n}`)) n++;
    emit('update:options', [...props.options, { value: `option_${n}`, label: `Option ${n}` }]);
}
function remove(index: number): void {
    emit('update:options', props.options.filter((_, i) => i !== index));
}
// M177 (`R-0b7db001`, `D140`): Yes/No left the palette because a Single choice with these two covers it. The values are the
// ones the XLSForm export has always written for a Yes/No, so `= 'yes'` reads the same either way.
function addYesNo(): void {
    emit('update:options', [
        { value: 'yes', label: 'Yes' },
        { value: 'no', label: 'No' },
    ]);
}
</script>

<template>
    <div class="choices" :class="{ 'choices--short': shortLabels }">
        <div class="choices__head">
            <span class="choices__col">Value</span>
            <span class="choices__col">Label</span>
            <span v-if="shortLabels" class="choices__col">Short label</span>
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
            <MdsTextInput
                v-if="shortLabels"
                :model-value="opt.short_label ?? ''"
                :disabled="disabled"
                :aria-label="`Short form of option ${i + 1}`"
                data-choice-short-label
                @update:model-value="setShortLabel(i, $event)"
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
        <p v-else-if="shortLabels" class="choices__note" data-choices-short-note>
            A short label is what the closed dropdown shows once that choice is picked. The open list, screen readers, exports
            and paper keep the full label.
        </p>
        <div class="choices__actions">
            <MdsButton variant="tertiary" size="sm" icon-left="plus" :disabled="disabled" @click="add">
                Add option
            </MdsButton>
            <MdsButton v-if="options.length === 0" variant="tertiary" size="sm" :disabled="disabled" data-choices-yes-no @click="addYesNo">
                Add Yes and No
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

/* M249: the short label sits between the label and the remove button. */
.choices--short .choices__head,
.choices--short .choices__row {
    grid-template-columns: 1fr 1fr 1fr 28px;
}

.choices__note {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.choices__col {
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
    text-transform: uppercase;
    letter-spacing: var(--mds-tracking-wide);
}

.choices__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.choices__empty {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
}
</style>
