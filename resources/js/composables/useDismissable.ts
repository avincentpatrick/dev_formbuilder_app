import { onBeforeUnmount, onMounted, type Ref } from 'vue';

/**
 * Shared popover/menu dismissal behaviour: Escape closes (and restores focus to the trigger),
 * a pointer-down outside closes (without stealing focus). Used by AccountMenu / NotificationBell /
 * FeedbackButton so every shell popover behaves identically.
 */
export function useDismissable(opts: {
    isOpen: Ref<boolean>;
    rootEl: Ref<HTMLElement | null>;
    triggerEl: Ref<HTMLElement | null>;
}) {
    function close(returnFocus = false): void {
        if (!opts.isOpen.value) return;
        opts.isOpen.value = false;
        if (returnFocus) opts.triggerEl.value?.focus();
    }

    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape' && opts.isOpen.value) {
            event.stopPropagation();
            close(true);
        }
    }

    function onPointerDown(event: Event): void {
        if (!opts.isOpen.value) return;
        const target = event.target as Node | null;
        if (target && opts.rootEl.value?.contains(target)) return;
        if (target && opts.triggerEl.value?.contains(target)) return; // the trigger toggles itself
        close(false);
    }

    onMounted(() => {
        document.addEventListener('keydown', onKeydown);
        document.addEventListener('pointerdown', onPointerDown, true);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('keydown', onKeydown);
        document.removeEventListener('pointerdown', onPointerDown, true);
    });

    return { close };
}
