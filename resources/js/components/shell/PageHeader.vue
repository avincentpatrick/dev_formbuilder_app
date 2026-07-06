<script setup lang="ts">
/**
 * Standard page header rendered by each page as the first child of its content region (the shell
 * owns nav/chrome; the page owns only its content — DSR §3.0). Provides the single page <h1>, an
 * optional leading icon badge, an optional primary-action slot, and an optional breadcrumbs slot
 * (unused until nested pages exist).
 */
import { MdsIcon, type IconName } from '@meridian/design-system';

defineProps<{ title: string; icon?: IconName }>();
</script>

<template>
    <header class="page-header">
        <div v-if="$slots.breadcrumbs" class="page-header__crumbs"><slot name="breadcrumbs" /></div>
        <div class="page-header__row">
            <div class="page-header__heading">
                <span v-if="icon" class="page-header__badge" aria-hidden="true">
                    <MdsIcon :name="icon" size="md" />
                </span>
                <h1 class="page-header__title">{{ title }}</h1>
            </div>
            <div v-if="$slots.actions" class="page-header__actions"><slot name="actions" /></div>
        </div>
    </header>
</template>

<style scoped>
.page-header {
    margin-bottom: var(--mds-space-6);
}

.page-header__crumbs {
    margin-bottom: var(--mds-space-2);
}

.page-header__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
}

.page-header__heading {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    min-width: 0;
}

/* Tinted rounded badge holding the page glyph — the icony page-title treatment. */
.page-header__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: var(--mds-radius-lg);
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-action-primary-fg);
}

.page-header__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-1-font-size);
    line-height: var(--mds-type-heading-1-line-height);
    font-weight: var(--mds-type-heading-1-font-weight);
    letter-spacing: -0.01em;
    color: var(--mds-color-text-heading);
}

.page-header__actions {
    display: flex;
    gap: var(--mds-space-2);
}
</style>
