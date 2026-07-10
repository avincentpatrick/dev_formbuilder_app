import { reactive, ref } from 'vue';
import { describe, expect, it } from 'vitest';
import { createAutosave, draftKey } from '../composables/useAutosave';

function memStorage(): Storage {
    const map = new Map<string, string>();
    return {
        get length(): number {
            return map.size;
        },
        clear: () => map.clear(),
        getItem: (key: string) => (map.has(key) ? (map.get(key) as string) : null),
        key: (index: number) => Array.from(map.keys())[index] ?? null,
        removeItem: (key: string) => {
            map.delete(key);
        },
        setItem: (key: string, value: string) => {
            map.set(key, value);
        },
    } as unknown as Storage;
}

const T0 = 1_700_000_000_000;

describe('createAutosave', () => {
    it('persists and restores answers + locale + step (round trip)', () => {
        const storage = memStorage();
        const answers = reactive<Record<string, string>>({ name: 'Ada' });
        const autosave = createAutosave({
            formId: 'f',
            slug: 's',
            checksum: 'c1',
            answers,
            locale: ref('es'),
            currentStepKey: ref('s2'),
            storage,
            now: () => T0,
        });
        autosave.flush();

        const restored = autosave.restore();
        expect(restored).toMatchObject({ checksum: 'c1', locale: 'es', currentStepKey: 's2', answers: { name: 'Ada' } });
        autosave.dispose();
    });

    it('discards a draft written under a different checksum (version drift)', () => {
        const storage = memStorage();
        storage.setItem(
            draftKey('f', 's'),
            JSON.stringify({ checksum: 'old', locale: 'en', currentStepKey: 'x', answers: {}, savedAt: new Date(T0).toISOString() }),
        );
        const autosave = createAutosave({
            formId: 'f',
            slug: 's',
            checksum: 'new',
            answers: reactive({}),
            locale: ref('en'),
            currentStepKey: ref('x'),
            storage,
            now: () => T0,
        });
        expect(autosave.restore()).toBeNull();
        expect(storage.getItem(draftKey('f', 's'))).toBeNull(); // cleared
        autosave.dispose();
    });

    it('discards a draft older than the 7-day inactivity window', () => {
        const storage = memStorage();
        storage.setItem(
            draftKey('f', 's'),
            JSON.stringify({ checksum: 'c1', locale: 'en', currentStepKey: 'x', answers: {}, savedAt: new Date(T0).toISOString() }),
        );
        const eightDaysLater = T0 + 8 * 24 * 60 * 60 * 1000;
        const autosave = createAutosave({
            formId: 'f',
            slug: 's',
            checksum: 'c1',
            answers: reactive({}),
            locale: ref('en'),
            currentStepKey: ref('x'),
            storage,
            now: () => eightDaysLater,
        });
        expect(autosave.restore()).toBeNull();
        autosave.dispose();
    });

    it('clear() removes the stored draft (called after a successful submit)', () => {
        const storage = memStorage();
        const autosave = createAutosave({
            formId: 'f',
            slug: 's',
            checksum: 'c1',
            answers: reactive({ a: '1' }),
            locale: ref('en'),
            currentStepKey: ref('x'),
            storage,
            now: () => T0,
        });
        autosave.flush();
        expect(storage.getItem(draftKey('f', 's'))).not.toBeNull();
        autosave.clear();
        expect(storage.getItem(draftKey('f', 's'))).toBeNull();
        autosave.dispose();
    });
});
