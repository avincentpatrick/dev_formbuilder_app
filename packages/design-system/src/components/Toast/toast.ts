// Shared toast types (DSR §3.7). The toast STORE lives in the consuming app (it bridges server flash
// messages + client-initiated pushes); these components are presentational and driven by that store.

export type ToastType = 'success' | 'error' | 'info';

export interface ToastItem {
    id: number | string;
    type: ToastType;
    message: string;
}
