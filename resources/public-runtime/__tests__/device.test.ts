import { describe, expect, it } from 'vitest';
import { DEVICE_ID_KEY, getDeviceId } from '../lib/device';

function memStorage(seed: Record<string, string> = {}): Storage {
    const map = new Map<string, string>(Object.entries(seed));
    return {
        get length() {
            return map.size;
        },
        clear: () => map.clear(),
        getItem: (k: string) => (map.has(k) ? (map.get(k) as string) : null),
        key: (i: number) => Array.from(map.keys())[i] ?? null,
        removeItem: (k: string) => void map.delete(k),
        setItem: (k: string, v: string) => void map.set(k, v),
    } as unknown as Storage;
}

const UUID_RE = /^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/;

describe('getDeviceId', () => {
    it('mints a UUIDv7 device id and persists it', () => {
        const storage = memStorage();
        const id = getDeviceId(storage);
        expect(id).toMatch(UUID_RE);
        expect(storage.getItem(DEVICE_ID_KEY)).toBe(id);
    });

    it('returns the same id on subsequent calls (stable per device)', () => {
        const storage = memStorage();
        expect(getDeviceId(storage)).toBe(getDeviceId(storage));
    });

    it('reuses an already-stored id', () => {
        const storage = memStorage({ [DEVICE_ID_KEY]: 'existing-id' });
        expect(getDeviceId(storage)).toBe('existing-id');
    });
});
