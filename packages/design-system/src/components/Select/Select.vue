<script setup lang="ts">
/**
 * The shared single-select dropdown, built on a native <select> so it inherits the platform's
 * keyboard model, mobile picker, and accessibility for free (no custom listbox to keep WCAG-correct).
 * Pairs with FormField (which supplies :id / :describedby / :invalid) exactly like TextInput. A native
 * chevron is drawn with a background SVG; error styling is a border change that always accompanies
 * FormField's icon + text — never color alone (WCAG 1.4.1).
 */
interface SelectOption {
    value: string;
    label: string;
    disabled?: boolean;
}

interface SelectGroup {
    label: string;
    options: SelectOption[];
}

// The root is a positioned wrapper (for the chevron), so fall-through attrs (e.g. aria-label on a bare
// select in a dense editor row) must be forwarded to the <select> itself, not the wrapper div.
defineOptions({ inheritAttrs: false });

withDefaults(
    defineProps<{
        modelValue?: string;
        options?: SelectOption[];
        groups?: SelectGroup[];
        id?: string;
        name?: string;
        // A non-selectable prompt shown when the model value is empty (rendered as a disabled option).
        placeholder?: string;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
    }>(),
    { modelValue: '', options: () => [], groups: () => [], invalid: false, disabled: false },
);

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div class="mds-select" :class="{ 'mds-select--invalid': invalid, 'mds-select--disabled': disabled }">
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
            <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
            <option v-for="opt in options" :key="opt.value" :value="opt.value" :disabled="opt.disabled">
                {{ opt.label }}
            </option>
            <optgroup v-for="group in groups" :key="group.label" :label="group.label">
                <option v-for="opt in group.options" :key="opt.value" :value="opt.value" :disabled="opt.disabled">
                    {{ opt.label }}
                </option>
            </optgroup>
        </select>
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
</style>
