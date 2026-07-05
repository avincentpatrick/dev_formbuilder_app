<script setup lang="ts">
/**
 * The one Button. Every screen uses this — never a page-local button style.
 * Consumes SEMANTIC tokens only (never primitives), so it re-themes for free
 * under data-theme-mode / data-accent.
 *
 * Variants: primary (filled Blueprint), secondary (outlined Blueprint),
 * tertiary (text/ghost), destructive (filled Redline). icon-only → Increment C2.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'tertiary' | 'destructive';
        size?: 'sm' | 'md' | 'lg';
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        loading?: boolean;
    }>(),
    { variant: 'primary', size: 'md', type: 'button', disabled: false, loading: false },
);

// While loading the button stays focusable (aria-disabled, not native disabled) but must not
// fire a second submit — guard the click. Native disabled already blocks clicks.
function onClick(event: MouseEvent) {
    if (props.loading || props.disabled) {
        event.preventDefault();
        event.stopPropagation();
    }
}
</script>

<template>
    <button
        class="mds-button"
        :class="[
            `mds-button--${variant}`,
            `mds-button--${size}`,
            { 'mds-button--loading': loading },
        ]"
        :type="type"
        :disabled="disabled"
        :aria-disabled="disabled || loading || undefined"
        :aria-busy="loading || undefined"
        @click="onClick"
    >
        <span v-if="loading" class="mds-button__spinner" aria-hidden="true" />
        <slot />
    </button>
</template>

<style scoped>
.mds-button {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--mds-space-2);
    border: 1px solid transparent;
    border-radius: var(--mds-radius-md);
    font-family: var(--mds-font-family-body);
    font-weight: var(--mds-font-weight-semibold);
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
    transition:
        background-color var(--mds-duration-base) var(--mds-ease-standard),
        border-color var(--mds-duration-base) var(--mds-ease-standard),
        color var(--mds-duration-base) var(--mds-ease-standard);
}

/* Guarantee a ≥44×44px touch target (WCAG 2.2 §2.5.8 / §4.4) even when the visual box is
   smaller (sm/md) — a centered, transparent overlay that extends the clickable area. */
.mds-button::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 100%;
    height: 100%;
    min-width: 44px;
    min-height: 44px;
    transform: translate(-50%, -50%);
}

/* ── Sizes ─────────────────────────────────────────────────────────────── */
.mds-button--sm {
    min-height: 32px;
    padding: 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
}
.mds-button--md {
    min-height: 40px;
    padding: 0 var(--mds-space-4);
    font-size: var(--mds-type-body-md-font-size);
}
.mds-button--lg {
    min-height: 48px;
    padding: 0 var(--mds-space-5);
    font-size: var(--mds-type-body-lg-font-size);
}

/* Never remove the focus indicator — accessibility is a build-time constraint (WCAG 2.2 AA). */
.mds-button:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

/* ── Primary (filled Blueprint) ────────────────────────────────────────── */
.mds-button--primary {
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
}
.mds-button--primary:hover:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-primary-bg-hover);
}
.mds-button--primary:active:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-primary-bg-active);
}

/* ── Secondary (outlined Blueprint) ────────────────────────────────────── */
.mds-button--secondary {
    background-color: transparent;
    color: var(--mds-color-action-primary-fg);
    border-color: var(--mds-color-action-primary-fg);
}
.mds-button--secondary:hover:not(:disabled):not(.mds-button--loading),
.mds-button--tertiary:hover:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-primary-tint);
}

/* ── Tertiary (text / ghost) ───────────────────────────────────────────── */
.mds-button--tertiary {
    background-color: transparent;
    color: var(--mds-color-action-primary-fg);
}

/* ── Destructive (filled Redline) ──────────────────────────────────────── */
.mds-button--destructive {
    background-color: var(--mds-color-action-danger-bg);
    color: var(--mds-color-text-on-primary);
}
.mds-button--destructive:hover:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-danger-bg-hover);
}
.mds-button--destructive:active:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-danger-bg-active);
}

/* ── Disabled (native :disabled only — exempt from AA contrast, §4.1) ───── */
.mds-button:disabled {
    cursor: not-allowed;
}
.mds-button--primary:disabled,
.mds-button--destructive:disabled {
    background-color: var(--mds-color-action-disabled-bg);
    color: var(--mds-color-action-disabled-text);
    border-color: transparent;
}
.mds-button--secondary:disabled,
.mds-button--tertiary:disabled {
    color: var(--mds-color-text-disabled);
    border-color: var(--mds-color-border-default);
    background-color: transparent;
}

/* ── Loading ───────────────────────────────────────────────────────────── */
.mds-button--loading {
    cursor: progress;
}
.mds-button__spinner {
    width: 1em;
    height: 1em;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: var(--mds-radius-full);
    animation: mds-button-spin 600ms linear infinite;
}

@keyframes mds-button-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
