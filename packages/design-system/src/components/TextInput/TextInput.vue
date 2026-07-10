<script setup lang="ts">
/**
 * The shared single-line text input. Consumes semantic tokens only; pairs with FormField
 * (which supplies :id / :describedby / :invalid). Error styling is a border change that ALWAYS
 * accompanies FormField's icon + text — never color alone (WCAG 1.4.1).
 */
withDefaults(
    defineProps<{
        modelValue?: string;
        id?: string;
        type?: 'text' | 'email' | 'password' | 'tel' | 'url' | 'date' | 'time' | 'datetime-local';
        name?: string;
        autocomplete?: string;
        placeholder?: string;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
    }>(),
    { modelValue: '', type: 'text', invalid: false, disabled: false },
);

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <input
        :id="id"
        class="mds-input"
        :class="{ 'mds-input--invalid': invalid }"
        :type="type"
        :name="name"
        :value="modelValue"
        :autocomplete="autocomplete"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedby"
        @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
</template>

<style scoped>
.mds-input {
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

.mds-input::placeholder {
    color: var(--mds-color-text-secondary);
    opacity: 1;
}

.mds-input:hover:not(:disabled) {
    border-color: var(--mds-color-input-border-hover);
}

.mds-input:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-color: var(--mds-color-action-primary-bg);
}

.mds-input--invalid {
    border-color: var(--mds-color-action-danger-bg);
}

.mds-input:disabled {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}
</style>
