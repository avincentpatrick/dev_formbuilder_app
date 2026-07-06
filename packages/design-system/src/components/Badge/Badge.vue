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
    }>(),
    { variant: 'neutral' },
);
</script>

<template>
    <span class="mds-badge" :class="`mds-badge--${variant}`">
        <Icon v-if="icon" :name="icon" size="sm" class="mds-badge__icon" />
        {{ label }}
    </span>
</template>

<style scoped>
.mds-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    padding: var(--mds-space-0-5) var(--mds-space-2);
    border-radius: var(--mds-radius-full);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    font-weight: var(--mds-font-weight-medium);
    white-space: nowrap;
}

.mds-badge__icon {
    width: 12px;
    height: 12px;
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
