<script setup lang="ts">
/**
 * The authenticated app shell (DSR §3.0). Wraps every tenant page (assigned as a persistent Inertia
 * layout in app.ts) with the top nav + sidebar; the page fills only the content region. Owns shell
 * state (mobile drawer open, top-nav scroll shadow) which persists across Inertia visits because the
 * layout instance persists.
 */
import { ref } from 'vue';
import TopNav from '@/components/shell/TopNav.vue';
import Sidebar from '@/components/shell/Sidebar.vue';

const drawerOpen = ref(false);
const scrolled = ref(false);

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
            <main class="app-shell__content" @scroll="onScroll">
                <div class="app-shell__inner">
                    <slot />
                </div>
            </main>
        </div>
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
