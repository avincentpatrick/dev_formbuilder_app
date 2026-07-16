/**
 * Reactive online/offline status (Increment G8a) from `navigator.onLine` plus the window `online`/`offline`
 * events. Drives the ambient offline pill and the submit pre-flight guard (a POST while offline is a doomed
 * request — G8a has no outbox yet; queued replay is G8b).
 *
 * Auto-disposes when the owning component's effect scope tears down; also returns an explicit `dispose()` for
 * tests. `navigator`/`window` are injectable so the listeners can be exercised without a real browser.
 */
import { onScopeDispose, ref, type Ref } from 'vue';

export interface OnlineStatus {
    online: Ref<boolean>;
    dispose(): void;
}

export function useOnline(nav: Navigator = navigator, target: Window = window): OnlineStatus {
    const online = ref(nav.onLine);

    const sync = (): void => {
        online.value = nav.onLine;
    };

    target.addEventListener('online', sync);
    target.addEventListener('offline', sync);

    function dispose(): void {
        target.removeEventListener('online', sync);
        target.removeEventListener('offline', sync);
    }

    // Silent when there's no active scope (e.g. a unit test drives it directly).
    onScopeDispose(dispose, true);

    return { online, dispose };
}
