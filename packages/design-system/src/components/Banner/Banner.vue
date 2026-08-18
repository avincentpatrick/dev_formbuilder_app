<script setup lang="ts">
/**
 * A persistent, page-level notice (DSR §3.8's sibling to Badge and Toast).
 *
 * ── WHY THIS IS NOT A TOAST, WHICH IS THE INTERESTING PART ──────────────────────────────────────────────
 * `MdsToast` announces something that HAPPENED and then leaves. A banner states a CONDITION the page is
 * currently in, and stays as long as the condition does. The distinction is not stylistic: a toast is
 * `role="status"` and disappears, so a screen-reader user who arrives after it has gone has no way to learn
 * the state — which is the wrong shape for "you are impersonating someone" or "this workspace is in
 * maintenance". A banner is part of the document and can be navigated back to.
 *
 * ── COLOUR IS NEVER THE ONLY CHANNEL (WCAG 1.4.1) ───────────────────────────────────────────────────────
 * Every variant renders its icon AND its text; the tone tints the surface. A `danger` banner and an `info`
 * banner are told apart by their words and their glyph, not only by hue — the same rule Badge follows, and
 * the reason `icon` is required rather than optional here.
 *
 * ── `role="status"` RATHER THAN `role="alert"` ──────────────────────────────────────────────────────────
 * `alert` is assertive: it interrupts whatever the user is reading, which is right for an error that just
 * occurred and wrong for a standing condition that was already true when the page loaded. `status` is
 * polite — announced at the next natural pause. A banner that shouted over the page on every navigation
 * would make the impersonation surface actively hostile to a screen-reader user, who would hear it before
 * every page's heading, forever.
 */
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

export type BannerTone = 'info' | 'warning' | 'danger';

withDefaults(
    defineProps<{
        tone?: BannerTone;
        icon: IconName;
        /** The condition, stated plainly. Keep it to one line — the banner is a status, not a paragraph. */
        message: string;
    }>(),
    { tone: 'info' },
);
</script>

<template>
    <div class="mds-banner" :class="`mds-banner--${tone}`" role="status">
        <Icon :name="icon" size="sm" class="mds-banner__icon" aria-hidden="true" />
        <p class="mds-banner__message">{{ message }}</p>
        <!-- The action slot is where a way OUT of the condition goes. Optional: a maintenance banner has
             no action, an impersonation banner must have one. -->
        <div v-if="$slots.action" class="mds-banner__action"><slot name="action" /></div>
    </div>
</template>

<style scoped>
.mds-banner {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2) var(--mds-space-4);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.mds-banner__icon {
    flex: none;
}

.mds-banner__message {
    margin: 0;
    /* Takes the slack so the action sits hard right at every width. */
    flex: 1 1 auto;
    min-width: 0;
}

.mds-banner__action {
    flex: none;
}

.mds-banner--info {
    background-color: var(--mds-color-status-info-bg);
    color: var(--mds-color-status-info-fg);
}
.mds-banner--warning {
    background-color: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
}
.mds-banner--danger {
    background-color: var(--mds-color-status-danger-bg);
    color: var(--mds-color-status-danger-fg);
}
</style>
