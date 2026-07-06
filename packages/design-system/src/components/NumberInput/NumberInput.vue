<script setup lang="ts">
/**
 * The shared numeric input. A native <input type="number"> so it inherits the platform stepper,
 * numeric keypad on mobile, and up/down arrow semantics. Pairs with FormField (which supplies
 * :id / :describedby / :invalid). The model value is a number, or null when the field is cleared —
 * the builder config panels distinguish "0" from "unset", so an empty box maps to null, not 0.
 * Error styling is a border change that always accompanies FormField's icon + text (WCAG 1.4.1).
 */
withDefaults(
    defineProps<{
        modelValue?: number | null;
        id?: string;
        name?: string;
        min?: number;
        max?: number;
        step?: number;
        placeholder?: string;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
    }>(),
    { modelValue: null, invalid: false, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

function onInput(event: Event): void {
    const raw = (event.target as HTMLInputElement).value;
    emit('update:modelValue', raw === '' ? null : Number(raw));
}
</script>

<template>
    <input
        :id="id"
        class="mds-number"
        :class="{ 'mds-number--invalid': invalid }"
        type="number"
        inputmode="decimal"
        :name="name"
        :value="modelValue ?? ''"
        :min="min"
        :max="max"
        :step="step"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedby"
        @input="onInput"
    />
</template>

<style scoped>
.mds-number {
    width: 100%;
    min-height: 40px;
    padding: 0 var(--mds-space-3);
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-input-bg);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
    transition:
        border-color var(--mds-duration-base) var(--mds-ease-standard),
        box-shadow var(--mds-duration-base) var(--mds-ease-standard);
}

.mds-number::placeholder {
    color: var(--mds-color-text-secondary);
    opacity: 1;
}

.mds-number:hover:not(:disabled) {
    border-color: var(--mds-color-input-border-hover);
}

.mds-number:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-color: var(--mds-color-action-primary-bg);
}

.mds-number--invalid {
    border-color: var(--mds-color-action-danger-bg);
}

.mds-number:disabled {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}
</style>
