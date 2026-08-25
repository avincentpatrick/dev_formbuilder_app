import { beforeEach, describe, expect, it } from 'vitest';
import { IDLE_MS, RESPONDENT_SESSION_KEY, respondentSession, rotateRespondentSession } from '../lib/respondent-session';

/**
 * Increment M15 — the visit identifier the outbox surface is scoped to.
 *
 * Every case here drives an INJECTED `Storage` and an INJECTED clock, for the reason `pruneSynced`'s tests
 * give: a time-dependent branch that can only be exercised by waiting is a branch that does not get
 * exercised. The one case that cannot use an injected store is the storage-denied fallback, which is the
 * point of it.
 */

function memoryStorage(): Storage {
    const map = new Map<string, string>();

    return {
        get length() {
            return map.size;
        },
        clear: () => map.clear(),
        getItem: (key: string) => map.get(key) ?? null,
        key: (index: number) => [...map.keys()][index] ?? null,
        removeItem: (key: string) => void map.delete(key),
        setItem: (key: string, value: string) => void map.set(key, value),
    } as Storage;
}

/** A store that throws on every operation — private mode, or site data blocked. */
function hostileStorage(): Storage {
    const boom = (): never => {
        throw new DOMException('denied');
    };

    return {
        get length(): number {
            return boom();
        },
        clear: boom,
        getItem: boom,
        key: boom,
        removeItem: boom,
        setItem: boom,
    } as unknown as Storage;
}

describe('respondentSession', () => {
    let store: Storage;

    beforeEach(() => {
        store = memoryStorage();
        rotateRespondentSession(store);
    });

    it('mints a uuid on first read and persists it', () => {
        const id = respondentSession(store, 1_000);

        expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        expect(store.getItem(RESPONDENT_SESSION_KEY)).toContain(id);
    });

    it('returns the SAME id across reads inside the idle window — this is what survives a reload', () => {
        // The load-bearing case. `tests/e2e/public-runtime-offline.spec.ts` parks a conflict row, reloads,
        // and only then asserts the review CTA; a per-page-load id would make that row a stranger's and
        // take four assertions down with it, on a suite that cannot run on the development host.
        const first = respondentSession(store, 1_000);

        expect(respondentSession(store, 1_000 + IDLE_MS)).toBe(first);
    });

    it('mints a NEW id once the device has been idle past the window', () => {
        const first = respondentSession(store, 1_000);

        expect(respondentSession(store, 1_000 + IDLE_MS + 1)).not.toBe(first);
    });

    it('refreshes the stamp on every read, so an active visit never expires under its own reader', () => {
        // Idle time, not elapsed time: three reads a window apart span more than IDLE_MS in total and are
        // still one visit. This is why ten minutes is not a guess at how long a form takes to fill.
        const first = respondentSession(store, 0);

        expect(respondentSession(store, IDLE_MS)).toBe(first);
        expect(respondentSession(store, IDLE_MS * 2)).toBe(first);
        expect(respondentSession(store, IDLE_MS * 3)).toBe(first);
    });

    it('rotates on demand, and REMOVES the marker rather than minting a replacement', () => {
        const first = respondentSession(store, 1_000);

        rotateRespondentSession(store);

        // Nothing is left behind for whoever picks the device up next, even if the reload that normally
        // follows never happens.
        expect(store.getItem(RESPONDENT_SESSION_KEY)).toBeNull();
        expect(respondentSession(store, 1_100)).not.toBe(first);
    });

    it('treats a corrupt marker as absent rather than repairing it', () => {
        store.setItem(RESPONDENT_SESSION_KEY, '{not json');

        expect(respondentSession(store, 1_000)).toMatch(/^[0-9a-f]{8}-/);
    });

    it('treats a marker with a missing or non-numeric stamp as absent', () => {
        store.setItem(RESPONDENT_SESSION_KEY, JSON.stringify({ id: 'someone-elses-visit' }));

        expect(respondentSession(store, 1_000)).not.toBe('someone-elses-visit');
    });

    it('FAILS CLOSED when storage is denied — a per-load id, never a shared one', () => {
        // `lib/device.ts` hits the same wall and degrades the other way, accepting a fresh provenance id.
        // Here the degradation is the safe direction: every boot becomes its own visit, so a respondent
        // loses sight of their own row after a refresh and can never be shown someone else's.
        const hostile = hostileStorage();

        expect(() => respondentSession(hostile, 1_000)).not.toThrow();
        expect(respondentSession(hostile, 1_000)).toMatch(/^[0-9a-f]{8}-/);
    });

    it('rotates the in-memory fallback too, so the denied-storage path still hands over', () => {
        const hostile = hostileStorage();
        const first = respondentSession(hostile, 1_000);

        rotateRespondentSession(hostile);

        expect(respondentSession(hostile, 1_100)).not.toBe(first);
    });
});
