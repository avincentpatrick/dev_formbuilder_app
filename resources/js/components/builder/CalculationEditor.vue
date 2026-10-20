<script setup lang="ts">
/**
 * The calculation editor (M180, `R-f77aa45d`, `D155`, `D156`) — a calculated question's Basics. The user (2026-10-16):
 * *"create a calculated field using the form's own questions by clicking or selecting, not by purely typing … make sure
 * that formula can still be typed … put presets"*.
 *
 *   - a Type — Number, Text, Date or Duration — which decides how the value is SHOWN (`SchemaValueFormatter` /
 *     `display-value.ts`), with decimal places for a Number and a unit for a Duration;
 *   - Build it: a preset and its questions, chosen from lists; it WRITES the formula shown beneath it;
 *   - Formula: the formula typed, with the "Insert a question" picker. Typing in the formula switches to Formula.
 *
 * The formula is the only source of truth (`calculation-presets.ts`): a formula in a preset's shape opens on Build it,
 * any other on Formula. Every change leaves as ONE patch of the question's `config`, so the panel saves it as one edit
 * and one undo step. ⛔ No tablist (the settings panel holds exactly one) and no floating menu (the panel scrolls, DSR
 * §3.4b): the switches are `MdsSegmentedControl`s and the lists native `MdsSelect`s.
 */
import { computed, ref } from 'vue';
import { MdsCheckbox, MdsFormField, MdsNumberInput, MdsSegmentedControl, MdsSelect, MdsTextInput } from '@meridian/design-system';
import ReferenceInput from './ReferenceInput.vue';
import type { ReferenceCandidate } from './reference-candidates';
import {
    parsePreset, presetFormula, presetsFor, PRESETS, typeOfPreset, UNITS,
    type CalculationQuestion, type CalculationType, type DateOperand, type DateUnit, type IfOperator, type Preset, type PresetKind,
} from './calculation-presets';

const props = defineProps<{
    config: Record<string, unknown>;
    /** The questions a preset may read — every other question on the form, in canvas order. */
    questions: CalculationQuestion[];
    /** The formula box's "Insert a question" list. */
    candidates: ReferenceCandidate[];
    formulaError?: string;
    placesError?: string;
    unitError?: string;
}>();

const emit = defineEmits<{ patch: [patch: Record<string, unknown>] }>();

const NUMBER_TYPES = ['integer', 'decimal', 'calculated'];
const DATE_TYPES = ['date', 'time', 'datetime'];
const ANY_TYPES_EXCLUDED = ['matrix', 'likert_matrix', 'geopoint', 'geotrace', 'geoshape', 'note', 'page_break', 'file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature'];
const TODAY = '__today__';

const formula = computed(() => (typeof props.config.calculated_formula === 'string' ? props.config.calculated_formula : ''));
const type = computed<CalculationType>(() => {
    const declared = props.config.calculated_type;

    return declared === 'text' || declared === 'date' || declared === 'duration' ? declared : 'number';
});
const preset = computed<Preset | null>(() => parsePreset(formula.value));
const mode = ref<'build' | 'formula'>(formula.value.trim() === '' || preset.value !== null ? 'build' : 'formula');

const typeOptions = [
    { value: 'number', label: 'Number' },
    { value: 'text', label: 'Text' },
    { value: 'date', label: 'Date' },
    { value: 'duration', label: 'Duration' },
];
const modeOptions = [
    { value: 'build', label: 'Build it' },
    { value: 'formula', label: 'Formula' },
];
const unitOptions = UNITS.map((unit) => ({ value: unit, label: unit[0].toUpperCase() + unit.slice(1) }));
const dayUnitOptions = unitOptions.filter((option) => ['days', 'weeks', 'months', 'years'].includes(option.value));
const operatorOptions: Array<{ value: IfOperator; label: string }> = [
    { value: '=', label: 'is' }, { value: '!=', label: 'is not' }, { value: '>', label: 'is more than' },
    { value: '>=', label: 'is at least' }, { value: '<', label: 'is less than' }, { value: '<=', label: 'is at most' },
];
const presetOptions = computed(() => presetsFor(type.value).map((kind) => ({ value: kind, label: PRESETS[kind] })));

const label = (question: CalculationQuestion): string => (question.label === question.key || question.label === '' ? question.key : `${question.label} · ${question.key}`);
const numbers = computed(() => props.questions.filter((q) => NUMBER_TYPES.includes(q.fieldType)));
const dates = computed(() => props.questions.filter((q) => DATE_TYPES.includes(q.fieldType)));
const anyQuestion = computed(() => props.questions.filter((q) => !ANY_TYPES_EXCLUDED.includes(q.fieldType)));
const optionsOf = (list: CalculationQuestion[]) => list.map((q) => ({ value: q.key, label: label(q) }));
const dateOptions = computed(() => [...optionsOf(dates.value), { value: TODAY, label: 'Today' }]);

function write(next: Preset): void {
    const { type: givenType, unit } = typeOfPreset(next);
    emit('patch', { calculated_formula: presetFormula(next), calculated_type: givenType, ...(unit === null ? {} : { duration_unit: unit }) });
}

/** A preset chosen from the list starts from the first questions that fit it, so its formula is whole at once. */
function choosePreset(kind: string): void {
    const first = (list: CalculationQuestion[], at = 0): string => list[at]?.key ?? list[0]?.key ?? '';
    const startOf = (): DateOperand => (dates.value.length > 0 ? { key: first(dates.value) } : { today: true });
    const presets: Record<PresetKind, () => Preset> = {
        sum: () => ({ kind: 'sum', keys: numbers.value.slice(0, 2).map((q) => q.key) }),
        average: () => ({ kind: 'average', keys: numbers.value.slice(0, 2).map((q) => q.key) }),
        count: () => ({ kind: 'count', keys: anyQuestion.value.slice(0, 2).map((q) => q.key) }),
        difference: () => ({ kind: 'difference', from: first(numbers.value), subtract: first(numbers.value, 1) }),
        age: () => ({ kind: 'age', key: first(dates.value) }),
        duration: () => ({ kind: 'duration', start: startOf(), end: dates.value.length > 1 ? { key: first(dates.value, 1) } : { today: true }, unit: 'days' }),
        date_add: () => ({ kind: 'date_add', from: startOf(), count: 7, unit: 'days' }),
        if: () => ({ kind: 'if', key: first(anyQuestion.value), op: '=', value: '', then: type.value === 'number' ? '1' : 'yes', otherwise: type.value === 'number' ? '0' : 'no' }),
        join: () => ({ kind: 'join', keys: anyQuestion.value.slice(0, 2).map((q) => q.key), separator: ' ' }),
    };
    const make = presets[kind as PresetKind];
    if (make !== undefined) write(make());
}

/** One of the current preset's choices changed: rewrite its formula. */
function update(change: Partial<Preset>): void {
    if (preset.value !== null) write({ ...preset.value, ...change } as Preset);
}

function toggleKey(key: string, on: boolean): void {
    const current = preset.value;
    if (current === null || !('keys' in current)) return;
    const keys = props.questions.map((q) => q.key).filter((k) => (k === key ? on : current.keys.includes(k)));
    if (keys.length > 0) update({ keys } as Partial<Preset>);
}

const operandValue = (operand: DateOperand): string => ('today' in operand ? TODAY : operand.key);
const operandOf = (value: string): DateOperand => (value === TODAY ? { today: true } : { key: value });

function onFormula(value: string): void {
    emit('patch', { calculated_formula: value === '' ? null : value });
}
</script>

<template>
    <div class="calc">
        <div class="calc__group">
            <span class="calc__group-label">Result type</span>
            <MdsSegmentedControl compact :model-value="type" :options="typeOptions" ariaLabel="Result type" @update:model-value="emit('patch', { calculated_type: $event })" />
        </div>

        <div v-if="type === 'number'" class="calc__row" data-calc="places">
            <MdsFormField label="Decimal places" :error="placesError" help="How many digits after the point to show. Leave it blank to show the number as worked out." v-slot="{ id, describedby }">
                <MdsNumberInput :id="id" :describedby="describedby" :min="0" :max="10" :step="1" :model-value="typeof config.decimal_places === 'number' ? config.decimal_places : null" @update:model-value="emit('patch', { decimal_places: $event })" />
            </MdsFormField>
        </div>

        <div class="calc__group">
            <span class="calc__group-label">How to write it</span>
            <MdsSegmentedControl compact :model-value="mode" :options="modeOptions" ariaLabel="How to write it" @update:model-value="mode = $event as 'build' | 'formula'" />
        </div>

        <template v-if="mode === 'build'">
            <div class="calc__row" data-calc="preset">
                <MdsFormField label="Preset" v-slot="{ id }">
                    <MdsSelect :id="id" :options="presetOptions" placeholder="Choose a preset" :model-value="preset?.kind ?? ''" @update:model-value="choosePreset($event)" />
                </MdsFormField>
            </div>

            <template v-if="preset !== null">
                <fieldset v-if="'keys' in preset" class="calc__keys" data-calc="keys">
                    <legend>{{ preset.kind === 'join' ? 'Answers to join, in this order' : 'Questions' }}</legend>
                    <MdsCheckbox
                        v-for="q in preset.kind === 'sum' || preset.kind === 'average' ? numbers : anyQuestion"
                        :key="q.key"
                        :model-value="preset.keys.includes(q.key)"
                        :label="label(q)"
                        @update:model-value="toggleKey(q.key, $event)"
                    />
                </fieldset>
                <div v-if="preset.kind === 'join'" class="calc__row" data-calc="separator">
                    <MdsFormField label="Between them" v-slot="{ id }">
                        <MdsTextInput :id="id" :model-value="preset.separator" @update:model-value="update({ separator: $event })" />
                    </MdsFormField>
                </div>
                <template v-if="preset.kind === 'difference'">
                    <div class="calc__row" data-calc="from">
                        <MdsFormField label="From" v-slot="{ id }"><MdsSelect :id="id" :options="optionsOf(numbers)" :model-value="preset.from" @update:model-value="update({ from: $event })" /></MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="subtract">
                        <MdsFormField label="Take away" v-slot="{ id }"><MdsSelect :id="id" :options="optionsOf(numbers)" :model-value="preset.subtract" @update:model-value="update({ subtract: $event })" /></MdsFormField>
                    </div>
                </template>
                <div v-if="preset.kind === 'age'" class="calc__row" data-calc="key">
                    <MdsFormField label="Date of birth" v-slot="{ id }"><MdsSelect :id="id" :options="optionsOf(dates)" :model-value="preset.key" @update:model-value="update({ key: $event })" /></MdsFormField>
                </div>
                <template v-if="preset.kind === 'duration'">
                    <div class="calc__row" data-calc="start">
                        <MdsFormField label="Start" v-slot="{ id }"><MdsSelect :id="id" :options="dateOptions" :model-value="operandValue(preset.start)" @update:model-value="update({ start: operandOf($event) })" /></MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="end">
                        <MdsFormField label="End" v-slot="{ id }"><MdsSelect :id="id" :options="dateOptions" :model-value="operandValue(preset.end)" @update:model-value="update({ end: operandOf($event) })" /></MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="unit">
                        <MdsFormField label="Units" :error="unitError" help="Whole units only: 8 hours 30 minutes is 8 hours." v-slot="{ id, describedby }">
                            <MdsSelect :id="id" :describedby="describedby" :options="unitOptions" :model-value="preset.unit" @update:model-value="update({ unit: $event as DateUnit })" />
                        </MdsFormField>
                    </div>
                </template>
                <template v-if="preset.kind === 'date_add'">
                    <div class="calc__row" data-calc="from">
                        <MdsFormField label="From" v-slot="{ id }"><MdsSelect :id="id" :options="dateOptions" :model-value="operandValue(preset.from)" @update:model-value="update({ from: operandOf($event) })" /></MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="count">
                        <MdsFormField label="How many" help="A minus number counts back." v-slot="{ id, describedby }">
                            <MdsNumberInput :id="id" :describedby="describedby" :step="1" :model-value="preset.count" @update:model-value="update({ count: Math.trunc($event ?? 0) })" />
                        </MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="unit">
                        <MdsFormField label="Units" v-slot="{ id }"><MdsSelect :id="id" :options="dayUnitOptions" :model-value="preset.unit" @update:model-value="update({ unit: $event as DateUnit })" /></MdsFormField>
                    </div>
                </template>
                <template v-if="preset.kind === 'if'">
                    <div class="calc__row" data-calc="key">
                        <MdsFormField label="If" v-slot="{ id }"><MdsSelect :id="id" :options="optionsOf(anyQuestion)" :model-value="preset.key" @update:model-value="update({ key: $event })" /></MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="op">
                        <MdsFormField label="Comparison" v-slot="{ id }"><MdsSelect :id="id" :options="operatorOptions" :model-value="preset.op" @update:model-value="update({ op: $event as IfOperator })" /></MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="value">
                        <MdsFormField label="Value" v-slot="{ id }"><MdsTextInput :id="id" :model-value="preset.value" @update:model-value="update({ value: $event })" /></MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="then">
                        <MdsFormField label="Then" v-slot="{ id }"><MdsTextInput :id="id" :model-value="preset.then" @update:model-value="update({ then: $event })" /></MdsFormField>
                    </div>
                    <div class="calc__row" data-calc="otherwise">
                        <MdsFormField label="Otherwise" v-slot="{ id }"><MdsTextInput :id="id" :model-value="preset.otherwise" @update:model-value="update({ otherwise: $event })" /></MdsFormField>
                    </div>
                </template>
            </template>
        </template>

        <div v-else-if="type === 'duration'" class="calc__row" data-calc="display-unit">
            <MdsFormField label="Shown in" :error="unitError" help="The unit written after the number, such as 34 years." v-slot="{ id, describedby }">
                <MdsSelect :id="id" :describedby="describedby" :options="unitOptions" placeholder="No unit" :model-value="typeof config.duration_unit === 'string' ? config.duration_unit : ''" @update:model-value="emit('patch', { duration_unit: $event || null })" />
            </MdsFormField>
        </div>

        <div class="calc__row" data-calc="formula">
            <MdsFormField
                label="Calculation formula"
                :error="formulaError"
                help="Worked out from other answers. Click Insert a question, or type @, to use another question's answer, and use + - * /, comparisons, or if(), sum(), round(), date_diff(), date_add(), age(), concat(), count(), today() and now()."
                v-slot="{ id, describedby, invalid }"
            >
                <ReferenceInput
                    :id="id"
                    :candidates="candidates"
                    :describedby="describedby"
                    :invalid="invalid"
                    :model-value="formula"
                    :rows="2"
                    placeholder="e.g. ${quantity} * ${unit_price}"
                    @update:model-value="onFormula($event)"
                />
            </MdsFormField>
        </div>
    </div>
</template>

<style scoped>
.calc {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.calc__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

/* The panel is narrow: the segments wrap rather than spill (the D28 affordance ConfigPanel's groups use). */
.calc__group :deep(.mds-segmented) {
    flex-wrap: wrap;
}

.calc__group-label {
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.calc__keys {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin: 0;
    padding: 0;
    border: 0;
}

.calc__keys legend {
    margin-bottom: var(--mds-space-1);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}
</style>
