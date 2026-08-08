<script setup lang="ts">
/**
 * Toast portal (DSR §3.7 / §2.5). Renders the toast stack in a dedicated top-level portal — OUTSIDE
 * any modal's DOM subtree — so a toast (`--mds-shadow-5`) is never trapped beneath an open modal
 * (`--mds-shadow-4`). Stacked top-right on desktop, full-width top-anchored on mobile (§6). The
 * container is always present, so it is a stable live-region host for the toasts inserted into it.
 * The consuming app owns the toast list + timers; this just presents them.
 *
 * `data-mds-inert-exempt` is the DOM half of that same promise (Increment I10a). Because this host is a
 * body-level sibling of an open modal's backdrop, the modal's inert walk would otherwise mark it — and
 * `inert` removes a subtree from the accessibility tree, so every toast raised while a dialog is open
 * would be announced to nobody and clickable by nobody. Being outside the modal is the point; being
 * unreachable is not.
 */
import Toast from './Toast.vue';
import type { ToastItem } from './toast';

withDefaults(defineProps<{ toasts: ToastItem[]; teleport?: boolean }>(), { teleport: true });

defineEmits<{ dismiss: [id: ToastItem['id']] }>();
</script>

<template>
    <Teleport to="body" :disabled="!teleport">
        <div class="mds-toast-host" data-mds-inert-exempt>
            <TransitionGroup name="mds-toast">
                <Toast
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="mds-toast-host__item"
                    :type="toast.type"
                    :message="toast.message"
                    @dismiss="$emit('dismiss', toast.id)"
                />
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<style scoped>
.mds-toast-host {
    position: fixed;
    top: var(--mds-space-4);
    right: var(--mds-space-4);
    z-index: 1100;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    width: min(360px, calc(100vw - var(--mds-space-8)));
    pointer-events: none;
}

.mds-toast-host__item {
    pointer-events: auto;
}

@media (max-width: 480px) {
    .mds-toast-host {
        top: 0;
        left: 0;
        right: 0;
        width: auto;
        padding: var(--mds-space-3);
    }
}

.mds-toast-enter-active {
    transition:
        opacity var(--mds-duration-moderate) var(--mds-ease-decelerate),
        transform var(--mds-duration-moderate) var(--mds-ease-decelerate);
}
.mds-toast-leave-active {
    transition:
        opacity var(--mds-duration-base) var(--mds-ease-accelerate),
        transform var(--mds-duration-base) var(--mds-ease-accelerate);
}
.mds-toast-enter-from,
.mds-toast-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>
