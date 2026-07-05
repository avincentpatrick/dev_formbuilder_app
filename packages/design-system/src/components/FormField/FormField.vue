<script setup lang="ts">
/**
 * The shared form-field wrapper. No input in the app renders without one — it owns the
 * <label>, the "(required)" affordance, help text, and the accessible error region so every
 * field is consistent and WCAG-correct (label association, error = icon + text + aria-live,
 * never color alone — §3.2 / §4.1 / WCAG 1.4.1).
 *
 * The control is provided via the default slot, which receives { id, describedby, invalid }
 * to bind onto the input — this is what guarantees the label/description wiring is never
 * forgotten by a page author.
 */
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        required?: boolean;
        help?: string;
        error?: string;
        inputId?: string;
    }>(),
    { required: false },
);

const generatedId = useId();
const fieldId = computed(() => props.inputId ?? generatedId);
const helpId = computed(() => `${fieldId.value}-help`);
const errorId = computed(() => `${fieldId.value}-error`);

const describedby = computed(() => {
    const ids: string[] = [];
    if (props.help) ids.push(helpId.value);
    if (props.error) ids.push(errorId.value);
    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div class="mds-field">
        <label :for="fieldId" class="mds-field__label">
            {{ label }}<span v-if="required" class="mds-field__required"> (required)</span>
        </label>

        <slot :id="fieldId" :describedby="describedby" :invalid="Boolean(error)" />

        <p v-if="help" :id="helpId" class="mds-field__help">{{ help }}</p>

        <!-- Always present so it is a live region when an error appears later. -->
        <div :id="errorId" class="mds-field__error" aria-live="polite">
            <template v-if="error">
                <svg
                    class="mds-field__error-icon"
                    viewBox="0 0 16 16"
                    aria-hidden="true"
                    focusable="false"
                >
                    <path
                        fill="currentColor"
                        d="M8 1a7 7 0 100 14A7 7 0 008 1zm-.9 3.6h1.8l-.2 5h-1.4l-.2-5zM8 12.4a1 1 0 110-2 1 1 0 010 2z"
                    />
                </svg>
                <span>{{ error }}</span>
            </template>
        </div>
    </div>
</template>

<style scoped>
.mds-field {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.mds-field__label {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.mds-field__required {
    font-weight: var(--mds-font-weight-regular);
    color: var(--mds-color-text-secondary);
}

.mds-field__help {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.mds-field__error {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-1);
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}
.mds-field__error:empty {
    display: none;
}

.mds-field__error-icon {
    flex-shrink: 0;
    width: 1em;
    height: 1em;
    margin-top: 0.15em;
}
</style>
