import { describe, expect, it } from 'vitest';
import { useOnline } from '../composables/useOnline';

/** A fake navigator + event target whose onLine value and online/offline events the test drives. */
function fakeEnv(initialOnline: boolean) {
    const listeners: Record<string, Array<() => void>> = { online: [], offline: [] };
    let online = initialOnline;

    const nav = {
        get onLine(): boolean {
            return online;
        },
    } as unknown as Navigator;

    const target = {
        addEventListener: (type: string, cb: () => void) => {
            (listeners[type] ??= []).push(cb);
        },
        removeEventListener: (type: string, cb: () => void) => {
            listeners[type] = (listeners[type] ?? []).filter((fn) => fn !== cb);
        },
    } as unknown as Window;

    return {
        nav,
        target,
        listeners,
        setOnline: (value: boolean) => {
            online = value;
        },
        fire: (type: 'online' | 'offline') => (listeners[type] ?? []).forEach((fn) => fn()),
    };
}

describe('useOnline', () => {
    it('reflects the initial navigator.onLine value', () => {
        const env = fakeEnv(false);
        const { online, dispose } = useOnline(env.nav, env.target);

        expect(online.value).toBe(false);
        dispose();
    });

    it('updates reactively on online/offline events', () => {
        const env = fakeEnv(true);
        const { online, dispose } = useOnline(env.nav, env.target);

        expect(online.value).toBe(true);

        env.setOnline(false);
        env.fire('offline');
        expect(online.value).toBe(false);

        env.setOnline(true);
        env.fire('online');
        expect(online.value).toBe(true);

        dispose();
    });

    it('removes both listeners on dispose', () => {
        const env = fakeEnv(true);
        const { dispose } = useOnline(env.nav, env.target);

        expect(env.listeners.online.length + env.listeners.offline.length).toBe(2);
        dispose();
        expect(env.listeners.online.length + env.listeners.offline.length).toBe(0);
    });
});
