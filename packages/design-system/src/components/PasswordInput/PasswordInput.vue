<script setup lang="ts">
/**
 * Password input = the shared TextInput plus an accessible reveal toggle.
 * The toggle is a real <button> with aria-pressed; its accessible name ("Show password" /
 * "Hide password") contains its visible text ("Show"/"Hide") to satisfy WCAG 2.5.3 (label in
 * name). Icon-free by design for Increment C1 (no icon system until C2).
 */
import { ref } from 'vue';
import TextInput from '../TextInput/TextInput.vue';

withDefaults(
    defineProps<{
        modelValue?: string;
        id?: string;
        name?: string;
        autocomplete?: string;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
    }>(),
    { modelValue: '', invalid: false, disabled: false },
);

defineEmits<{ 'update:modelValue': [value: string] }>();

const revealed = ref(false);
</script>

<template>
    <div class="mds-password">
        <TextInput
            :id="id"
            class="mds-password__input"
            :type="revealed ? 'text' : 'password'"
            :name="name"
            :model-value="modelValue"
            :autocomplete="autocomplete"
            :describedby="describedby"
            :invalid="invalid"
            :disabled="disabled"
            @update:model-value="$emit('update:modelValue', $event)"
        />
        <button
            type="button"
            class="mds-password__toggle"
            :aria-pressed="revealed"
            :aria-label="revealed ? 'Hide password' : 'Show password'"
            :disabled="disabled"
            @click="revealed = !revealed"
        >
            {{ revealed ? 'Hide' : 'Show' }}
        </button>
    </div>
</template>

<style scoped>
.mds-password {
    position: relative;
}

/* Room for the toggle (child component root — reachable with a plain scoped selector). */
.mds-password__input {
    padding-right: var(--mds-space-12);
}

.mds-password__toggle {
    position: absolute;
    top: 50%;
    right: var(--mds-space-1);
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /* ≥24px pointer target (WCAG 2.2 §2.5.8) — the "Show" text alone is shorter; fits the 40px input. */
    min-height: 32px;
    min-width: 44px;
    padding: 0 var(--mds-space-2);
    border: none;
    border-radius: var(--mds-radius-sm);
    background-color: transparent;
    color: var(--mds-color-action-primary-fg);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    cursor: pointer;
}

.mds-password__toggle:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-password__toggle:disabled {
    color: var(--mds-color-text-disabled);
    cursor: not-allowed;
}
</style>
