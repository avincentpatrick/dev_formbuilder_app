/**
 * The collapse preference's storage (M128, `R-33c7fd56`). The first stored client preference in
 * `resources/js`, so the cases are the storage failures, not the happy path alone: blocked storage must
 * leave the sidebar working, never throw.
 */

import { describe, expect, it } from 'vitest';

import { SIDEBAR_COLLAPSED_KEY, useSidebarCollapse } from '../useSidebarCollapse';

function memoryStorage(): Storage {
    const data = new Map<string, string>();

    return {
        get length() {
            return data.size;
        },
        clear: () => data.clear(),
        getItem: (key: string) => data.get(key) ?? null,
        key: (index: number) => [...data.keys()][index] ?? null,
        removeItem: (key: string) => void data.delete(key),
        setItem: (key: string, value: string) => void data.set(key, value),
    };
}

/** Storage that refuses every call, as a blocked or partitioned origin does. */
function hostileStorage(): Storage {
    const refuse = (): never => {
        throw new DOMException('denied', 'SecurityError');
    };

    return {
        get length(): number {
            return refuse();
        },
        clear: refuse,
        getItem: refuse,
        key: refuse,
        removeItem: refuse,
        setItem: refuse,
    };
}

describe('useSidebarCollapse', () => {
    it('starts expanded with nothing stored, and writes only the collapsed state', () => {
        const storage = memoryStorage();
        const { collapsed, toggle } = useSidebarCollapse(storage);

        expect(collapsed.value).toBe(false);

        toggle();
        expect(collapsed.value).toBe(true);
        expect(storage.getItem(SIDEBAR_COLLAPSED_KEY)).toBe('1');

        toggle();
        expect(collapsed.value).toBe(false);
        expect(storage.getItem(SIDEBAR_COLLAPSED_KEY)).toBeNull();
    });

    it('reads a stored collapse, and nothing else, as collapsed', () => {
        const storage = memoryStorage();
        storage.setItem(SIDEBAR_COLLAPSED_KEY, '1');
        expect(useSidebarCollapse(storage).collapsed.value).toBe(true);

        storage.setItem(SIDEBAR_COLLAPSED_KEY, 'yes');
        expect(useSidebarCollapse(storage).collapsed.value).toBe(false);
    });

    it('works for the page view when storage refuses everything, and never throws', () => {
        const { collapsed, toggle } = useSidebarCollapse(hostileStorage());

        expect(collapsed.value).toBe(false);
        expect(() => toggle()).not.toThrow();
        expect(collapsed.value).toBe(true);
    });

    it('works with no storage at all', () => {
        const { collapsed, toggle } = useSidebarCollapse(null);

        toggle();
        expect(collapsed.value).toBe(true);
    });
});
