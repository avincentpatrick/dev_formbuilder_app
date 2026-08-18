<script setup lang="ts">
/**
 * The one Button. Every screen uses this — never a page-local button style.
 * Consumes SEMANTIC tokens only (never primitives), so it re-themes for free
 * under data-theme-mode / data-accent.
 *
 * Variants: primary (filled Blueprint), secondary (outlined Blueprint),
 * tertiary (text/ghost), destructive (filled Redline).
 *
 * `iconLeft`/`iconRight` render a shared MdsIcon glyph before/after the label — the standard way
 * to make an action iconic without every page hand-rolling icon+text markup. Icons are decorative
 * (the button's text label is the accessible name); while loading, the leading spinner replaces
 * `iconLeft`.
 *
 * `as="a"` + `href` renders a real anchor with identical styling, mirroring MdsCard. An action that
 * NAVIGATES — especially to another origin, like an OAuth consent screen — has to be a link: a
 * <button> announces the wrong role, drops middle-click and "open in new tab", and would otherwise
 * push pages into hand-rolling their own link-that-looks-like-a-button (H15b). A disabled anchor is
 * expressed with aria-disabled + no href, since HTML has no disabled attribute for links.
 */
import { computed } from 'vue';
import MdsIcon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'tertiary' | 'destructive';
        size?: 'sm' | 'md' | 'lg';
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        loading?: boolean;
        iconLeft?: IconName;
        iconRight?: IconName;
        as?: 'button' | 'a';
        href?: string;
    }>(),
    { variant: 'primary', size: 'md', type: 'button', disabled: false, loading: false, as: 'button' },
);

// Dropping href is what actually makes a disabled link inert — an <a> ignores the disabled attribute,
// and keeping the href would leave it clickable and in the tab order while claiming to be disabled.
const isLink = computed(() => props.as === 'a');
const resolvedHref = computed(() => (isLink.value && !props.disabled && !props.loading ? props.href : undefined));

// Glyphs are sized down one step from the text so they sit optically balanced beside the label.
const iconSize = computed(() => (props.size === 'lg' ? 'md' : 'sm'));

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
    <component
        :is="as"
        class="mds-button"
        :class="[
            `mds-button--${variant}`,
            `mds-button--${size}`,
            { 'mds-button--loading': loading, 'mds-button--disabled': isLink && disabled },
        ]"
        :type="isLink ? undefined : type"
        :disabled="isLink ? undefined : disabled"
        :href="resolvedHref"
        :aria-disabled="disabled || loading || undefined"
        :aria-busy="loading || undefined"
        @click="onClick"
    >
        <span v-if="loading" class="mds-button__spinner" aria-hidden="true" />
        <MdsIcon v-else-if="iconLeft" :name="iconLeft" :size="iconSize" />
        <slot />
        <MdsIcon v-if="iconRight" :name="iconRight" :size="iconSize" />
    </component>
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
        color var(--mds-duration-base) var(--mds-ease-standard),
        box-shadow var(--mds-duration-base) var(--mds-ease-standard);
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
/* JR2 — the glow. Before this the button read no shadow token at any state and was the flattest
   thing in the six, which is most of why the app looked plain: every page's main action was a
   rectangle of colour sitting ON the surface rather than above it. `--mds-shadow-accent` is mixed
   off `--mds-color-action-primary-bg` at build time, so a brand-themed tenant's button glows in
   their own accent — a literal rgba here would put a blue halo under a teal button. It is NOT a rung
   of the shadow-0..5 ladder; see the token's own note. */
.mds-button--primary {
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
    box-shadow: var(--mds-shadow-accent);
}
.mds-button--primary:hover:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-primary-bg-hover);
}
/* The glow goes on press. A lifted thing that stays lifted while you push it is the one motion cue
   users read as broken, and dropping the shadow is what makes the darker `-bg-active` fill read as
   *settling* rather than merely changing colour. */
.mds-button--primary:active:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-primary-bg-active);
    box-shadow: none;
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
    /* Its own glow token rather than the accent one — a red fill under a blue halo is the tell that
       a shadow was pasted rather than derived. */
    box-shadow: var(--mds-shadow-danger);
}
.mds-button--destructive:hover:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-danger-bg-hover);
}
.mds-button--destructive:active:not(:disabled):not(.mds-button--loading) {
    background-color: var(--mds-color-action-danger-bg-active);
    box-shadow: none;
}

/* ── Disabled (exempt from AA contrast, §4.1) ──────────────────────────────
   An <a> ignores :disabled, so the as="a" form carries .mds-button--disabled instead; the two
   selectors are paired everywhere so a link and a button never disagree about what disabled looks
   like. `pointer-events: none` is the link's stand-in for the click blocking :disabled gives free —
   the href is already dropped, so this only stops the cursor lying about it. */
.mds-button:disabled,
.mds-button--disabled {
    cursor: not-allowed;
}
.mds-button--disabled {
    pointer-events: none;
}
/* JR2: the glow is a property of a LIVE fill, so it goes wherever the fill stops being one. A
   disabled button whose grey face still casts a coloured halo reads as enabled-but-broken. */
.mds-button--primary:disabled,
.mds-button--destructive:disabled,
.mds-button--primary.mds-button--disabled,
.mds-button--destructive.mds-button--disabled {
    background-color: var(--mds-color-action-disabled-bg);
    color: var(--mds-color-action-disabled-text);
    border-color: transparent;
    box-shadow: none;
}
.mds-button--secondary:disabled,
.mds-button--tertiary:disabled,
.mds-button--secondary.mds-button--disabled,
.mds-button--tertiary.mds-button--disabled {
    color: var(--mds-color-text-disabled);
    border-color: var(--mds-color-border-default);
    background-color: transparent;
}

/* ── Loading ───────────────────────────────────────────────────────────── */
/* Same reasoning as disabled: a button that is busy is not a button you can press, so the glow goes
   for the duration and returns when it can be pressed again. */
.mds-button--loading {
    cursor: progress;
    box-shadow: none;
}
.mds-button__spinner {
    width: 1em;
    height: 1em;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: var(--mds-radius-full);
    /* ⚠️ The literal is CORRECT here and must not be "tidied" into `--mds-duration-deliberate`,
       which is also 600ms and looks like a free improvement. Reduced motion collapses every duration
       token to 1ms centrally (theme-overrides.css §2.8), so a tokenised spinner would run at ~1000
       revolutions per second — a strobing disc, and strictly worse for the user the setting exists to
       protect. `MdsSpinner` reached the same conclusion independently (Spinner.vue:69-73) and its
       comment states the principle: a loading spinner is a FUNCTIONAL indicator, so it keeps moving
       under reduced motion, just slower. This spinner had no reduced-motion arm at all; JR2 adopts
       Spinner's, so the two now agree. */
    animation: mds-button-spin 600ms linear infinite;
}

@media (prefers-reduced-motion: reduce) {
    .mds-button__spinner {
        animation-duration: 1500ms;
    }
}

@keyframes mds-button-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
