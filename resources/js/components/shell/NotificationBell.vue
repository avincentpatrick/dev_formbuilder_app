<script setup lang="ts">
/**
 * Notification bell (Feature #13). Shell chrome is real; the notification feed backend doesn't exist
 * yet (Phase 1), so the center is a styled placeholder empty state. Popover a11y via useDismissable.
 */
import { ref } from 'vue';
import { MdsIcon, MdsEmptyState } from '@meridian/design-system';
import { useDismissable } from '@/composables/useDismissable';

const open = ref(false);
const rootEl = ref<HTMLElement | null>(null);
const triggerEl = ref<HTMLButtonElement | null>(null);
useDismissable({ isOpen: open, rootEl, triggerEl });
</script>

<template>
    <div ref="rootEl" class="bell">
        <button
            ref="triggerEl"
            type="button"
            class="bell__trigger"
            :aria-expanded="open"
            aria-haspopup="dialog"
            aria-label="Notifications"
            @click="open = !open"
        >
            <MdsIcon name="bell" size="md" aria-hidden="true" />
        </button>

        <div v-if="open" class="bell__popover" role="dialog" aria-label="Notifications">
            <MdsEmptyState
                headline="You’re all caught up"
                description="Updates about your forms and submissions will appear here."
            />
        </div>
    </div>
</template>

<style scoped>
.bell {
    position: relative;
}

.bell__trigger {
    display: inline-flex;
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

.bell__trigger:hover {
    background-color: var(--mds-color-bg-sunken);
}

.bell__trigger:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.bell__popover {
    position: absolute;
    top: calc(100% + var(--mds-space-1));
    right: 0;
    z-index: 30;
    width: 300px;
    max-width: calc(100vw - var(--mds-space-6));
    background-color: var(--mds-color-bg-surface-raised);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-3);
}
</style>
