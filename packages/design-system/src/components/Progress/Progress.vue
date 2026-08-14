<script setup lang="ts">
/**
 * The determinate progress bar (DSR §3.9, Increment J4a).
 *
 * §3.9 specifies three patterns for three situations and calls them "not interchangeable": the
 * indeterminate `MdsSpinner`, the layout-preserving `MdsSkeleton`, and this — the measurable one. Its
 * governing rule is that a spinner is never used for an operation with a knowable fraction complete,
 * which is why this component exists at all rather than a page reaching for a spinner and a percentage.
 *
 * ── THE LABEL ROW IS UNCONDITIONAL, AND THAT IS THE WHOLE POINT OF THE API ──────────────────────────────
 * §3.9: "always paired with a numeric label — a bar alone is not sufficient (screen-reader and low-vision
 * users need the numeric equivalent, not just the visual fill level)". There is deliberately no
 * `labelHidden` prop and no way to render the track on its own, so that rule is unwriteable-wrong rather
 * than merely documented. `MdsBadge` makes the same move: its `dot` cannot be used to build the bare
 * coloured disc §3.8 forbids. A rule a component can be talked out of is a rule that will be.
 *
 * ── `aria-labelledby`, NOT `aria-label` ─────────────────────────────────────────────────────────────────
 * The visible label IS the accessible name, so the two cannot drift and WCAG 2.5.3 (Label in Name) holds
 * by construction. The hand-rolled meter this replaces passed the figures twice — once into a visible
 * element and once into an `aria-label` string — which is two places for one fact.
 *
 * ── `aria-valuetext` IS ALWAYS SET, BECAUSE THE PERCENTAGE IS OFTEN A LIE ────────────────────────────────
 * With `aria-valuenow="58"` and `aria-valuemax="100"` a screen reader announces "58 percent". When the
 * unit is responses against a plan cap that is wrong twice over: the number is a count, and the cap is not
 * always 100. `valueText` lets the caller pass the reading the user actually thinks in ("58 / 100") and it
 * is the same string the sighted user sees.
 *
 * ── THE FILL WIDTH IS `--progress-fill`, AND THE MISSING `--mds-` PREFIX IS DELIBERATE ───────────────────
 * `token-references.test.ts` scans every file for `var(--mds-...)` and fails on any name that is not a
 * real token — including one with a fallback, since it never evaluates the fallback. A component-local
 * `var(--mds-progress-fill, 0%)` would therefore red-light the whole package for a value that is not a
 * token and was never meant to be. Unprefixed, that gate ignores it by design.
 *
 * The inline style carries only the number; the geometry stays in the stylesheet behind that property.
 * That is what would let a later switch to `transform: scaleX()` happen without touching a call site, and
 * it is not the `:style`-binding §3.11 rules out — that rule is scoped to charts and a future CSP.
 *
 * ── `-fg` FOR THE FILL, NEVER `-bg` ─────────────────────────────────────────────────────────────────────
 * §3.4's standing rule: for any coloured rule, edge or indicator reach for `-fg`. A `-bg` token guarantees
 * only that text placed ON it is readable, which says nothing about the fill against its own track. The
 * meter this replaces used `--mds-color-action-primary-bg`, measuring 4.24:1 light and 3.95:1 dark against
 * `bg-sunken`; `-fg` measures 6.30:1 and 9.56:1, and carries the per-tenant brand-ramp guarantee that
 * `-bg` does not. The warning tone already used `status-warning-fg` and is unchanged.
 */
import { computed, useId } from 'vue';

export type ProgressTone = 'default' | 'warning';
export type ProgressSize = 'sm' | 'md';

const props = withDefaults(
    defineProps<{
        /** What is being measured. Rendered, and the bar's accessible name. */
        label: string;
        /** Current value in the caller's own unit, clamped into `[0, max]`. */
        value: number;
        max?: number;
        /**
         * The numeric equivalent, shown beside the label and announced as `aria-valuetext`. Defaults to a
         * rounded percentage; pass a domain-native reading whenever the fraction is not the unit the user
         * thinks in.
         */
        valueText?: string;
        tone?: ProgressTone;
        /** Track thickness. `sm` is the dense in-card meter, `md` a standalone one. */
        size?: ProgressSize;
    }>(),
    { max: 100, tone: 'default', size: 'sm' },
);

const labelId = useId();

/**
 * A non-finite value is a bug at the call site (a division by a zero cap, a not-yet-loaded figure), and a
 * bar has no honest way to render one — so it reads as "nothing done yet" rather than throwing or painting
 * a NaN-wide fill.
 */
const clamped = computed(() => {
    if (!Number.isFinite(props.value)) return 0;

    return Math.min(Math.max(props.value, 0), Math.max(props.max, 0));
});

/** `max <= 0` is degenerate rather than an error: nothing can be complete against a capacity of nothing. */
const percent = computed(() => (props.max > 0 ? Math.round((clamped.value / props.max) * 100) : 0));

const text = computed(() => props.valueText ?? `${percent.value}%`);
</script>

<template>
    <div class="mds-progress" :class="`mds-progress--${size}`">
        <div class="mds-progress__head">
            <span :id="labelId" class="mds-progress__label">{{ label }}</span>
            <span class="mds-progress__value">{{ text }}</span>
        </div>
        <div
            class="mds-progress__track"
            role="progressbar"
            :aria-labelledby="labelId"
            :aria-valuenow="clamped"
            :aria-valuemin="0"
            :aria-valuemax="max"
            :aria-valuetext="text"
            :style="{ '--progress-fill': `${percent}%` }"
        >
            <div class="mds-progress__fill" :class="`mds-progress__fill--${tone}`" />
        </div>
    </div>
</template>

<style scoped>
.mds-progress {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.mds-progress__head {
    display: flex;
    justify-content: space-between;
    gap: var(--mds-space-2);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.mds-progress__value {
    color: var(--mds-color-text-body);
    /* Tabular figures so a value ticking up does not shuffle the label row sideways. */
    font-variant-numeric: tabular-nums;
}

.mds-progress__track {
    display: block;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-bg-sunken);
    overflow: hidden;
}

.mds-progress--sm .mds-progress__track {
    height: 5px;
}

.mds-progress--md .mds-progress__track {
    height: 8px;
}

.mds-progress__fill {
    display: block;
    height: 100%;
    width: var(--progress-fill, 0%);
    border-radius: var(--mds-radius-full);
    transition: width var(--mds-duration-moderate) var(--mds-ease-standard);
}

.mds-progress__fill--default {
    background-color: var(--mds-color-action-primary-fg);
}

.mds-progress__fill--warning {
    background-color: var(--mds-color-status-warning-fg);
}

/* A meter that animates its own width is decorative motion, not information — the number beside it has
   already changed. Under reduced motion the fill simply arrives. */
@media (prefers-reduced-motion: reduce) {
    .mds-progress__fill {
        transition: none;
    }
}
</style>
