<script setup lang="ts">
/**
 * The shared modal / dialog (DSR section 3.6). Every blocking overlay in the app is this component --
 * no page builds a "modal-like" floating panel that skips the focus trap. Ships the full contract:
 *   - a top-right close affordance (icon button with aria-label), also closable via Escape;
 *   - a focus trap (Tab cycles within the panel) with initial focus into the panel and
 *     return-focus to the opener on close;
 *   - role="dialog" + aria-modal + aria-labelledby (the title);
 *   - renders in a top-level portal above the scrim (shadow-4), and becomes a full-screen
 *     sheet below the tablet breakpoint (section 6).
 * The package is isolated (no app dependency), so the trap/escape/scroll-lock are self-contained here.
 *
 * Actions go in the #actions slot (one primary button, bottom-right -- the one-primary rule, 3.1).
 */
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import Icon from '../Icon/Icon.vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        closeLabel?: string;
        // Teleport the overlay to <body> (default). Set false to render in place -- used by the axe
        // stories so the scanner (scoped to #storybook-root) can see the dialog.
        teleport?: boolean;
    }>(),
    { closeLabel: 'Close', teleport: true },
);

const emit = defineEmits<{ close: []; 'update:open': [value: boolean] }>();

const titleId = useId();
const panel = ref<HTMLElement | null>(null);
let opener: HTMLElement | null = null;

const FOCUSABLE =
    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusable(): HTMLElement[] {
    if (!panel.value) return [];
    return Array.from(panel.value.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
        (el) => el.offsetParent !== null || el === document.activeElement,
    );
}

function requestClose() {
    emit('update:open', false);
    emit('close');
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        requestClose();
        return;
    }
    if (event.key !== 'Tab') return;

    const items = focusable();
    if (items.length === 0) {
        event.preventDefault();
        panel.value?.focus();
        return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement as HTMLElement | null;

    if (event.shiftKey && (active === first || !panel.value?.contains(active))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

function onBackdrop(event: MouseEvent) {
    if (event.target === event.currentTarget) requestClose();
}

watch(
    () => props.open,
    (isOpen, was) => {
        if (isOpen && !was) {
            opener = document.activeElement as HTMLElement | null;
            document.body.style.overflow = 'hidden';
            nextTick(() => {
                const items = focusable();
                (items[0] ?? panel.value)?.focus();
            });
        } else if (!isOpen && was) {
            document.body.style.overflow = '';
            opener?.focus?.();
            opener = null;
        }
    },
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body" :disabled="!teleport">
        <Transition name="mds-modal">
            <div v-if="open" class="mds-modal__backdrop" @mousedown="onBackdrop">
                <div
                    ref="panel"
                    class="mds-modal__panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    tabindex="-1"
                    @keydown="onKeydown"
                >
                    <header class="mds-modal__header">
                        <h2 :id="titleId" class="mds-modal__title">{{ title }}</h2>
                        <button
                            type="button"
                            class="mds-modal__close"
                            :aria-label="closeLabel"
                            @click="requestClose"
                        >
                            <Icon name="close" size="md" />
                        </button>
                    </header>

                    <div class="mds-modal__body"><slot /></div>

                    <footer v-if="$slots.actions" class="mds-modal__footer">
                        <slot name="actions" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.mds-modal__backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-4);
    background-color: var(--mds-color-overlay-scrim);
}

.mds-modal__panel {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 520px;
    max-height: calc(100dvh - var(--mds-space-8));
    background-color: var(--mds-color-bg-surface-raised);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-4);
    color: var(--mds-color-text-body);
}

.mds-modal__panel:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-modal__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-4);
    padding: var(--mds-space-5) var(--mds-space-6);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.mds-modal__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.mds-modal__close {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-md);
    background: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}
.mds-modal__close::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    min-width: 44px;
    min-height: 44px;
    width: 100%;
    height: 100%;
    transform: translate(-50%, -50%);
}
.mds-modal__close:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}
.mds-modal__close:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-modal__body {
    padding: var(--mds-space-6);
    overflow-y: auto;
}

.mds-modal__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--mds-space-3);
    padding: var(--mds-space-4) var(--mds-space-6);
    border-top: 1px solid var(--mds-color-border-default);
}

@media (max-width: 480px) {
    .mds-modal__backdrop {
        padding: 0;
        align-items: stretch;
    }
    .mds-modal__panel {
        max-width: none;
        max-height: none;
        min-height: 100dvh;
        border: 0;
        border-radius: 0;
    }
}

.mds-modal-enter-active {
    transition: opacity var(--mds-duration-slow) var(--mds-ease-decelerate);
}
.mds-modal-leave-active {
    transition: opacity var(--mds-duration-moderate) var(--mds-ease-accelerate);
}
.mds-modal-enter-from,
.mds-modal-leave-to {
    opacity: 0;
}
.mds-modal-enter-active .mds-modal__panel {
    transition: transform var(--mds-duration-slow) var(--mds-ease-decelerate);
}
.mds-modal-enter-from .mds-modal__panel {
    transform: translateY(8px);
}
</style>
