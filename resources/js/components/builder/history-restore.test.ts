/**
 * M172 (`R-81756e95`) — a builder brought back by Back or Forward knows it, so it re-visits the server instead of showing
 * the props of its first visit (measured on the trunk: the builder came back with none of the questions added since).
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

const handlers = vi.hoisted(() => ({ start: [] as Array<() => void> }));

vi.mock('@inertiajs/vue3', () => ({
    router: { on: (event: string, handler: () => void) => (event === 'start' ? handlers.start.push(handler) : undefined) },
}));

const { takeHistoryRestore } = await import('./history-restore');

afterEach(() => {
    window.history.replaceState(null, '', '/');
});

describe('takeHistoryRestore', () => {
    it('is true once for the page Back restored, and false for an ordinary visit', () => {
        window.history.replaceState(null, '', '/forms/f1/builder');
        window.dispatchEvent(new PopStateEvent('popstate'));

        expect(takeHistoryRestore()).toBe(true);
        expect(takeHistoryRestore(), 'consumed').toBe(false);
    });

    it('forgets a Back when an ordinary visit starts, so the builder it reaches is not reloaded for nothing', () => {
        window.history.replaceState(null, '', '/forms/f1');
        window.dispatchEvent(new PopStateEvent('popstate'));
        handlers.start.forEach((handler) => handler());
        window.history.replaceState(null, '', '/forms/f1/builder');

        expect(takeHistoryRestore()).toBe(false);
    });

    it('does not count a Back that restored another page', () => {
        window.history.replaceState(null, '', '/forms/f1');
        window.dispatchEvent(new PopStateEvent('popstate'));
        window.history.replaceState(null, '', '/forms/f1/builder');

        expect(takeHistoryRestore()).toBe(false);
    });
});
