// App-side toast store (DSR §3.7). A module-level singleton list that MdsToastHost (rendered once in
// AppLayout) presents. Two producers push into it: server outcomes (the flash → toast bridge in
// AppLayout) and client-initiated calls (`useToast().push(...)`). Success/info auto-dismiss; error
// toasts persist until the user dismisses them (§3.7 / §4.6).
import { reactive } from 'vue';
import type { ToastItem, ToastType } from '@meridian/design-system';

const toasts = reactive<ToastItem[]>([]);
let counter = 0;
const AUTO_DISMISS_MS = 5000;

export function useToast() {
    function dismiss(id: ToastItem['id']): void {
        const index = toasts.findIndex((t) => t.id === id);
        if (index !== -1) toasts.splice(index, 1);
    }

    function push(type: ToastType, message: string): ToastItem['id'] {
        const id = ++counter;
        toasts.push({ id, type, message });
        if (type !== 'error') {
            window.setTimeout(() => dismiss(id), AUTO_DISMISS_MS);
        }
        return id;
    }

    return { toasts, push, dismiss };
}
