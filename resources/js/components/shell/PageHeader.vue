<script setup lang="ts">
/**
 * Standard page header rendered by each page as the first child of its content region (the shell
 * owns nav/chrome; the page owns only its content — DSR §3.0). Provides the single page <h1>, an
 * optional primary-action slot, and an optional breadcrumbs slot (unused until nested pages exist).
 */
defineProps<{ title: string }>();
</script>

<template>
    <header class="page-header">
        <div v-if="$slots.breadcrumbs" class="page-header__crumbs"><slot name="breadcrumbs" /></div>
        <div class="page-header__row">
            <h1 class="page-header__title">{{ title }}</h1>
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

.page-header__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-1-font-size);
    line-height: var(--mds-type-heading-1-line-height);
    font-weight: var(--mds-type-heading-1-font-weight);
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--mds-color-text-heading);
}

.page-header__actions {
    display: flex;
    gap: var(--mds-space-2);
}
</style>
