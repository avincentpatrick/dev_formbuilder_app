<script setup lang="ts">
/**
 * Status pill / badge (DSR §3.8). A small, non-interactive label that communicates an enum state
 * (a member's status, a tenant's status, …). The visible text label is ALWAYS the signifier — colour
 * is never the only channel (WCAG 1.4.1), so a `neutral` grey and a `success` green pill are still
 * told apart by their words. Consumes semantic status tokens only, so it re-themes for free.
 *
 * The enum → {variant,label} mapping lives once in `status-variant.ts` (§3.8) — pages pass a resolved
 * variant + label here, they never re-decide the colour per screen.
 */
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';
import type { BadgeVariant } from './status-variant';

withDefaults(
    defineProps<{
        variant?: BadgeVariant;
        label: string;
        icon?: IconName;
        /**
         * JR2 — a 6px `currentColor` disc before the label, the approved direction's treatment for a
         * LIST status pill. Off by default: most badges in the app are inline annotations rather than
         * a scannable status column, and a dot on all fifty-odd of them is noise.
         *
         * It is decoration, never a signifier — the label still carries the meaning, so WCAG 1.4.1
         * is satisfied by the word exactly as it was before, and the disc is `aria-hidden`.
         */
        dot?: boolean;
    }>(),
    { variant: 'neutral', dot: false },
);
</script>

<template>
    <span class="mds-badge" :class="`mds-badge--${variant}`">
        <!-- `&& !icon` is a guard, not tidiness: `MdsStatTile` passes `trend-up`/`trend-down` to its
             delta badge, and a caller that also set `dot` would put a disc and an arrow side by side.
             The icon wins because it is the one carrying information. -->
        <span v-if="dot && !icon" class="mds-badge__dot" aria-hidden="true" />
        <Icon v-if="icon" :name="icon" size="sm" class="mds-badge__icon" />
        {{ label }}
    </span>
</template>

<style scoped>
.mds-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    /* JR2: 4px of vertical padding rather than 2px, and semibold rather than medium — the direction's
       pill is a slightly taller, slightly firmer object. Radius stays `full`, and the badge keeps
       having no border and no shadow: it is a tinted fill with text on it, and every variant below
       is a sanctioned `-bg`/`-fg` pair, so the contrast is guaranteed by construction. */
    padding: var(--mds-space-1) var(--mds-space-2);
    border-radius: var(--mds-radius-full);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    font-weight: var(--mds-font-weight-semibold);
    white-space: nowrap;
}

.mds-badge__icon {
    width: 12px;
    height: 12px;
}

/* `currentColor` rather than a status token: the disc then tracks whichever `-fg` the variant set,
   so it can never drift out of step with the label beside it, and a variant added later needs no
   rule here. */
.mds-badge__dot {
    width: 6px;
    height: 6px;
    flex: none;
    border-radius: var(--mds-radius-full);
    background-color: currentColor;
}

.mds-badge--success {
    background-color: var(--mds-color-status-success-bg);
    color: var(--mds-color-status-success-fg);
}
.mds-badge--warning {
    background-color: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
}
.mds-badge--danger {
    background-color: var(--mds-color-status-danger-bg);
    color: var(--mds-color-status-danger-fg);
}
.mds-badge--info {
    background-color: var(--mds-color-status-info-bg);
    color: var(--mds-color-status-info-fg);
}
.mds-badge--neutral {
    background-color: var(--mds-color-status-neutral-bg);
    color: var(--mds-color-status-neutral-fg);
}
</style>
