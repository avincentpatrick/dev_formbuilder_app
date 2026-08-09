<script setup lang="ts">
/**
 * The shared single-line text input. Consumes semantic tokens only; pairs with FormField
 * (which supplies :id / :describedby / :invalid). Error styling is a border change that ALWAYS
 * accompanies FormField's icon + text — never color alone (WCAG 1.4.1).
 *
 * ── `type="search"` (Increment J1a, DSR §3.2) ────────────────────────────────────────────────────
 * Added for global search's nav field and the list-page keyword filters. It is a pure widening: the
 * default is still `'text'` and no existing call site passes it, so no rendered input changes.
 *
 * TWO CONSEQUENCES, both easy to trip over later:
 *
 *   1. ⚠️ THE IMPLICIT ROLE BECOMES `searchbox`, NOT `textbox`. Any Playwright or Vitest locator for
 *      one of these must be `getByRole('searchbox')`. A `getByRole('textbox')` finds nothing and the
 *      failure reads as "the input is missing", which points at the wrong file.
 *
 *   2. ⚠️ `::-webkit-search-cancel-button` IS DELIBERATELY NOT SUPPRESSED. It is a genuine affordance
 *      — the one-tap "clear the query" every mobile user already knows — and removing it without
 *      building a replacement is a net loss. It does NOT violate WCAG 2.5.8 (Target Size, Minimum)
 *      despite being ~14px: SC 2.5.8's User-Agent Control exception applies verbatim ("the size of the
 *      target is determined by the user agent and is not modified by the author"). Do not "fix" this.
 *
 * No `appearance` reset is applied either. The box model here is fully authored (border, padding,
 * border-radius, min-height), which is what modern engines honour on a search field; adding
 * `appearance: none` is the change that would kill the cancel button in WebKit, i.e. the opposite of
 * what point 2 wants. Playwright runs Desktop Chrome at all three viewports, so Safari's rendering of
 * this type is genuinely unverified here — noted rather than guessed at.
 */
withDefaults(
    defineProps<{
        modelValue?: string;
        id?: string;
        type?:
            | 'text'
            | 'email'
            | 'password'
            | 'tel'
            | 'url'
            | 'date'
            | 'time'
            | 'datetime-local'
            | 'search';
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
