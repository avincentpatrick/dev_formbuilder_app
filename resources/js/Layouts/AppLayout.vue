<script setup lang="ts">
/**
 * The authenticated app shell (DSR §3.0). Wraps every tenant page (assigned as a persistent Inertia
 * layout in app.ts) with the top nav + sidebar; the page fills only the content region. Owns shell
 * state (mobile drawer open, top-nav scroll shadow) which persists across Inertia visits because the
 * layout instance persists.
 */
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { MdsToastHost } from '@meridian/design-system';
import TopNav from '@/components/shell/TopNav.vue';
import Sidebar from '@/components/shell/Sidebar.vue';
import { useToast } from '@/composables/useToast';

const drawerOpen = ref(false);
const scrolled = ref(false);

const page = usePage();
const { toasts, push, dismiss } = useToast();

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
        <TopNav :scrolled="scrolled" @toggle-drawer="drawerOpen = !drawerOpen" />
        <div class="app-shell__body">
            <Sidebar :drawer-open="drawerOpen" @close="drawerOpen = false" />
            <!-- tabindex=0 so the scroll region is keyboard-operable when content overflows
                 (WCAG 2.1.1 / axe scrollable-region-focusable) — pages with only short/disabled
                 content would otherwise leave the scrolled area unreachable by keyboard. -->
            <main class="app-shell__content" tabindex="0" aria-label="Main content" @scroll="onScroll">
                <div class="app-shell__inner">
                    <slot />
                </div>
            </main>
        </div>
        <MdsToastHost :toasts="toasts" @dismiss="dismiss" />
    </div>
</template>

<style scoped>
.app-shell {
    display: flex;
    flex-direction: column;
    height: 100vh;
    height: 100dvh;
    background-color: var(--mds-color-bg-canvas);
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

.app-shell__inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--mds-space-8);
}

@media (max-width: 480px) {
    .app-shell__inner {
        padding: var(--mds-space-4);
    }
}
</style>
