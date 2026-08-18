<script setup lang="ts">
/**
 * Page chrome for the fill flow: a centered content column with the form header, a focusable main region
 * (the focus-rescue fallback target, §10.2), an optional session-notice slot, the flow itself, and the single
 * visually-hidden aria-live region the whole SPA announces through (§10.1).
 */
import FormHeader from './FormHeader.vue';
import { useAnnouncer } from '../composables/context';

defineProps<{ title: string; description: string | null; saving: boolean; savedAt: string | null }>();

const announcer = useAnnouncer();
</script>

<template>
    <div class="runtime">
        <div class="runtime__column">
            <FormHeader :title="title" :description="description" :saving="saving" :saved-at="savedAt" />
            <main class="runtime__main" data-runtime-main tabindex="-1">
                <slot name="notice" />
                <slot />
            </main>
        </div>
        <div class="runtime__sr-live" role="status" aria-live="polite">{{ announcer.message.value }}</div>
    </div>
</template>

<style scoped>
.runtime {
    /* I10d — `flex: 1`, not `min-height: 100vh`. The sync surface now sits ABOVE this in App.vue's
       flex column, so claiming the whole viewport here would make the document taller than the screen and
       put a scrollbar on every page. `assertClean` checks HORIZONTAL overflow only, so this would have
       shipped as a visible regression with a green gate. */
    flex: 1;
    padding: var(--mds-space-6) var(--mds-space-4) var(--mds-space-10);
    background-color: var(--mds-color-bg-canvas);
}

.runtime__column {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
    width: 100%;
    max-width: 44rem;
    margin: 0 auto;
}

.runtime__main {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
}

.runtime__main:focus {
    outline: none;
}

.runtime__sr-live {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
