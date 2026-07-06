<script setup lang="ts">
/**
 * The shared multi-line text input. Sibling of TextInput — consumes semantic tokens only and pairs
 * with FormField (which supplies :id / :describedby / :invalid). Error styling is a border change that
 * ALWAYS accompanies FormField's icon + text — never color alone (WCAG 1.4.1).
 */
withDefaults(
    defineProps<{
        modelValue?: string;
        id?: string;
        name?: string;
        rows?: number;
        autocomplete?: string;
        placeholder?: string;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
    }>(),
    { modelValue: '', rows: 3, invalid: false, disabled: false },
);

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <textarea
        :id="id"
        class="mds-textarea"
        :class="{ 'mds-textarea--invalid': invalid }"
        :name="name"
        :rows="rows"
        :value="modelValue"
        :autocomplete="autocomplete"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedby"
        @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />
</template>

<style scoped>
.mds-textarea {
    display: block;
    width: 100%;
    min-height: 88px;
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-input-bg);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
    resize: vertical;
    transition:
        border-color var(--mds-duration-base) var(--mds-ease-standard),
        box-shadow var(--mds-duration-base) var(--mds-ease-standard);
}

.mds-textarea::placeholder {
    color: var(--mds-color-text-secondary);
    opacity: 1;
}

.mds-textarea:hover:not(:disabled) {
    border-color: var(--mds-color-input-border-hover);
}

.mds-textarea:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-color: var(--mds-color-action-primary-bg);
}

.mds-textarea--invalid {
    border-color: var(--mds-color-action-danger-bg);
}

.mds-textarea:disabled {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}
</style>
