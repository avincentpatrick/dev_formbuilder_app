<script setup lang="ts">
/**
 * Primary sidebar navigation. Enabled items are real Inertia <Link>s (standard navigation — a list
 * of links, not a roving menu widget); the active item shows three non-color signifiers (left accent
 * bar + bold + tint). Disabled Phase-1 destinations render as inert "Soon" rows. Responsive: full
 * (>1024) → icon-only (≤1024) → off-canvas drawer (≤480) toggled from the top nav.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { MdsIcon } from '@meridian/design-system';
import { navItems } from './nav-model';

defineProps<{ drawerOpen: boolean }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage();

function isActive(href?: string): boolean {
    if (!href) return false;
    const path = page.url.split('?')[0];
    return path === href || path.startsWith(`${href}/`);
}
</script>

<template>
    <div class="sidebar-wrap" :class="{ 'is-open': drawerOpen }">
        <div class="sidebar-scrim" @click="emit('close')" />
        <nav class="sidebar" aria-label="Primary">
            <ul class="sidebar__list">
                <li v-for="item in navItems" :key="item.key">
                    <Link
                        v-if="item.enabled && item.href"
                        :href="item.href"
                        class="sidebar__item"
                        :class="{ 'is-active': isActive(item.href) }"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                        :title="item.label"
                        @click="emit('close')"
                    >
                        <MdsIcon :name="item.icon" size="md" />
                        <span class="sidebar__label">{{ item.label }}</span>
                    </Link>
                    <span
                        v-else
                        class="sidebar__item sidebar__item--disabled"
                        aria-disabled="true"
                        :title="`${item.label} — coming soon`"
                    >
                        <MdsIcon :name="item.icon" size="md" />
                        <span class="sidebar__label">{{ item.label }}</span>
                        <span class="sidebar__soon">Soon</span>
                    </span>
                </li>
            </ul>
        </nav>
    </div>
</template>

<style scoped>
.sidebar-wrap {
    flex-shrink: 0;
}

.sidebar-scrim {
    display: none;
}

.sidebar {
    width: 240px;
    height: 100%;
    padding: var(--mds-space-4) var(--mds-space-3);
    background-color: var(--mds-color-bg-surface);
    border-right: 1px solid var(--mds-color-border-default);
    overflow-y: auto;
}

.sidebar__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
}

.sidebar__item {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    min-height: 40px;
    padding: 0 var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
    text-decoration: none;
}

a.sidebar__item:hover:not(.is-active) {
    background-color: var(--mds-color-bg-sunken);
}

a.sidebar__item:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
}

.sidebar__item.is-active {
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-semibold);
}

/* Left-edge accent bar — a non-color signifier of the active item, paired with the bold weight. */
.sidebar__item.is-active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 60%;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-action-primary-bg);
}

.sidebar__item--disabled {
    color: var(--mds-color-text-secondary);
    cursor: default;
}

.sidebar__label {
    flex: 1;
}

.sidebar__soon {
    padding: 0 var(--mds-space-1);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-medium);
}

/* ── Tablet (≤1024): icon-only, labels available to AT via sr-only + tooltip ─────────── */
@media (max-width: 1024px) {
    .sidebar {
        width: 64px;
        padding: var(--mds-space-4) var(--mds-space-2);
    }
    .sidebar__item {
        justify-content: center;
        gap: 0;
    }
    .sidebar__label {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
    .sidebar__soon {
        display: none;
    }
}

/* ── Mobile (≤480): off-canvas drawer with scrim ────────────────────────────────────── */
@media (max-width: 480px) {
    .sidebar-wrap {
        position: fixed;
        inset: 0;
        z-index: 40;
        pointer-events: none;
        visibility: hidden;
    }
    .sidebar-wrap.is-open {
        pointer-events: auto;
        visibility: visible;
    }
    .sidebar-scrim {
        display: block;
        position: absolute;
        inset: 0;
        background-color: var(--mds-color-overlay-scrim);
        opacity: 0;
        transition: opacity var(--mds-duration-base) var(--mds-ease-standard);
    }
    .sidebar-wrap.is-open .sidebar-scrim {
        opacity: 1;
    }
    .sidebar {
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        width: 260px;
        padding: var(--mds-space-4) var(--mds-space-3);
        transform: translateX(-100%);
        transition: transform var(--mds-duration-base) var(--mds-ease-decelerate);
        box-shadow: var(--mds-shadow-4);
    }
    .sidebar-wrap.is-open .sidebar {
        transform: translateX(0);
    }
    /* Labels return inside the open drawer. */
    .sidebar__item {
        justify-content: flex-start;
        gap: var(--mds-space-3);
    }
    .sidebar__label {
        position: static;
        width: auto;
        height: auto;
        margin: 0;
        clip: auto;
    }
    .sidebar__soon {
        display: inline;
    }
}
</style>
