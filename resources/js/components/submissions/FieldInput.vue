<script setup lang="ts">
/**
 * One manual-encoding input (Increment F4b). Maps a published field descriptor onto the matching shared
 * design-system control and binds its value + per-field 422 error. "Render-supported, mark-the-rest": the
 * ~14 Phase-1 scalar types render a real control; `note` renders as static prose; anything else (advanced
 * types, or a field inside a repeatable section) shows a read-only "not available for manual entry" notice
 * rather than being silently dropped.
 *
 * Single controls (text/number/select/date) use MdsFormField (label ↔ input association). Group controls
 * (a multi-select checkbox group, the yes/no segmented control) can't be driven by one label-for, so they
 * render a real <fieldset>/<legend> with their own aria-live error region — same visual anatomy, correct
 * grouping semantics.
 */
import {
    MdsCheckbox,
    MdsFormField,
    MdsNumberInput,
    MdsSegmentedControl,
    MdsSelect,
    MdsTextarea,
    MdsTextInput,
} from '@meridian/design-system';
import { computed } from 'vue';

export interface EncodeField {
    key: string;
    field_type: string;
    label: string;
    hint: string | null;
    placeholder: string | null;
    required: boolean;
    options: { value: string; label: string }[];
    supported: boolean;
}

// The value shapes an answer slot can hold across the encode controls (list for multi-select, number for
// numeric fields, "" otherwise). Kept concrete (not `unknown`) so Inertia's useForm accepts the form data.
export type AnswerValue = string | number | boolean | string[] | null;

const props = defineProps<{ field: EncodeField; modelValue: AnswerValue; error?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: AnswerValue] }>();

const yesNoOptions = [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
];

const control = computed<'text' | 'textarea' | 'number' | 'select' | 'checkboxes' | 'yesno' | 'note' | 'unsupported'>(
    () => {
        const t = props.field.field_type;
        if (t === 'note') return 'note';
        if (!props.field.supported) return 'unsupported';
        if (['short_text', 'email', 'phone', 'url', 'date', 'time', 'datetime'].includes(t)) return 'text';
        if (t === 'long_text') return 'textarea';
        if (t === 'integer' || t === 'decimal') return 'number';
        if (t === 'single_select' || t === 'dropdown') return 'select';
        if (t === 'multi_select') return 'checkboxes';
        if (t === 'yes_no') return 'yesno';
        return 'unsupported';
    },
);

const textType = computed<'text' | 'email' | 'tel' | 'url' | 'date' | 'time' | 'datetime-local'>(() => {
    switch (props.field.field_type) {
        case 'email':
            return 'email';
        case 'phone':
            return 'tel';
        case 'url':
            return 'url';
        case 'date':
            return 'date';
        case 'time':
            return 'time';
        case 'datetime':
            return 'datetime-local';
        default:
            return 'text';
    }
});

// The bare-string binding for text/select/yesno controls (modelValue is typed `unknown` for the union).
const stringValue = computed<string>(() => (typeof props.modelValue === 'string' ? props.modelValue : ''));
const numberValue = computed<number | null>(() => (typeof props.modelValue === 'number' ? props.modelValue : null));
const listValue = computed<string[]>(() => (Array.isArray(props.modelValue) ? (props.modelValue as string[]) : []));

function toggleOption(value: string, checked: boolean): void {
    const current = listValue.value;
    emit('update:modelValue', checked ? [...current, value] : current.filter((v) => v !== value));
}
</script>

<template>
    <!-- Single-control fields: label ↔ input via MdsFormField -->
    <MdsFormField
        v-if="['text', 'textarea', 'number', 'select'].includes(control)"
        :label="field.label"
        :required="field.required"
        :help="field.hint ?? undefined"
        :error="error"
        v-slot="{ id, describedby, invalid }"
    >
        <MdsTextInput
            v-if="control === 'text'"
            :id="id"
            :type="textType"
            :model-value="stringValue"
            :placeholder="field.placeholder ?? undefined"
            :describedby="describedby"
            :invalid="invalid"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <MdsTextarea
            v-else-if="control === 'textarea'"
            :id="id"
            :model-value="stringValue"
            :placeholder="field.placeholder ?? undefined"
            :describedby="describedby"
            :invalid="invalid"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <MdsNumberInput
            v-else-if="control === 'number'"
            :id="id"
            :model-value="numberValue"
            :step="field.field_type === 'integer' ? 1 : undefined"
            :placeholder="field.placeholder ?? undefined"
            :describedby="describedby"
            :invalid="invalid"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <MdsSelect
            v-else-if="control === 'select'"
            :id="id"
            :model-value="stringValue"
            :options="field.options"
            placeholder="Select an option"
            :describedby="describedby"
            :invalid="invalid"
            @update:model-value="emit('update:modelValue', $event)"
        />
    </MdsFormField>

    <!-- Group controls: real fieldset/legend + their own aria-live error region -->
    <fieldset v-else-if="control === 'checkboxes' || control === 'yesno'" class="encode-field">
        <legend class="encode-field__legend">
            {{ field.label }}<span v-if="field.required" class="encode-field__required"> (required)</span>
        </legend>
        <p v-if="field.hint" class="encode-field__help">{{ field.hint }}</p>

        <div v-if="control === 'checkboxes'" class="encode-field__checks">
            <MdsCheckbox
                v-for="opt in field.options"
                :key="opt.value"
                :label="opt.label"
                :model-value="listValue.includes(opt.value)"
                :invalid="Boolean(error)"
                @update:model-value="toggleOption(opt.value, $event)"
            />
        </div>
        <MdsSegmentedControl
            v-else
            :model-value="stringValue"
            :options="yesNoOptions"
            :ariaLabel="field.label"
            @update:model-value="emit('update:modelValue', $event)"
        />

        <div class="encode-field__error" aria-live="polite">
            <template v-if="error">
                <svg class="encode-field__error-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path
                        fill="currentColor"
                        d="M8 1a7 7 0 100 14A7 7 0 008 1zm-.9 3.6h1.8l-.2 5h-1.4l-.2-5zM8 12.4a1 1 0 110-2 1 1 0 010 2z"
                    />
                </svg>
                <span>{{ error }}</span>
            </template>
        </div>
    </fieldset>

    <!-- Display-only note -->
    <p v-else-if="control === 'note'" class="encode-note">{{ field.label }}</p>

    <!-- Unsupported (advanced / repeat) — surfaced, not silently dropped -->
    <div v-else class="encode-unsupported">
        <span class="encode-unsupported__label">{{ field.label }}</span>
        <p class="encode-unsupported__note">Not available for manual entry yet (Phase 2).</p>
    </div>
</template>

<style scoped>
.encode-field {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.encode-field__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.encode-field__required {
    font-weight: var(--mds-font-weight-regular);
    color: var(--mds-color-text-secondary);
}

.encode-field__help {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.encode-field__checks {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.encode-field__error {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-1);
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}
.encode-field__error:empty {
    display: none;
}

.encode-field__error-icon {
    flex-shrink: 0;
    width: 1em;
    height: 1em;
    margin-top: 0.15em;
}

.encode-note {
    margin: 0;
    padding: var(--mds-space-3) var(--mds-space-4);
    border-left: 3px solid var(--mds-color-border-strong);
    background-color: var(--mds-color-bg-sunken);
    border-radius: var(--mds-radius-sm);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.encode-unsupported {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.encode-unsupported__label {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
}

.encode-unsupported__note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
    font-style: italic;
}
</style>
