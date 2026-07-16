import { describe, expect, it, vi } from 'vitest';
import { registerServiceWorker } from '../lib/register-sw';

/** A fake window that captures the deferred `load` handler so the test can fire it deterministically. */
function fakeWindow(): { win: Window; fireLoad: () => void } {
    let loadHandler: (() => void) | null = null;
    const win = {
        addEventListener: (type: string, cb: () => void) => {
            if (type === 'load') {
                loadHandler = cb;
            }
        },
    } as unknown as Window;
    return { win, fireLoad: () => loadHandler?.() };
}

describe('registerServiceWorker', () => {
    it('registers /sw.js scoped to /f/ — deferred until window load', () => {
        const register = vi.fn().mockResolvedValue({});
        const nav = { serviceWorker: { register } } as unknown as Navigator;
        const { win, fireLoad } = fakeWindow();

        registerServiceWorker(nav, win);
        expect(register).not.toHaveBeenCalled(); // nothing until load

        fireLoad();
        expect(register).toHaveBeenCalledWith('/sw.js', { scope: '/f/' });
    });

    it('does nothing when the Service Worker API is unavailable', () => {
        const nav = {} as unknown as Navigator; // no serviceWorker
        const { win, fireLoad } = fakeWindow();

        expect(() => {
            registerServiceWorker(nav, win);
            fireLoad();
        }).not.toThrow();
    });

    it('swallows a registration rejection (best-effort — the form still works)', async () => {
        const register = vi.fn().mockRejectedValue(new Error('/sw.js 404 (not built)'));
        const nav = { serviceWorker: { register } } as unknown as Navigator;
        const { win, fireLoad } = fakeWindow();

        registerServiceWorker(nav, win);
        fireLoad();
        await Promise.resolve();

        expect(register).toHaveBeenCalledOnce();
    });
});
