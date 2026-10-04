<script setup lang="ts">
/**
 * The shared radio button (DSR §3.2, built M130 so a single choice can show round buttons). A real native
 * <input type="radio"> — visually hidden, fully keyboard-operable — under a custom circle, with an inline
 * label. Built to the spec §3.2 already carried: circular, the same border logic as `MdsCheckbox`, and a
 * FILLED INNER DOT as the selected state — the dot, not the colour, is the signifier (WCAG 1.4.1).
 *
 * ⚠️ GROUPING IS THE CALLER'S JOB, AND THAT IS DELIBERATE. A radio alone has nothing to rove between: the
 * arrow keys move within the set of radios that share one `name`, and the question they answer is a
 * `<fieldset>`'s `<legend>`. So `name` is required here, and the caller owns the fieldset. A caller that
 * renders the same question more than once on a page (a repeat instance) must give each copy its own
 * name, or the copies become one group and choosing in one clears the others.
 *
 * Same `id` / `describedby` / `invalid` contract as `MdsCheckbox`, so an error can be read on the control
 * a summary jump lands on. Consumes semantic tokens only.
 */
withDefaults(
    defineProps<{
        /** The group's current value; this radio is checked when it equals `value`. */
        modelValue?: string;
        value: string;
        label: string;
        name: string;
        id?: string;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
    }>(),
    { modelValue: '', invalid: false, disabled: false },
);

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <label class="mds-radio" :class="{ 'mds-radio--disabled': disabled }">
        <input
            :id="id"
            class="mds-radio__input"
            type="radio"
            :name="name"
            :value="value"
            :checked="modelValue === value"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedby"
            @change="$emit('update:modelValue', value)"
        />
        <span class="mds-radio__circle" :class="{ 'mds-radio__circle--invalid': invalid }" aria-hidden="true">
            <span class="mds-radio__dot" />
        </span>
        <span class="mds-radio__label">{{ label }}</span>
    </label>
</template>

<style scoped>
.mds-radio {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    min-height: 44px; /* touch target (§4.4) */
    cursor: pointer;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.mds-radio--disabled {
    cursor: not-allowed;
    /* As `MdsCheckbox` explains: the visible label is a <span>, so axe cannot apply the disabled contrast
       exemption — dim to text-secondary (still ≥4.5:1), not to text-disabled. */
    color: var(--mds-color-text-secondary);
}

/* Native control: hidden visually, still focusable and operable — `MdsCheckbox`'s idiom, inside this
   component's own positioned root so its containing block resolves here. */
.mds-radio__input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}

.mds-radio__circle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-input-bg);
    transition: border-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.mds-radio:hover .mds-radio__circle {
    border-color: var(--mds-color-input-border-hover);
}

.mds-radio__circle--invalid {
    border-color: var(--mds-color-action-danger-bg);
}

.mds-radio__dot {
    width: 8px;
    height: 8px;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-action-primary-bg);
    opacity: 0;
}

/* Selected = the ring takes the action colour AND the inner dot appears; the dot is the non-colour signifier. */
.mds-radio__input:checked + .mds-radio__circle {
    border-color: var(--mds-color-action-primary-bg);
}
.mds-radio__input:checked + .mds-radio__circle .mds-radio__dot {
    opacity: 1;
}

/* Keyboard focus ring on the circle (mouse clicks don't show it). */
.mds-radio__input:focus-visible + .mds-radio__circle {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-radio--disabled .mds-radio__circle {
    background-color: var(--mds-color-bg-sunken);
}
</style>
