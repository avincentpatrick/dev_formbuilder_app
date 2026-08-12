<script setup lang="ts">
/**
 * A single dashboard KPI tile (DSR §3.5) — an icon badge beside a headline value and its label, on a
 * card surface. Presentational only: the parent computes and formats the value (e.g. `toLocaleString()`).
 * The icon is decorative (aria-hidden via MdsIcon's default); the value + label carry the meaning, so the
 * tile needs no extra ARIA.
 *
 * H24b1 added three optional states, all from ADR-0011. Every one is optional so the pre-existing call
 * sites keep working unchanged. (That count was written as "two" when H24b1 shipped and was never
 * revisited; as of J2a it is **17**, across seven files. Counted, not estimated.)
 *
 *  · `delta` — a period-over-period change. `null` means UNDEFINED, not zero: the analytics substrate
 *    returns `total.change === null` whenever the prior period held no rows, because a rise from nothing
 *    has no percentage. It renders as an em dash. Printing "+100%" there is the specific lie this prop
 *    exists to make impossible.
 *  · `unavailable` — a metric that cannot honestly be computed for the selected period says so instead
 *    of rendering a zero (§D5: a naive completion funnel returns 100% on a default-configured form and
 *    RISES every night as the draft reaper deletes its own denominator; suppression is the correct
 *    answer, and suppression rendered as 0% is the same defect wearing a different hat).
 *  · `caption` — §D5 again: both draft metrics must state their denominator on the face of the tile.
 *
 * The delta is an `MdsBadge` rather than coloured text on purpose. `--mds-color-danger-text` resolves to
 * `danger-300` in dark and fails contrast on `bg-surface` at this size (found in H21d1); the badge's
 * `status-*-fg` on `status-*-bg` is the pairing that is actually verified. The arrow glyph is the
 * redundant channel, so direction never depends on colour alone (WCAG 1.4.1).
 *
 * J2a added `href`, and it is a LINK rather than a click emit — the `MdsCard` `interactive`/`as` precedent
 * one directory over. Every tile on the dashboard names something the reader can go and look at, and until
 * J2 not one of them was reachable: eleven tiles and charts, all inert markup. A `click` emit on the
 * existing container would have been the smaller diff and the wrong answer — a div with a handler has no
 * accessible name, no role, no keyboard route and no hover affordance, and would fail the same axe job that
 * gates this package. Optional, so all pre-existing call sites keep rendering a plain container.
 *
 * ⚠️ AN EMPTY STRING IS TREATED AS NO LINK, and the guard is on `:href` as well as on `:is`. Without it,
 * `href: ''` — the obvious shape of a `row.can.view ? url : ''` call site — bound `href=""` onto the DIV:
 * invalid HTML, not a link, and no `--link` class, so it rendered as the inert tile it used to be while
 * carrying a bogus attribute. Found by the J2a adversarial review, not by a gate.
 */
import { computed } from 'vue';
import Badge from '../Badge/Badge.vue';
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';
import type { BadgeVariant } from '../Badge/status-variant';

const props = withDefaults(
    defineProps<{
        label: string;
        icon: IconName;
        /** Pre-formatted. `null`/omitted renders an em dash — a zero must be passed as `0`. */
        value?: string | number | null;
        /** Percent change vs. the prior period. `null` = undefined (no prior data), never zero. */
        delta?: number | null;
        /** Names the comparison, e.g. "vs. previous 30 days". Required for `delta` to render. */
        deltaLabel?: string;
        unavailable?: boolean;
        unavailableNote?: string;
        caption?: string;
        /** When set the whole tile becomes a link to this destination (J2a). */
        href?: string;
    }>(),
    {
        value: null,
        delta: undefined,
        deltaLabel: undefined,
        unavailable: false,
        unavailableNote: undefined,
        caption: undefined,
        href: undefined,
    },
);

const EM_DASH = '—';

/** `??`, never `||` — a numeric 0 is a real value and must not fall through to the dash. */
const displayValue = computed(() => (props.unavailable ? EM_DASH : (props.value ?? EM_DASH)));

const showDelta = computed(() => !props.unavailable && props.deltaLabel !== undefined);

const deltaVariant = computed<BadgeVariant>(() => {
    if (props.delta === null || props.delta === undefined || props.delta === 0) return 'neutral';

    return props.delta > 0 ? 'success' : 'danger';
});

const deltaIcon = computed<IconName | undefined>(() => {
    if (props.delta === null || props.delta === undefined || props.delta === 0) return undefined;

    return props.delta > 0 ? 'trend-up' : 'trend-down';
});

const deltaText = computed(() => {
    if (props.delta === null || props.delta === undefined) return EM_DASH;

    return `${props.delta > 0 ? '+' : ''}${props.delta}%`;
});
</script>

<template>
    <component
        :is="href ? 'a' : 'div'"
        class="mds-stat-tile"
        :class="{ 'mds-stat-tile--link': href }"
        :href="href || undefined"
    >
        <span class="mds-stat-tile__badge" aria-hidden="true">
            <Icon :name="icon" size="md" />
        </span>
        <div class="mds-stat-tile__text">
            <p class="mds-stat-tile__value">{{ displayValue }}</p>
            <p class="mds-stat-tile__label">{{ label }}</p>

            <p v-if="unavailable && unavailableNote" class="mds-stat-tile__note">{{ unavailableNote }}</p>

            <p v-if="showDelta" class="mds-stat-tile__delta">
                <Badge :variant="deltaVariant" :icon="deltaIcon" :label="deltaText" />
                <span class="mds-stat-tile__delta-label">{{ deltaLabel }}</span>
            </p>

            <p v-if="caption" class="mds-stat-tile__caption">{{ caption }}</p>
        </div>
    </component>
</template>

<style scoped>
.mds-stat-tile {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-4);
    padding: var(--mds-space-5);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    /* JR2: the page-level card tier, in step with `MdsCard` (DSR §2.6). The icon chip below stays at
       `lg`, so the nesting reads 20 outside / 16 inside rather than the flat 12/16 it was. */
    border-radius: var(--mds-radius-xl);
    box-shadow: var(--mds-shadow-1);
    color: var(--mds-color-text-body);
}

/* A linked tile keeps the tile's own typography — no underline — and gains the raise-on-hover + focus
   ring `MdsCard--interactive` uses, so the two read as one family. It does NOT set `color`: the base
   `.mds-stat-tile` rule already pins `--mds-color-text-body`, and an author rule beats the UA's link
   colour regardless of specificity, so the two variants stay colour-equivalent by construction rather
   than by one of them inheriting.

   ⚠️ THE ACCESSIBLE NAME IS THE TILE'S WHOLE TEXT, not just the value and label — the delta badge, its
   comparison label and the caption all join it, because an anchor's name is its contents. That is correct
   for a card-shaped link and is why no `aria-label` is added (one would HIDE the delta from screen-reader
   users while sighted readers keep seeing it). `StatTile.test.ts` asserts the exact name so it cannot
   drift silently; an earlier version of this comment claimed only the value and label, and the test that
   was supposed to guard it asserted `toContain` and could not have failed. */
.mds-stat-tile--link {
    text-decoration: none;
    cursor: pointer;
    transition:
        box-shadow var(--mds-duration-base) var(--mds-ease-standard),
        border-color var(--mds-duration-base) var(--mds-ease-standard);
}

/* JR2: the accent hover edge, identical to `MdsCard--interactive` so the two keep reading as one
   family. See that rule for the measured ratios and for why `-fg` rather than `-bg`. */
.mds-stat-tile--link:hover {
    box-shadow: var(--mds-shadow-2);
    border-color: var(--mds-color-action-primary-fg);
}

.mds-stat-tile--link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-stat-tile__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    flex-shrink: 0;
    border-radius: var(--mds-radius-lg);
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-action-primary-fg);
}

.mds-stat-tile__text {
    min-width: 0;
}

.mds-stat-tile__value {
    margin: 0 0 var(--mds-space-1);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-1-font-size);
    /* JR2: 1.05 rather than the role's 46px leading. A stat value is a single line by construction
       (`displayValue` never wraps a formatted number), so the role's line box only adds 8px of dead
       space above the figure and pushes the label away from it. */
    line-height: 1.05;
    font-weight: var(--mds-type-heading-1-font-weight);
    /* JR1 wrote the sentence below and shipped only half of it: the tracking landed, the tabular
       figures did not. Both charts carry `font-variant-numeric` and the one number a user actually
       reads did not, so a column of tiles jittered by a fraction per digit. JR2 adds the missing
       declaration — a stat value is the one place a heading-1 is nearly always digits, and
       tabular-nums plus tight tracking is what makes a column of them line up. */
    letter-spacing: var(--mds-type-heading-1-letter-spacing);
    font-variant-numeric: tabular-nums;
    color: var(--mds-color-text-heading);
}

/* JR2 — the approved direction's signature, and the one place in the system a text colour does not
   come from a text token (exceptions-log #11). The figure is filled with a top-to-bottom gradient
   from the heading ink into 22% of the accent, which is what gives the dashboard its lift.

   Three guards, each load-bearing:
     · the plain `color` above stays the BASE declaration, so any engine that does not take
       `background-clip: text` renders an ordinary heading-ink number rather than nothing;
     · `-webkit-text-fill-color` is set alongside `color`, because in WebKit that property — not
       `color` — is what the clipped background actually paints through;
     · forced-colors is restored explicitly. Without it a Windows High Contrast user gets
       `color: transparent` over a background the mode has already stripped, i.e. an invisible
       number, which is the whole reason this pattern is usually a defect.
   Both gradient endpoints clear 10:1 on their ground in both themes, so the fill costs no legibility. */
@supports (background-clip: text) or (-webkit-background-clip: text) {
    .mds-stat-tile__value {
        background-image: linear-gradient(
            180deg,
            var(--mds-color-text-heading),
            color-mix(in srgb, var(--mds-color-text-heading) 78%, var(--mds-color-action-primary-bg))
        );
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        -webkit-text-fill-color: transparent;
    }
}

@media (forced-colors: active) {
    .mds-stat-tile__value {
        background-image: none;
        color: CanvasText;
        -webkit-text-fill-color: CanvasText;
    }
}

/* JR2: an uppercased micro-label under the figure, the direction's treatment for a stat caption.
   `text-transform` does not touch `textContent`, so the tile's accessible name — asserted verbatim
   in StatTile.test.ts — reads exactly as it did before. */
.mds-stat-tile__label {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    font-weight: var(--mds-font-weight-semibold);
    letter-spacing: var(--mds-tracking-wide);
    text-transform: uppercase;
    color: var(--mds-color-text-secondary);
}

.mds-stat-tile__note,
.mds-stat-tile__caption {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.mds-stat-tile__delta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    margin: var(--mds-space-3) 0 0;
    font-size: var(--mds-type-caption-font-size);
}

.mds-stat-tile__delta-label {
    color: var(--mds-color-text-secondary);
}
</style>
