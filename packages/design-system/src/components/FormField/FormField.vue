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
 *
 * ⛔ `groupLabel` EXISTS BECAUSE A SELF-LABELLING GROUP IS THE ONE CONTROL THIS WRAPPER CANNOT WIRE,
 * AND TWO CALL SITES HAD ALREADY WALKED INTO IT (M87). `MdsSegmentedControl` renders its OWN
 * `<fieldset>` and prints `ariaLabel` as a visually-hidden `<legend>`; it takes no `id`, so it can
 * never consume the slot's `id`. Wrapping one produced a `<label for="…">` pointing at an element
 * that does not exist — a dangling association and a second, competing name source — on both role
 * pickers in members. `SheetsRuleFields.vue` carries a comment warning against the same construction
 * and solved it by dropping the wrapper, which also drops the `aria-live` error region.
 *
 * With `groupLabel` the visible text renders as a `<span>`: no `for`, nothing to dangle, the group's
 * own `<legend>` remains the single accessible name, and the error region is kept. ⚠️ axe has no rule
 * for a `label[for]` matching nothing, so nothing in the stack would have reported it — which is why
 * this is asserted in `FormField.test.ts` rather than left to the a11y gate.
 */
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        required?: boolean;
        help?: string;
        error?: string;
        inputId?: string;
        /** The slotted control labels itself (a `<fieldset>`/`<legend>` group), so render a span. */
        groupLabel?: boolean;
    }>(),
    { required: false, groupLabel: false },
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
        <span v-if="groupLabel" class="mds-field__label" aria-hidden="true">
            {{ label }}<span v-if="required" class="mds-field__required"> (required)</span>
        </span>
        <label v-else :for="fieldId" class="mds-field__label">
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
