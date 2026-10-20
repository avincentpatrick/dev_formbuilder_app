<script setup lang="ts">
/**
 * The authenticated app shell (DSR §3.0). Wraps every tenant page (assigned as a persistent Inertia
 * layout in app.ts) with the top nav + sidebar; the page fills only the content region. Owns shell
 * state (mobile drawer open, top-nav scroll shadow) which persists across Inertia visits because the
 * layout instance persists.
 */
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { MdsToastHost } from '@meridian/design-system';
import CommandPalette from '@/components/shell/CommandPalette.vue';
import TopNav from '@/components/shell/TopNav.vue';
import Sidebar from '@/components/shell/Sidebar.vue';
import ImpersonationBanner from '@/components/shell/ImpersonationBanner.vue';
import WhatsNewModal from '@/components/whats-new/WhatsNewModal.vue';
import { versionLabel } from '@/components/whats-new/version-label';
import { useToast } from '@/composables/useToast';

const drawerOpen = ref(false);
const scrolled = ref(false);

const page = usePage();
const { toasts, push, dismiss } = useToast();

// Full-bleed pages (the form builder) manage their own internal layout + scroll and fill the whole
// content region edge-to-edge — no shell padding. Detected off the current page component so the SINGLE
// persistent AppLayout instance is kept (no remount/flash on entering the builder); the workspace itself
// owns overflow, so the shell's scroll region is disabled for it.
//
// Every other page also fills the region (M173, `D120`): the shell caps no page's width. JR4 capped them at
// 1200px (1600px for a list of ten) so a row's title and its date would not sit at opposite edges of a wide
// screen; that job now belongs to each page's own layout — card grids that gain columns, a reading measure
// for inputs and prose — never to a centred column with empty space either side.
const FLUID_PAGES = new Set<string>(['forms/Builder']);
const fluid = computed(() => FLUID_PAGES.has(page.component));

// M181 (`R-c7247dbf`, `D124`) — the release, under every page but the builder's (see the footer below).
const version = computed(() => versionLabel(page.props.app?.version));

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

// ⚠️ THE DRAWER IS CLOSED ON EVERY NAVIGATION, AND THE `close` EMIT IS NOT ENOUGH ON ITS OWN. This is a
// PERSISTENT layout, so `drawerOpen` survives Inertia visits. Sidebar emits `close` when one of its own
// links is clicked, but a command-palette jump, the compact search link, the account menu and the browser's
// Back button all navigate without one — leaving the drawer open over the new page and, since J4b, leaving
// that page's content inert behind a scrim nobody asked for.
watch(() => page.url, () => {
    drawerOpen.value = false;
});
</script>

<template>
    <div class="app-shell">
        <!-- I11b — ABOVE the nav, not inside the content region. A support session is a property of the
             whole window, not of the page being viewed, and a fluid page (the builder) owns its own scroll
             and would carry the banner out of view on the one screen where forgetting matters most. Renders
             nothing at all when nobody is impersonating, which is every ordinary request. -->
        <ImpersonationBanner />
        <TopNav :scrolled="scrolled" :drawer-open="drawerOpen" @toggle-drawer="drawerOpen = !drawerOpen" />
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
                <!-- M181 (`D124`): the product and its version under every page, with the way to What's new and Help.
                     Inside the scroll region, after the page, so it sits at the foot of a short page and after a long
                     one. Not on a fluid page: the builder owns the whole region and its scroll, and its version is in
                     the sidebar's foot. Inside <main>, so it is no `contentinfo` landmark of its own. -->
                <footer v-if="true" class="app-shell__footer">
                    <span>Meridian {{ version }}</span>
                    <span aria-hidden="true">·</span>
                    <Link href="/whats-new" class="app-shell__footer-link">What's new</Link>
                    <span aria-hidden="true">·</span>
                    <Link href="/help" class="app-shell__footer-link">Help</Link>
                </footer>
            </main>
        </div>
        <MdsToastHost :toasts="toasts" @dismiss="dismiss" />
        <!-- M181 (`D125`, `D126`): the What's new prompt — here for the reason the palette below is, the one persistent
             instance, so it opens once on the first page loaded while there is something new, and never per page. -->
        <WhatsNewModal />
        <!--
            Mounted HERE, beside the toast host, because this is the SINGLE persistent AppLayout instance:
            the palette's open state is module-scoped, so mounting it per-page would bind the ⌘K chord once
            per navigation. Deliberately NOT in AdminLayout — the central console has no tenant to search.

            The openers are the nav's two search affordances, in preference order — and it is a LIST
            because only one of them exists at a time: below 480px the field is `display: none` and the
            compact icon link takes over. Passing just the field stranded focus at 375px (CI caught it),
            since `.focus()` on a hidden element is a silent no-op and MdsModal then captures <body>.
        -->
        <CommandPalette :opener-selectors="['#topnav-search', '.topnav__search-compact']" />
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

/* M173 (`D120`) — no cap: the column is the whole region less its padding, at every width. A page lays its
   own width out. ⚠️ No project in the Playwright matrix can see a width (its widest is 1440, where JR4's
   1200px cap never bound either), so `tests/e2e/list-layout.spec.ts` measures this at 1920 and 2560 and
   `AppLayout.test.ts` pins the rule as text. */
.app-shell__inner {
    padding: var(--mds-space-8);
}

/* M181: a column, so the page takes the region's height at least and the footer sits at the foot of a short page.
   Never on a fluid page: the builder's `height: 100%` layout is left exactly as it was. */
.app-shell__content:not(.app-shell__content--fluid) {
    display: flex;
    flex-direction: column;
}

.app-shell__inner:not(.app-shell__inner--fluid) {
    flex: 1 0 auto;
}

/* M181 (`D124`) — the slim footer under every page: the version, What's new and Help, on the page's own padding. */
.app-shell__footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    padding: 0 var(--mds-space-8) var(--mds-space-6);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.app-shell__footer-link {
    color: var(--mds-color-action-primary-fg);
}

.app-shell__footer-link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

/* Edge-to-edge, full-height — the builder owns its own layout and scroll. */
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
    .app-shell__footer {
        padding-inline: var(--mds-space-4);
    }
}

/* One more step of side padding on a wide screen, so a page's content never meets the edge of the glass. */
@media (min-width: 1920px) {
    .app-shell__inner:not(.app-shell__inner--fluid) {
        padding-inline: var(--mds-space-12);
    }
    .app-shell__footer {
        padding-inline: var(--mds-space-12);
    }
}
</style>
