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

// JR4 — wide pages cap at 1600 rather than 1200, detected the same way and for the same reason (one
// persistent instance, no props, no page-side change). THE RULE, so the next page is easy to classify:
// a page is wide when its main content is an unbounded collection of PEER ITEMS THAT GAIN A COLUMN —
// a data table, a card grid. Everything else keeps 1200, where the cap is doing its real job of holding
// a readable measure.
//
// ⚠️ OPT-IN RATHER THAN OPT-OUT, DELIBERATELY, BECAUSE OF WHAT FORGETTING COSTS. A list page missing
// from this set renders exactly as it does today; a settings form missing from an exclusion list would
// render 1600px of input fields. The failure mode of forgetting has to be "no change".
//
// The exclusions are measured, not left over: `domains/Index` (:217) and `search/Index` (:211) are
// `flex-direction: column` stacks of FULL-WIDTH cards, so widening them produces the very defect this
// increment exists to remove — a title at one edge of the glass and its date at the other. `scopes` is
// a tree beside a detail pane, `Settings` and `submissions/Encode` are forms whose 640px cards would be
// stranded at the left of a 1600px column, and every detail page is about one thing rather than many.
//
// `submissions/Inbox` is ONE component name for TWO routes (`/submissions` and the per-form Responses
// tab, both rendered by SubmissionInboxController). Both get the wide column; both are a list.
const WIDE_PAGES = new Set<string>([
    'Dashboard',
    'analytics/Index',
    'audit/Index',
    'feedback/Index',
    'forms/Index',
    'forms/Templates',
    'integrations/Index',
    'members/Index',
    'submissions/Inbox',
    'webhooks/Index',
]);
// `!fluid` first: a page in both sets must keep its full-bleed layout rather than acquire a cap. The CSS
// is also ordered so `--fluid` wins on specificity ties — belt and braces, because these two modifiers
// are the only place in the app where two layout classes can meet on one element.
const wide = computed(() => !fluid.value && WIDE_PAGES.has(page.component));

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
                <div
                    class="app-shell__inner"
                    :class="{ 'app-shell__inner--wide': wide, 'app-shell__inner--fluid': fluid }"
                >
                    <slot />
                </div>
            </main>
        </div>
        <MdsToastHost :toasts="toasts" @dismiss="dismiss" />
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

.app-shell__inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--mds-space-8);
}

/* JR4 — ⚠️ 1600, AND THE INTERESTING NUMBER IS THE ONE THIS IS NOT. `max-width: none` is what the
   complaint appears to ask for and it is wrong: unbounded, a row on a 32" panel puts a form's title at
   one edge and its date at the other and the eye cannot carry the association across two feet of glass.
   Same reasoning as the forms grid's `auto-fill` rather than `auto-fit` (`forms/Index.vue:688`).

   THE GUTTER IS ARITHMETIC, NOT TASTE. The content region is `viewport − 240` (the sidebar) and the
   column is capped, so the dead space EACH SIDE is:
       cap 1200 → 0 @1440 ·  80 @1600 · 240 @1920 · 560 @2560
       cap 1600 → 0 @1440 ·   0 @1600 ·  40 @1920 · 200 @2560
   A wide monitor is filled; a very wide one still reads as a page rather than a spreadsheet.

   ⚠️ AND NO GATE IN THIS REPO CAN SEE THIS CHANGE — Playwright's widest project is 1440×900, where the
   gutter is exactly 0 both before and after. That is why it survived every increment, and why
   `tests/e2e/list-layout.spec.ts` sets its own 1600px viewport rather than trusting the matrix.

   Declared BEFORE `--fluid`: both are single-class specificity, so source order is the only thing that
   makes `max-width: none` win if a page ever appears in both sets. */
.app-shell__inner--wide {
    max-width: 1600px;
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
