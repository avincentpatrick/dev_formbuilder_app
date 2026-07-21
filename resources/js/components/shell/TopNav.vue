<script setup lang="ts">
/**
 * Top navigation bar (DSR §3.4): wordmark + mobile hamburger (left); theme quick-toggle, feedback,
 * notifications, account menu (right). 64px tall; gains a shadow when the content region scrolls.
 * (Tenant switcher + global search are deferred to C3.)
 */
import { MdsIcon } from '@meridian/design-system';
import ThemeQuickToggle from './ThemeQuickToggle.vue';
import NotificationBell from './NotificationBell.vue';
import FeedbackButton from './FeedbackButton.vue';
import AccountMenu from './AccountMenu.vue';

defineProps<{ scrolled: boolean }>();
const emit = defineEmits<{ 'toggle-drawer': [] }>();
</script>

<template>
    <header class="topnav" :class="{ 'is-scrolled': scrolled }">
        <div class="topnav__left">
            <button
                type="button"
                class="topnav__hamburger"
                aria-label="Open navigation"
                @click="emit('toggle-drawer')"
            >
                <MdsIcon name="menu" size="md" aria-hidden="true" />
            </button>
            <span class="topnav__wordmark">Meridian</span>
        </div>

        <div class="topnav__right">
            <ThemeQuickToggle />
            <FeedbackButton />
            <NotificationBell />
            <AccountMenu />
        </div>
    </header>
</template>

<style scoped>
.topnav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
    height: 64px;
    flex-shrink: 0;
    padding: 0 var(--mds-space-4);
    background-color: var(--mds-color-bg-surface);
    border-bottom: 1px solid var(--mds-color-border-default);
    transition: box-shadow var(--mds-duration-base) var(--mds-ease-standard);
}

.topnav.is-scrolled {
    box-shadow: var(--mds-shadow-1);
}

/* min-width: 0 on both groups so they can shrink below their content instead of pushing the bar
   wider than the viewport. Without it a flex item's automatic minimum size is its content size, and
   the §2.9 text-size scale grows the wordmark ~25% at extra_large — at 375px that leaves only ~31px
   of headroom, i.e. one long tenant name from overflowing. */
.topnav__left {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    min-width: 0;
}

.topnav__wordmark {
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    font-weight: var(--mds-font-weight-bold);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--mds-color-action-primary-fg);
    /* Truncate rather than force the bar wide (see .topnav__left). */
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.topnav__right {
    display: flex;
    align-items: center;
    gap: var(--mds-space-1);
    min-width: 0;
}

.topnav__hamburger {
    display: none;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: none;
    border-radius: var(--mds-radius-md);
    background-color: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}

.topnav__hamburger:hover {
    background-color: var(--mds-color-bg-sunken);
}

.topnav__hamburger:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

@media (max-width: 480px) {
    .topnav__hamburger {
        display: inline-flex;
    }
}
</style>
