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
    /* JR1: was a hard-coded -0.01em. The tracking is part of the type ROLE, so it moved into the
       token beside the size it belongs to — three copies of a magic number could drift, a token cannot. */
    letter-spacing: var(--mds-type-heading-1-letter-spacing);
    color: var(--mds-color-text-heading);
    /* M19: the escape this rule never had, and the reason it is `anywhere` rather than `break-word`.
       The h1 is a flex item of `.page-header__heading`, so its automatic minimum size IS its min-content
       size — one unbreakable word — and `break-word` does not reduce min-content, only `anywhere` does.
       `min-width: 0` on the PARENT (`:49`) was the guard that looked like it covered this and cannot:
       it lets the heading box shrink, and the h1 then spills out of the shrunken box instead.
       Measured at 375px × extra_large: "Submissions" renders 324px into 276px of room and overruns the
       content region by 17px — invisible locally because `.app-shell` is `overflow-x: clip` AND because
       this host resolves the display stack to Segoe UI while CI resolves it to DejaVu Sans, ~27% wider.
       ⚠️ The worse instance is not the one that was filed: `Pages/forms/Show.vue` passes `form.title`
       here, so this is arbitrary tenant text at 48px with no wrapping escape at all. */
    overflow-wrap: anywhere;
}

/* Wrap rather than overflow: an action slot can carry several buttons (the webhook detail page runs to
   five), and a non-wrapping row spills past a 375px viewport — the responsive-overflow gate's failure mode.
   Same fix the builder toolbar took in H12b. */
.page-header__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}
</style>
