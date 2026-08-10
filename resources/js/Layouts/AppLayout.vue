<script setup lang="ts">
/**
 * The authenticated app shell (DSR §3.0). Wraps every tenant page (assigned as a persistent Inertia
 * layout in app.ts) with the top nav + sidebar; the page fills only the content region. Owns shell
 * state (mobile drawer open, top-nav scroll shadow) which persists across Inertia visits because the
 * layout instance persists.
 */
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { MdsToastHost } from '@meridian/design-system';
import CommandPalette from '@/components/shell/CommandPalette.vue';
import TopNav from '@/components/shell/TopNav.vue';
import Sidebar from '@/components/shell/Sidebar.vue';
import ImpersonationBanner from '@/components/shell/ImpersonationBanner.vue';
import { useToast } from '@/composables/useToast';

const drawerOpen = ref(false);
const scrolled = ref(false);

const page = usePage();
const { toasts, push, dismiss } = useToast();

// Full-bleed pages (the form builder) manage their own internal layout + scroll and fill the whole
// content region edge-to-edge — no centered max-width, no shell padding. Detected off the current page
// component so the SINGLE persistent AppLayout instance is kept (no remount/flash on entering the
// builder); the workspace itself owns overflow, so the shell's scroll region is disabled for it.
const FLUID_PAGES = new Set<string>(['forms/Builder']);
const fluid = computed(() => FLUID_PAGES.has(page.component));

// Server flash → toast bridge: any controller that redirects with ->with('toast', {...}) surfaces it
// here once. Fires on the visit that carries the flash (immediate covers a redirect-then-render).
watch(
    () => page.props.flash?.toast,
    (toast) => {
        if (toast) push(toast.type, toast.message);
    },
    { immediate: true },
);

function onScroll(event: Event): void {
    scrolled.value = (event.target as HTMLElement).scrollTop > 4;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && drawerOpen.value) {
        drawerOpen.value = false;
    }
}
</script>

<template>
    <div class="app-shell" @keydown="onKeydown">
        <!-- I11b — ABOVE the nav, not inside the content region. A support session is a property of the
             whole window, not of the page being viewed, and a fluid page (the builder) owns its own scroll
             and would carry the banner out of view on the one screen where forgetting matters most. Renders
             nothing at all when nobody is impersonating, which is every ordinary request. -->
        <ImpersonationBanner />
        <TopNav :scrolled="scrolled" @toggle-drawer="drawerOpen = !drawerOpen" />
        <div class="app-shell__body">
            <Sidebar :drawer-open="drawerOpen" @close="drawerOpen = false" />
            <!-- tabindex=0 so the scroll region is keyboard-operable when content overflows
                 (WCAG 2.1.1 / axe scrollable-region-focusable) — pages with only short/disabled
                 content would otherwise leave the scrolled area unreachable by keyboard. A fluid page
                 owns its own scroll, so the shell region is not itself scrollable (tabindex dropped). -->
            <main
                class="app-shell__content"
                :class="{ 'app-shell__content--fluid': fluid }"
                :tabindex="fluid ? undefined : 0"
                aria-label="Main content"
                @scroll="onScroll"
            >
                <div class="app-shell__inner" :class="{ 'app-shell__inner--fluid': fluid }">
                    <slot />
                </div>
            </main>
        </div>
        <MdsToastHost :toasts="toasts" @dismiss="dismiss" />
        <!--
            Mounted HERE, beside the toast host, because this is the SINGLE persistent AppLayout instance:
            the palette's open state is module-scoped, so mounting it per-page would bind the ⌘K chord once
            per navigation. Deliberately NOT in AdminLayout — the central console has no tenant to search.

            The opener selector is the nav search field, so a chord-opened palette returns focus somewhere
            real on close rather than to <body>, whose .focus() is a silent no-op.
        -->
        <CommandPalette opener-selector="#topnav-search" />
    </div>
</template>

<style scoped>
.app-shell {
    display: flex;
    flex-direction: column;
    height: 100vh;
    height: 100dvh;
    background-color: var(--mds-color-bg-canvas);
    /* G11 belt: the §2.9 text-size scale can grow text ~25%, and .app-shell is the only element
       between the top nav and the document — .app-shell__content below is its own scroll container,
       so page content never reaches the document's overflow chain, but the nav does. `clip` (not
       `hidden`) creates no scroll container and does not break position: sticky, so this guarantees
       no personalization combination can ever scroll the DOCUMENT horizontally. The E2E
       no-horizontal-overflow assertion then measures real layout regressions rather than flaking on
       one long tenant name at 375px. */
    overflow-x: clip;
}

.app-shell__body {
    display: flex;
    flex: 1;
    min-height: 0;
}

.app-shell__content {
    flex: 1;
    min-width: 0;
    overflow-y: auto;
    background-color: var(--mds-color-bg-canvas);
}

/* Fluid pages own their own scroll — the shell region must not double-scroll. */
.app-shell__content--fluid {
    overflow: hidden;
}

.app-shell__inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--mds-space-8);
}

/* Edge-to-edge, full-height, no centered column — the page fills the region. */
.app-shell__inner--fluid {
    max-width: none;
    height: 100%;
    margin: 0;
    padding: 0;
}

@media (max-width: 480px) {
    .app-shell__inner:not(.app-shell__inner--fluid) {
        padding: var(--mds-space-4);
    }
}
</style>
