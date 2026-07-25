<script setup lang="ts">
/**
 * A single dashboard KPI tile (DSR §3.5) — an icon badge beside a headline value and its label, on a
 * card surface. Presentational only: the parent computes and formats the value (e.g. `toLocaleString()`).
 * The icon is decorative (aria-hidden via MdsIcon's default); the value + label carry the meaning, so the
 * tile needs no extra ARIA.
 */
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

defineProps<{
    label: string;
    value: string | number;
    icon: IconName;
}>();
</script>

<template>
    <div class="mds-stat-tile">
        <span class="mds-stat-tile__badge" aria-hidden="true">
            <Icon :name="icon" size="md" />
        </span>
        <div class="mds-stat-tile__text">
            <p class="mds-stat-tile__value">{{ value }}</p>
            <p class="mds-stat-tile__label">{{ label }}</p>
        </div>
    </div>
</template>

<style scoped>
.mds-stat-tile {
    display: flex;
    align-items: center;
    gap: var(--mds-space-4);
    padding: var(--mds-space-5);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-1);
    color: var(--mds-color-text-body);
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
    line-height: var(--mds-type-heading-1-line-height);
    font-weight: var(--mds-type-heading-1-font-weight);
    color: var(--mds-color-text-heading);
}

.mds-stat-tile__label {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
