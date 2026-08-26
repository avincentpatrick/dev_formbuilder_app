/**
 * WHO THE DEVICE-LOCAL OUTBOX BELONGS TO (Increment M15) — a respondent-session identifier.
 *
 * `lib/device.ts` answers "which physical device collected this", which is provenance the server
 * wants and which deliberately outlives everybody. This module answers a different question the
 * runtime had never asked: "which VISIT is on screen right now". The outbox surface mounts above
 * the phase machine on an unauthenticated page (`App.vue`), so without an answer the next
 * respondent at a kiosk saw the previous one's queue tags, server references and Discard buttons.
 *
 * ── WHY sessionStorage AND NOT A MODULE-SCOPE CONSTANT ──────────────────────────────────────────
 * A bare `let id = uuidv7()` at module scope is one session per PAGE LOAD, which is stricter and
 * simpler and WRONG — `tests/e2e/public-runtime-offline.spec.ts` proves it. Its conflict test parks
 * a row, RELOADS (`:225`), and only then asserts the review CTA (`:233`), the badge (`:237`) and
 * the resolve flow (`:246`). A per-load id makes that row someone else's after the reload and four
 * assertions die — on a suite that cannot run on the development host. `sessionStorage` survives a
 * reload inside one tab and dies with the tab, which is the shape actually wanted.
 *
 * ── THE TWO ROTATIONS, AND WHY BOTH ARE NEEDED ─────────────────────────────────────────────────
 * `rotate()` is the DELIBERATE hand-over: `App.vue`'s "Submit another response" calls it before
 * reloading, so the next person at the kiosk starts clean. On its own it is not enough, because it
 * depends on the previous respondent doing something correct before they walk away — and walking
 * away without pressing anything is the dominant kiosk path, not an edge case. So the id also
 * carries a `lastSeen` stamp and a fresh one is minted once the gap exceeds IDLE_MS.
 *
 * ⚠️ THE WINDOW IS IDLE TIME, NOT ELAPSED TIME, WHICH IS WHY TEN MINUTES IS NOT A GUESS AT HOW
 * LONG A FORM TAKES. `respondentSession()` refreshes the stamp on every read and
 * `touchRespondentSession()` refreshes it on real respondent INPUT, so a respondent filling a long
 * form never crosses it. The only exposed window is AFTER finishing, where ten minutes is short
 * enough that someone arriving at a shared desk almost always crosses it and long enough to
 * survive reading a confirmation screen. A shorter or operator-configurable value belongs to the
 * kiosk-mode row `docs/feature-backlog.md:68` already parks ("lock to one form, auto-reset, clear
 * PII on timeout"), not here.
 *
 * ⛔ INCREMENT M21 — THE PARAGRAPH ABOVE WAS FALSE AS BUILT FOR THE WHOLE OF M15, AND HOW IT WAS
 * FALSE IS WORTH MORE THAN THE FIX. It used to read "`touch()` runs on every read, and the runtime
 * reads this on every refresh of the sync surface". **There was no `touch()` in this module**, and
 * `respondentSession()` had exactly ONE production call site — `App.vue`'s composition root, once
 * per page load. So `lastSeen` recorded BOOT TIME and the window measured elapsed-since-last-boot.
 *
 * ⚠️ AND `__tests__/respondent-session.test.ts` PINNED THE CLAIM AND PASSED, BECAUSE THE CLAIM IS
 * TRUE OF THIS FUNCTION. "Refreshes the stamp on every read, so an active visit never expires
 * under its own reader" reads the session three windows apart and gets one id back — a correct
 * test of a contract that nothing exercised. **A unit test proves what a function does when it is
 * called; only a call-site sweep proves that it is.** Sixth intention-read-as-a-measurement in this
 * project, and the first found by grepping the call sites of a function whose own test was green.
 *
 * The consequence ran in the direction nobody looked. A reload more than IDLE_MS after boot —
 * including `App.vue`'s OWN `window.location.reload()` after a conflict discard — issued a fresh
 * visit, so `pruneSynced()` deleted the respondent's own delivered receipts as a stranger's: the
 * one deletion ADR-0021 promised would only ever touch an earlier visit. And once M21 scopes the
 * DRAFT to a visit, the same rotation would have thrown away a respondent's own half-filled form
 * on a refresh. `touchRespondentSession()` is called from respondent input rather than from a
 * timer, so an abandoned tab stops touching the moment the respondent stops typing — which is what
 * "idle" was supposed to mean all along.
 *
 * ⛔ THIS MODULE MUST NEVER BE IMPORTED BY `lib/outbox.ts`, `lib/db.ts` OR ANYTHING ELSE IN
 * `lib/replay.ts`'s IMPORT GRAPH. `sw.ts` imports `lib/db.ts` and `lib/replay.ts`, and
 * `tsconfig.sw.json` re-checks that graph under `lib: ["ESNext", "WebWorker"], types: []` — where
 * `Storage`, `window` and `sessionStorage` do not exist. One import would fail the second
 * type-check program. `lib/device.ts` is under the same constraint and solves it the same way: the
 * id is read at the composition root and threaded down as a plain string. `lib/outbox.ts` stays
 * pure and db-injected, and takes the session id as an argument.
 */

import { uuidv7 } from './uuid';

export const RESPONDENT_SESSION_KEY = 'meridian:respondent-session';

/** Ten minutes of inactivity ends a visit. See the docblock — this is idle time, not elapsed time. */
export const IDLE_MS = 10 * 60 * 1000;

interface StoredSession {
    id: string;
    lastSeen: number;
}

/**
 * The fallback when storage is unavailable, and it FAILS CLOSED ON PURPOSE.
 *
 * `lib/device.ts:22-25` hits the same wall (private mode, storage denied) and returns a fresh id,
 * accepting that provenance degrades. Here the degradation runs the other way and is the safe one:
 * a per-load id means every boot is its own visit, so nothing is ever shown across respondents. It
 * costs a respondent sight of their own row after a refresh; it cannot leak one.
 */
let memoryFallback: string | null = null;

function read(storage: Storage): StoredSession | null {
    try {
        const raw = storage.getItem(RESPONDENT_SESSION_KEY);
        if (raw === null || raw === '') {
            return null;
        }
        const parsed: unknown = JSON.parse(raw);
        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }
        const { id, lastSeen } = parsed as Partial<StoredSession>;

        return typeof id === 'string' && id !== '' && typeof lastSeen === 'number' && Number.isFinite(lastSeen)
            ? { id, lastSeen }
            : null;
    } catch {
        // A malformed or unreadable value is treated as absent, which mints a fresh visit. Repairing
        // it would be the wrong instinct: the only thing a corrupt marker could do is hand this
        // respondent a stranger's scope.
        return null;
    }
}

function write(storage: Storage, session: StoredSession): void {
    try {
        storage.setItem(RESPONDENT_SESSION_KEY, JSON.stringify(session));
    } catch {
        // Quota or a denied store — the caller still gets a usable id for this load.
    }
}

function defaultStorage(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.sessionStorage;
    } catch {
        return null;
    }
}

/**
 * The current visit's id, minting one when there is none and rotating it once the device has been
 * idle past `IDLE_MS`. Reading REFRESHES the stamp, so an active session never expires under its
 * own reader.
 *
 * `now` is injected for the same reason `pruneSynced` injects it: a time-dependent branch that can
 * only be tested by waiting is a branch that does not get tested.
 */
export function respondentSession(storage: Storage | null = defaultStorage(), now: number = Date.now()): string {
    if (storage === null) {
        return (memoryFallback ??= uuidv7());
    }

    const existing = read(storage);

    if (existing !== null && now - existing.lastSeen <= IDLE_MS) {
        write(storage, { id: existing.id, lastSeen: now });
        return existing.id;
    }

    const minted = uuidv7();
    write(storage, { id: minted, lastSeen: now });

    return minted;
}

/**
 * Increment M21 — keep the CURRENT visit alive because the respondent is still working.
 *
 * ⛔ IT NEVER MINTS AND IT NEVER REVIVES, WHICH IS THE WHOLE DIFFERENCE BETWEEN THIS AND
 * `respondentSession()`. An absent marker stays absent — minting here would hand a visit to a page
 * that never asked for one. An EXPIRED marker stays expired — reviving it would let a keystroke
 * from the next respondent at the kiosk resurrect the previous one's visit, which is precisely the
 * window this module exists to close. Both are no-ops, deliberately.
 *
 * It is safe to call on every keystroke: it is one `sessionStorage` write of a short JSON string,
 * on the same store the runtime already reads at boot.
 */
export function touchRespondentSession(storage: Storage | null = defaultStorage(), now: number = Date.now()): void {
    if (storage === null) {
        return;
    }

    const existing = read(storage);

    if (existing === null || now - existing.lastSeen > IDLE_MS) {
        return;
    }

    write(storage, { id: existing.id, lastSeen: now });
}

/**
 * End this visit deliberately — the kiosk hand-over. The NEXT `respondentSession()` call mints a
 * fresh id, so this is called BEFORE the reload rather than after it.
 *
 * It removes the marker rather than writing a new id, because the two are not the same: a caller
 * that rotates and then never reads (a reload that fails, a tab closed mid-gesture) must not leave
 * a fresh, valid, unused session behind for whoever arrives next.
 */
export function rotateRespondentSession(storage: Storage | null = defaultStorage()): void {
    memoryFallback = null;

    if (storage === null) {
        return;
    }

    try {
        storage.removeItem(RESPONDENT_SESSION_KEY);
    } catch {
        // Nothing to do — a storage that refuses removal also refused the write.
    }
}
