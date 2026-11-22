<script setup lang="ts">
/**
 * The shared single-select dropdown, built on a native <select> so it inherits the platform's
 * keyboard model, mobile picker, and accessibility for free (no custom listbox to keep WCAG-correct).
 * Pairs with FormField (which supplies :id / :describedby / :invalid) exactly like TextInput. A native
 * chevron is drawn with a background SVG; error styling is a border change that always accompanies
 * FormField's icon + text — never color alone (WCAG 1.4.1).
 *
 * M249 (`dropdown-placeholder`, `D233`) — TWO ADDITIONS, BOTH OPT-IN:
 *  - `clearable`: the placeholder option can be chosen, and choosing it empties the answer. Without it (the default)
 *    the placeholder stays a disabled prompt, so an answered required dropdown cannot be emptied by accident.
 *  - an option's `shortLabel`: while that option is chosen, the CLOSED box shows the short label instead of the full
 *    text, drawn over the select in an `aria-hidden` span while the select's own text is made transparent. The open
 *    list (the phone's own picker) and every screen reader keep the full label, which is the select's real value text.
 *    ⛔ The options get their own text colour, because Firefox and Windows Chrome draw the open list in the select's
 *    colour — transparent would empty it. ⛔ Under `forced-colors` the system repaints the select's text, so the span is
 *    hidden and the full label shows alone (`D233` answer 2).
 */
import { computed } from 'vue';

interface SelectOption {
    value: string;
    label: string;
    disabled?: boolean;
    /** M249: what the closed box shows while this option is chosen; absent or empty shows `label`. */
    shortLabel?: string | null;
}

interface SelectGroup {
    label: string;
    options: SelectOption[];
}

// The root is a positioned wrapper (for the chevron), so fall-through attrs (e.g. aria-label on a bare
// select in a dense editor row) must be forwarded to the <select> itself, not the wrapper div.
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        options?: SelectOption[];
        groups?: SelectGroup[];
        id?: string;
        name?: string;
        // A prompt shown when the model value is empty (rendered as a disabled option unless `clearable`).
        placeholder?: string;
        // M249: the placeholder option can be chosen to empty the answer.
        clearable?: boolean;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
    }>(),
    { modelValue: '', options: () => [], groups: () => [], clearable: false, invalid: false, disabled: false },
);

/** M249: the chosen option's short label, or null when it has none (the select then shows its own text). */
const closedLabel = computed<string | null>(() => {
    if (props.modelValue === '') {
        return null;
    }
    const chosen = [...props.options, ...props.groups.flatMap((group) => group.options)].find((opt) => opt.value === props.modelValue);
    const short = (chosen?.shortLabel ?? '').trim();

    return short === '' ? null : short;
});

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div class="mds-select" :class="{ 'mds-select--invalid': invalid, 'mds-select--disabled': disabled, 'mds-select--short': closedLabel !== null }">
        <select
            :id="id"
            class="mds-select__control"
            :name="name"
            :value="modelValue"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedby"
            v-bind="$attrs"
            @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
        >
            <option v-if="placeholder" value="" :disabled="!clearable">{{ placeholder }}</option>
            <option v-for="opt in options" :key="opt.value" :value="opt.value" :disabled="opt.disabled">
                {{ opt.label }}
            </option>
            <optgroup v-for="group in groups" :key="group.label" :label="group.label">
                <option v-for="opt in group.options" :key="opt.value" :value="opt.value" :disabled="opt.disabled">
                    {{ opt.label }}
                </option>
            </optgroup>
        </select>
        <span v-if="closedLabel !== null" class="mds-select__short" aria-hidden="true" data-select-short><span class="mds-select__short-text">{{ closedLabel }}</span></span>
        <svg class="mds-select__chevron" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
            <path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </div>
</template>

<style scoped>
.mds-select {
    position: relative;
    display: block;
    width: 100%;
}

.mds-select__control {
    appearance: none;
    -webkit-appearance: none;
    width: 100%;
    min-height: 40px;
    padding: 0 var(--mds-space-8) 0 var(--mds-space-3);
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-input-bg);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
    cursor: pointer;
    transition:
        border-color var(--mds-duration-base) var(--mds-ease-standard),
        box-shadow var(--mds-duration-base) var(--mds-ease-standard);
}

.mds-select__control:hover:not(:disabled) {
    border-color: var(--mds-color-input-border-hover);
}

.mds-select__control:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-color: var(--mds-color-action-primary-bg);
}

.mds-select--invalid .mds-select__control {
    border-color: var(--mds-color-action-danger-bg);
}

.mds-select__control:disabled {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}

.mds-select__chevron {
    position: absolute;
    top: 50%;
    right: var(--mds-space-3);
    width: 16px;
    height: 16px;
    transform: translateY(-50%);
    color: var(--mds-color-text-secondary);
    pointer-events: none;
}

.mds-select--disabled .mds-select__chevron {
    color: var(--mds-color-text-disabled);
}

/* ── M249: the short closed label, drawn over the select ────────────────────────────────────────── */
.mds-select--short .mds-select__control {
    color: transparent;
}

/* The open list keeps its text: Firefox and Windows Chrome paint options in the select's own colour. */
.mds-select--short .mds-select__control option,
.mds-select--short .mds-select__control optgroup {
    background-color: var(--mds-color-input-bg);
    color: var(--mds-color-text-body);
}

.mds-select__short {
    position: absolute;
    inset: 0 var(--mds-space-8) 0 var(--mds-space-3);
    display: flex;
    align-items: center;
    min-width: 0;
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
    pointer-events: none;
}

.mds-select__short-text {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.mds-select--disabled .mds-select__short {
    color: var(--mds-color-text-disabled);
}

/* The system repaints the select's own text in forced colours, so the full label shows alone. */
@media (forced-colors: active) {
    .mds-select__short {
        display: none;
    }
}
</style>
