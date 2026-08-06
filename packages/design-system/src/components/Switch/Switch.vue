<script setup lang="ts">
/**
 * The shared on/off switch (DSR §3.2). A real native <input type="checkbox"> (visually hidden but fully
 * keyboard-operable) under a track + thumb, with an inline label — the SAME contract as `Checkbox`
 * (`id`/`name`/`describedby`/`invalid`/`disabled`/`required`, `update:modelValue`), so the two are
 * interchangeable at a call site and a swap is mechanical rather than a rewrite of every test locator.
 *
 * ── WHY THIS EXISTS NOW, AND NOT BEFORE ────────────────────────────────────────────────────────────────
 * §3.2 specified a switch from the start and G11 deliberately declined to build one, on the grounds that
 * the single boolean shipped at the time (the dyslexia-font opt-in) would have become "a checkbox with
 * extra steps". Increment I5 is the case that argument anticipated: App Settings is a page of *settings*,
 * where every control answers "is this capability on for my organisation" — a state, not a selection from
 * a set. Fourteen notification channels sit beside them. Checkbox stays the right control for choosing
 * items out of a list; this one is for turning a thing on.
 *
 * ── THE NON-COLOUR SIGNIFIER IS THUMB POSITION (WCAG 1.4.1) ────────────────────────────────────────────
 * §3.2's table originally asked for a visible "On"/"Off" string beside the control. Thumb POSITION is
 * itself a non-colour visual signifier — it is the reason a switch is legible in greyscale and to a
 * monochrome-vision user — and a per-row state word beside fourteen controls reads as noise, not as help.
 * The check glyph inside the thumb is a second, redundant cue, mirroring the device `Checkbox` already
 * uses. The doc's §3.2 row was amended in the same PR to record this rather than left to disagree.
 *
 * ── `role="switch"` ON A NATIVE CHECKBOX, ON PURPOSE ───────────────────────────────────────────────────
 * The element stays `input[type="checkbox"]` — that is what keeps `.checked`, `disabled`,
 * `aria-describedby` and every existing `input[type="checkbox"]` test locator working across the swap —
 * while the role reports what the control actually is to assistive tech. ARIA in HTML permits the
 * combination, and axe does not require an explicit `aria-checked` for it because the native checked state
 * already maps. No `getByRole('checkbox')` locator exists in the repo (checked before choosing this).
 *
 * Consumes semantic tokens only — every name here is one `Checkbox.vue` already uses, which is what keeps
 * `token-references.test.ts` green without touching the token files.
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
    <label class="mds-switch" :class="{ 'mds-switch--disabled': disabled }">
        <input
            :id="id"
            class="mds-switch__input"
            type="checkbox"
            role="switch"
            :name="name"
            :checked="modelValue"
            :disabled="disabled"
            :required="required"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedby"
            @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
        />
        <span class="mds-switch__track" :class="{ 'mds-switch__track--invalid': invalid }" aria-hidden="true">
            <span class="mds-switch__thumb">
                <svg class="mds-switch__check" viewBox="0 0 24 24" fill="none" focusable="false">
                    <path
                        d="M5 12l4 4 10-10"
                        stroke="currentColor"
                        stroke-width="3.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </span>
        </span>
        <span class="mds-switch__label">{{ label }}</span>
    </label>
</template>

<style scoped>
.mds-switch {
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

.mds-switch--disabled {
    cursor: not-allowed;
    /* The visible label sits in a <span>, not the (disabled) <input>, so axe can't apply the disabled
       contrast exemption — dim to text-secondary (still ≥4.5:1) rather than text-disabled. The dimmed
       track + not-allowed cursor carry the disabled cue. */
    color: var(--mds-color-text-secondary);
}

/* Native control: hidden visually, still focusable + operable. */
.mds-switch__input {
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

.mds-switch__track {
    position: relative;
    display: inline-block;
    flex-shrink: 0;
    box-sizing: border-box;
    width: 40px;
    height: 24px;
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-bg-sunken);
    transition:
        background-color var(--mds-duration-fast) var(--mds-ease-standard),
        border-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.mds-switch:hover .mds-switch__track {
    border-color: var(--mds-color-input-border-hover);
}

.mds-switch__track--invalid {
    border-color: var(--mds-color-action-danger-bg);
}

/* Off: a solid grey thumb parked left. Deliberately NOT input-bg — the thumb must clear 3:1 against its
   own track (WCAG 1.4.11), and a surface-coloured thumb on a sunken track does not. */
.mds-switch__thumb {
    position: absolute;
    inset-block-start: 3px;
    inset-inline-start: 3px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-text-secondary);
    transition:
        transform var(--mds-duration-fast) var(--mds-ease-standard),
        background-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.mds-switch__check {
    width: 11px;
    height: 11px;
    color: var(--mds-color-action-primary-bg);
    opacity: 0;
}

/* On: filled track + thumb travelled to the right + the check glyph. Position is the signifier; the fill
   and the glyph are redundant cues on top of it. 40 − 3 − 16 − 3 = 18. */
.mds-switch__input:checked + .mds-switch__track {
    background-color: var(--mds-color-action-primary-bg);
    border-color: var(--mds-color-action-primary-bg);
}
.mds-switch__input:checked + .mds-switch__track .mds-switch__thumb {
    transform: translateX(18px);
    background-color: var(--mds-color-text-on-primary);
}
.mds-switch__input:checked + .mds-switch__track .mds-switch__check {
    opacity: 1;
}

/* Keyboard focus ring around the WHOLE track (§3.2), not the thumb. */
.mds-switch__input:focus-visible + .mds-switch__track {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-switch--disabled .mds-switch__track {
    opacity: 0.6;
}
</style>
