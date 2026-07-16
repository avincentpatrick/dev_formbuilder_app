import { reactive, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { createAutosave, draftKey } from '../composables/useAutosave';
import { openDb } from '../lib/db';

function memStorage(): Storage {
    const map = new Map<string, string>();
    return {
        get length() {
            return map.size;
        },
        clear: () => map.clear(),
        getItem: (key: string) => (map.has(key) ? (map.get(key) as string) : null),
        key: (index: number) => Array.from(map.keys())[index] ?? null,
        removeItem: (key: string) => void map.delete(key),
        setItem: (key: string, value: string) => void map.set(key, value),
    } as unknown as Storage;
}

const T0 = 1_700_000_000_000;
let n = 0;
const freshDb = () => openDb(`autosave-test-${(n += 1)}`);

function make(over: Record<string, unknown> = {}) {
    return createAutosave({
        db: freshDb(),
        formId: 'f',
        formVersionId: 'v1',
        slug: 's',
        checksum: 'c1',
        answers: reactive<Record<string, string>>({}),
        locale: ref('en'),
        currentStepKey: ref('x'),
        storage: memStorage(),
        now: () => T0,
        ...over,
    });
}

describe('createAutosave (Dexie-backed)', () => {
    it('persists and restores answers + locale + step (round trip)', async () => {
        const db = freshDb();
        const autosave = createAutosave({
            db,
            formId: 'f',
            formVersionId: 'v1',
            slug: 's',
            checksum: 'c1',
            answers: reactive<Record<string, string>>({ name: 'Ada' }),
            locale: ref('es'),
            currentStepKey: ref('s2'),
            storage: memStorage(),
            now: () => T0,
        });
        autosave.flush();
        await vi.waitFor(async () => expect(await db.draft_answers.get(['v1', 's'])).toBeDefined());

        const restored = await autosave.restore();
        expect(restored).toMatchObject({ checksum: 'c1', locale: 'es', currentStepKey: 's2', answers: { name: 'Ada' } });
        autosave.dispose();
    });

    it('discards a draft written under a different checksum (version drift)', async () => {
        const db = freshDb();
        await db.draft_answers.put({
            form_version_id: 'v1',
            local_draft_id: 's',
            checksum: 'old',
            locale: 'en',
            current_step_key: 'x',
            answers: {},
            updated_at: new Date(T0).toISOString(),
        });
        const autosave = make({ db, checksum: 'new' });
        expect(await autosave.restore()).toBeNull();
        expect(await db.draft_answers.get(['v1', 's'])).toBeUndefined();
        autosave.dispose();
    });

    it('discards a draft older than the 7-day inactivity window', async () => {
        const db = freshDb();
        await db.draft_answers.put({
            form_version_id: 'v1',
            local_draft_id: 's',
            checksum: 'c1',
            locale: 'en',
            current_step_key: 'x',
            answers: {},
            updated_at: new Date(T0).toISOString(),
        });
        const autosave = make({ db, now: () => T0 + 8 * 24 * 60 * 60 * 1000 });
        expect(await autosave.restore()).toBeNull();
        autosave.dispose();
    });

    it('clear() removes the stored draft (called after a successful submit)', async () => {
        const db = freshDb();
        const autosave = createAutosave({
            db,
            formId: 'f',
            formVersionId: 'v1',
            slug: 's',
            checksum: 'c1',
            answers: reactive<Record<string, string>>({ a: '1' }),
            locale: ref('en'),
            currentStepKey: ref('x'),
            storage: memStorage(),
            now: () => T0,
        });
        autosave.flush();
        await vi.waitFor(async () => expect(await db.draft_answers.get(['v1', 's'])).toBeDefined());
        await autosave.clear();
        expect(await db.draft_answers.get(['v1', 's'])).toBeUndefined();
        autosave.dispose();
    });

    it('migrates a pre-G8b localStorage draft into Dexie on first restore', async () => {
        const db = freshDb();
        const storage = memStorage();
        storage.setItem(
            draftKey('f', 's'),
            JSON.stringify({ checksum: 'c1', locale: 'en', currentStepKey: 'x', answers: { k: 'v' }, savedAt: new Date(T0).toISOString() }),
        );
        const autosave = make({ db, storage });

        const restored = await autosave.restore();
        expect(restored).toMatchObject({ answers: { k: 'v' }, locale: 'en' });
        expect(storage.getItem(draftKey('f', 's'))).toBeNull(); // legacy key retired
        expect(await db.draft_answers.get(['v1', 's'])).toBeDefined(); // lifted into Dexie
        autosave.dispose();
    });
});
