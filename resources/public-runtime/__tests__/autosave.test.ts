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
    it('is fully inert when disabled — no restore, no writes (Increment G8c resolve mode)', async () => {
        const db = freshDb();
        // Seed a draft that a live session WOULD restore; the disabled autosave must ignore it.
        await db.draft_answers.put({
            form_version_id: 'v1',
            local_draft_id: 's',
            checksum: 'c1',
            locale: 'en',
            current_step_key: 'x',
            answers: { name: 'Existing' },
            updated_at: new Date(T0).toISOString(),
            respondent_session_id: null,
        });
        const answers = reactive<Record<string, string>>({ name: 'Reviewing' });
        const autosave = createAutosave({
            db,
            formId: 'f',
            formVersionId: 'v1',
            slug: 's',
            checksum: 'c1',
            answers,
            locale: ref('en'),
            currentStepKey: ref('x'),
            storage: memStorage(),
            now: () => T0,
            enabled: false,
        });

        expect(await autosave.restore()).toBeNull();

        // A change + flush must not overwrite the seeded draft (the review session's durable copy is the outbox row).
        answers.name = 'Changed';
        autosave.flush();
        await Promise.resolve();
        expect((await db.draft_answers.get(['v1', 's']))?.answers).toEqual({ name: 'Existing' });
        autosave.dispose();
    });

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
            respondent_session_id: null,
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
            respondent_session_id: null,
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

/**
 * Increment M21 — the visit gate.
 *
 * ⚠️ THE FIRST TEST HERE IS THE POSITIVE CONTROL AND IT IS NOT DECORATION. The failure mode this whole
 * suite is exposed to is GREEN-WHILE-DEAD: `restore()` swallows every Dexie error (`:166`, `:186`) and
 * returns `null`, so a scoping bug that broke restore for EVERYBODY would look exactly like a passing
 * suite. `restores a draft written by THIS visit` fails if the gate is too tight; `refuses …` fails if it
 * is absent. Neither alone proves the feature; the pair does.
 */
describe('createAutosave — the visit gate (Increment M21)', () => {
    const VISIT_A = 'visit-a';
    const VISIT_B = 'visit-b';

    /** A stored draft, with the M21 stamp explicit so each case says who wrote it. */
    async function seed(db: ReturnType<typeof freshDb>, sessionId: string | null, over: Record<string, unknown> = {}) {
        await db.draft_answers.put({
            form_version_id: 'v1',
            local_draft_id: 's',
            checksum: 'c1',
            locale: 'en',
            current_step_key: 'x',
            answers: { name: 'Ada' },
            updated_at: new Date(T0).toISOString(),
            respondent_session_id: sessionId,
            ...over,
        });
    }

    it('POSITIVE CONTROL — restores a draft written by THIS visit (the F6b feature still works)', async () => {
        const db = freshDb();
        await seed(db, VISIT_A);
        const autosave = make({ db, sessionId: VISIT_A });

        expect(await autosave.restore()).toMatchObject({ answers: { name: 'Ada' }, locale: 'en' });
        autosave.dispose();
    });

    it('refuses a draft written by ANOTHER visit — the abandoned-kiosk leak', async () => {
        const db = freshDb();
        await seed(db, VISIT_A);
        const autosave = make({ db, sessionId: VISIT_B });

        expect(await autosave.restore()).toBeNull();
        autosave.dispose();
    });

    it('LEAVES the refused row in place, because the shared key is what collects it', async () => {
        const db = freshDb();
        await seed(db, VISIT_A);
        const autosave = make({ db, sessionId: VISIT_B });

        await autosave.restore();
        // Not deleted: destroying a stranger's work on a READ is the harm `lib/outbox.ts` names. The next
        // keystroke overwrites it on the same primary key, which is why the visit is not in the key.
        expect(await db.draft_answers.get(['v1', 's'])).toBeDefined();
        autosave.dispose();
    });

    it('treats a NULL stamp as an earlier visit, never as a wildcard (a pre-M21 row)', async () => {
        const db = freshDb();
        await seed(db, null);
        const autosave = make({ db, sessionId: VISIT_B });

        expect(await autosave.restore()).toBeNull();
        autosave.dispose();
    });

    it('an UNDEFINED sessionId does not scope at all, so every pre-M21 call shape is unchanged', async () => {
        const db = freshDb();
        await seed(db, VISIT_A);
        // No `sessionId` — a bare `RuntimeSession` mount, where the inject falls back to null.
        const autosave = make({ db });

        expect(await autosave.restore()).toMatchObject({ answers: { name: 'Ada' } });
        autosave.dispose();
    });

    it('stamps the current visit onto every write', async () => {
        const db = freshDb();
        const autosave = make({ db, sessionId: VISIT_A, answers: reactive<Record<string, string>>({ a: '1' }) });
        autosave.flush();

        await vi.waitFor(async () =>
            expect(await db.draft_answers.get(['v1', 's'])).toMatchObject({ respondent_session_id: VISIT_A }),
        );
        autosave.dispose();
    });

    it('stamps NULL when unscoped, so an unscoped writer can never be mistaken for a visit', async () => {
        const db = freshDb();
        const autosave = make({ db, answers: reactive<Record<string, string>>({ a: '1' }) });
        autosave.flush();

        await vi.waitFor(async () =>
            expect(await db.draft_answers.get(['v1', 's'])).toMatchObject({ respondent_session_id: null }),
        );
        autosave.dispose();
    });

    it('still collects an EXPIRED foreign row — freshness is tested before ownership', async () => {
        const db = freshDb();
        await seed(db, VISIT_A);
        const autosave = make({ db, sessionId: VISIT_B, now: () => T0 + 8 * 24 * 60 * 60 * 1000 });

        expect(await autosave.restore()).toBeNull();
        // The seven-day sweep is a branch inside restore() and the only sweeper this table has. Checking
        // ownership first would make the leak smaller and the litter permanent.
        expect(await db.draft_answers.get(['v1', 's'])).toBeUndefined();
        autosave.dispose();
    });

    it('closes the SECOND door — a scoped session never takes the legacy localStorage path', async () => {
        const db = freshDb();
        const storage = memStorage();
        storage.setItem(
            draftKey('f', 's'),
            JSON.stringify({
                checksum: 'c1',
                locale: 'en',
                currentStepKey: 'x',
                answers: { k: 'v' },
                savedAt: new Date(T0).toISOString(),
            }),
        );
        const autosave = make({ db, storage, sessionId: VISIT_B });

        // `meridian:draft:{formId}:{slug}` is respondent-blind exactly as the Dexie key was, and a draft
        // written before the visit concept existed can never be shown to belong to the visit on screen.
        expect(await autosave.restore()).toBeNull();
        // Not migrated either — lifting it into Dexie would stamp a stranger's answers with THIS visit.
        expect(await db.draft_answers.get(['v1', 's'])).toBeUndefined();
        autosave.dispose();
    });
});

/**
 * Increment M21 — the 30-second backstop is a BACKSTOP, not a heartbeat.
 *
 * `persist()` used to write unconditionally, so an open tab pushed `updated_at` forward every thirty
 * seconds forever. That is this row's subject twice over: the seven-day TTL could never fire on an
 * abandoned tab, because the only thing that expires a draft is a timestamp that stops moving; and
 * `reconcile.ts`'s newest-wins compared a heartbeat against a real save.
 */
describe('createAutosave — the backstop stops stamping an unchanged draft (Increment M21)', () => {
    it('does not rewrite updated_at when nothing changed', async () => {
        const db = freshDb();
        const answers = reactive<Record<string, string>>({ a: '1' });
        let clock = T0;
        const autosave = make({ db, answers, now: () => clock });

        autosave.flush();
        await vi.waitFor(async () => expect(await db.draft_answers.get(['v1', 's'])).toBeDefined());
        const first = (await db.draft_answers.get(['v1', 's']))?.updated_at;

        // The backstop firing an hour later with an untouched answer map.
        clock = T0 + 60 * 60 * 1000;
        autosave.flush();
        await Promise.resolve();

        expect((await db.draft_answers.get(['v1', 's']))?.updated_at).toBe(first);
        autosave.dispose();
    });

    it('still writes as soon as something genuinely changes', async () => {
        const db = freshDb();
        const answers = reactive<Record<string, string>>({ a: '1' });
        let clock = T0;
        const autosave = make({ db, answers, now: () => clock });

        autosave.flush();
        await vi.waitFor(async () => expect(await db.draft_answers.get(['v1', 's'])).toBeDefined());

        clock = T0 + 60 * 60 * 1000;
        answers.a = '2';
        autosave.flush();

        await vi.waitFor(async () =>
            expect(await db.draft_answers.get(['v1', 's'])).toMatchObject({
                answers: { a: '2' },
                updated_at: new Date(clock).toISOString(),
            }),
        );
        autosave.dispose();
    });

    it('re-writes after clear(), because the row it was tracking is gone', async () => {
        const db = freshDb();
        const answers = reactive<Record<string, string>>({ a: '1' });
        const autosave = make({ db, answers });

        autosave.flush();
        await vi.waitFor(async () => expect(await db.draft_answers.get(['v1', 's'])).toBeDefined());
        await autosave.clear();
        expect(await db.draft_answers.get(['v1', 's'])).toBeUndefined();

        // Same payload as before the clear: the skip must not suppress the write that re-creates the row.
        autosave.flush();
        await vi.waitFor(async () => expect(await db.draft_answers.get(['v1', 's'])).toBeDefined());
        autosave.dispose();
    });

    it('reports activity on a real change, and NOT from the backstop (Increment M21)', async () => {
        // The visit-keepalive hangs off `schedule()` rather than `persist()` on purpose: touching from the
        // 30s timer would keep an ABANDONED tab's visit alive forever and defeat the rotation entirely.
        const db = freshDb();
        const answers = reactive<Record<string, string>>({});
        const onActivity = vi.fn();
        const autosave = make({ db, answers, onActivity });

        autosave.flush(); // a persist, not a change
        expect(onActivity).not.toHaveBeenCalled();

        answers.a = 'typed';
        await vi.waitFor(() => expect(onActivity).toHaveBeenCalled());
        autosave.dispose();
    });
});
