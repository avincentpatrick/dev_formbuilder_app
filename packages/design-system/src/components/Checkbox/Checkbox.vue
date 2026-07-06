<script setup lang="ts">
/**
 * The shared checkbox (DSR §3.2). A real native <input type="checkbox"> (visually hidden but fully
 * keyboard-operable) under a custom box, with an inline label. The CHECK GLYPH — not the fill colour —
 * is the signifier of the checked state (WCAG 1.4.1): the box fills `action-primary` AND shows a white
 * check. Accepts the same `id`/`describedby`/`invalid` contract as `TextInput` so it can also be driven
 * by `FormField`. Consumes semantic tokens only.
 */
withDefaults(
    defineProps<{
        modelValue?: boolean;
        label: string;
        id?: string;
        name?: string;
        describedby?: string;
        invalid?: boolean;
        disabled?: boolean;
        required?: boolean;
    }>(),
    { modelValue: false, invalid: false, disabled: false, required: false },
);

defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <label class="mds-checkbox" :class="{ 'mds-checkbox--disabled': disabled }">
        <input
            :id="id"
            class="mds-checkbox__input"
            type="checkbox"
            :name="name"
            :checked="modelValue"
            :disabled="disabled"
            :required="required"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedby"
            @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
        />
        <span class="mds-checkbox__box" :class="{ 'mds-checkbox__box--invalid': invalid }" aria-hidden="true">
            <svg class="mds-checkbox__check" viewBox="0 0 24 24" fill="none" focusable="false">
                <path
                    d="M5 12l4 4 10-10"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
        </span>
        <span class="mds-checkbox__label">{{ label }}</span>
    </label>
</template>

<style scoped>
.mds-checkbox {
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

.mds-checkbox--disabled {
    cursor: not-allowed;
    /* The visible label sits in a <span>, not the (disabled) <input>, so axe can't apply the disabled
       contrast exemption — dim to text-secondary (still ≥4.5:1) rather than text-disabled. The dimmed
       box + not-allowed cursor carry the disabled cue. */
    color: var(--mds-color-text-secondary);
}

/* Native control: hidden visually, still focusable + operable. */
.mds-checkbox__input {
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

.mds-checkbox__box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-input-bg);
    transition:
        background-color var(--mds-duration-fast) var(--mds-ease-standard),
        border-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.mds-checkbox:hover .mds-checkbox__box {
    border-color: var(--mds-color-input-border-hover);
}

.mds-checkbox__box--invalid {
    border-color: var(--mds-color-action-danger-bg);
}

.mds-checkbox__check {
    width: 14px;
    height: 14px;
    color: var(--mds-color-text-on-primary);
    opacity: 0;
}

/* Checked = filled box + visible white check glyph (the non-colour signifier). */
.mds-checkbox__input:checked + .mds-checkbox__box {
    background-color: var(--mds-color-action-primary-bg);
    border-color: var(--mds-color-action-primary-bg);
}
.mds-checkbox__input:checked + .mds-checkbox__box .mds-checkbox__check {
    opacity: 1;
}

/* Keyboard focus ring on the box (mouse clicks don't show it). */
.mds-checkbox__input:focus-visible + .mds-checkbox__box {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-checkbox--disabled .mds-checkbox__box {
    background-color: var(--mds-color-bg-sunken);
}
</style>
