<script setup lang="ts">
/**
 * Config sub-editor for the option list of choice-style fields (single/multi/dropdown/likert). Each row
 * is a value + display label; the runtime option rendering follows the form engine, but authors define
 * the set here. Emits a fresh array on every change so the store records one debounced history entry.
 *
 * M243 (`R-1da063f4`): on a multi-select (`allowExclusive`, from the server's `choice_limit_types`) each row also has an
 * "Only answer" box. Such a choice — "None of these", "No view or opinion" — clears and locks every other box when a
 * person ticks it, and both engines refuse it beside another. Stored as `exclusive: true` on the option, and absent when off.
 */
import { MdsButton, MdsCheckbox, MdsIconButton, MdsTextInput } from '@meridian/design-system';

interface Choice {
    value: string;
    label: string;
    exclusive?: boolean;
}

const props = defineProps<{ options: Choice[]; disabled?: boolean; allowExclusive?: boolean }>();
const emit = defineEmits<{ 'update:options': [value: Choice[]] }>();

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
/** Turning "Only answer" off removes the key, so an option that never had it is saved exactly as before. */
function setExclusive(index: number, on: boolean): void {
    const next = props.options.map((opt, i) => {
        if (i !== index) return opt;
        const rest: Choice = { ...opt };
        delete rest.exclusive;
        return on ? { ...rest, exclusive: true } : rest;
    });
    emit('update:options', next);
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
    <div class="choices" :class="{ 'choices--exclusive': allowExclusive }">
        <div class="choices__head">
            <span class="choices__col">Value</span>
            <span class="choices__col">Label</span>
            <span v-if="allowExclusive" class="choices__col">Only answer</span>
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
            <MdsCheckbox
                v-if="allowExclusive"
                class="choices__exclusive"
                :model-value="opt.exclusive === true"
                :disabled="disabled"
                :label="`Option ${i + 1} is an only answer`"
                hide-label
                data-choice-exclusive
                @update:model-value="setExclusive(i, $event)"
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
        <p v-else-if="allowExclusive" class="choices__note">
            Tick Only answer for a choice such as “None of these” that must stand alone: picking it clears and locks the others.
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

.choices--exclusive .choices__head,
.choices--exclusive .choices__row {
    grid-template-columns: 1fr 1fr auto 28px;
}

.choices__exclusive {
    justify-self: center;
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
