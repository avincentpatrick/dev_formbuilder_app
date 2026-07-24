/**
 * Two-tier draft reconciliation (Increment H10, UX §5.2) — the precedence rule for the moment a respondent
 * opens a resume link (`/f/resume/{token}`) on a device that ALSO holds a local Dexie autosave draft for the
 * same form version. Pure and framework-free so it is unit-testable against fabricated timestamps.
 *
 * Precedence (LOCKED with the user, 2026-07-23 — "newest-wins, non-destructive"):
 *   1. No local draft, or a local draft from a DIFFERENT schema version (checksum mismatch, i.e. the form was
 *      republished since) → the SERVER draft wins. The stale local row is left untouched (never deleted); the
 *      running autosave converges it on the next save.
 *   2. A same-version local draft that was saved MORE RECENTLY than the server draft → the LOCAL draft wins, and
 *      a note tells the respondent we kept their device's newer answers (the mildly surprising case — they
 *      clicked the emailed link but we did not roll back their in-progress work).
 *   3. Otherwise → the SERVER draft wins silently (the resume link brought the newer/equal copy, as expected).
 *
 * The uuid is NOT decided here — the caller ALWAYS seeds the session with the server draft's
 * `client_submission_uuid` so the eventual submit promotes that row (the H9a invariant), regardless of which
 * tier's ANSWERS win. This function only chooses the answers/step/locale to restore.
 */

import type { AnswerMap, ResumeDraftResult } from './types';

/** The local Dexie draft, narrowed to the fields reconciliation needs (a subset of `DraftRow`). */
export interface LocalDraft {
    checksum: string;
    locale: string;
    currentStepKey: string;
    answers: AnswerMap;
    updatedAt: string;
}

export interface Reconciled {
    answers: AnswerMap;
    /** The locale to restore (server/local per the winner); null falls back to the form default. */
    locale: string | null;
    /** The step key to resume at; null means "no stored step — derive client-side". */
    currentStepKey: string | null;
    /** Which tier's answers were restored. */
    source: 'server' | 'local';
    /** A short, non-alarming note when the outcome might surprise the respondent (local-wins); else null. */
    note: string | null;
}

const LOCAL_WINS_NOTE = 'You have more recent answers saved on this device, so we kept those.';

/** Parse an ISO timestamp to epoch ms; an unparseable/absent value sorts oldest so it never beats a real one. */
function epoch(iso: string | null): number {
    if (iso === null) {
        return Number.NEGATIVE_INFINITY;
    }
    const t = Date.parse(iso);
    return Number.isNaN(t) ? Number.NEGATIVE_INFINITY : t;
}

export function reconcileDraft(
    server: ResumeDraftResult,
    schemaChecksum: string,
    local: LocalDraft | undefined,
): Reconciled {
    const serverResult: Reconciled = {
        answers: server.answers,
        locale: server.locale,
        currentStepKey: server.draftCurrentStep,
        source: 'server',
        note: null,
    };

    // No comparable local draft: nothing to reconcile against — the server draft is the only durable state.
    // A checksum mismatch means the local draft is stale across a republish (the autosave guard would discard
    // it anyway); the server draft is pinned to its own version, so it wins without a note.
    if (local === undefined || local.checksum !== schemaChecksum) {
        return serverResult;
    }

    // Same version on both tiers → newest-wins by last-saved time.
    if (epoch(local.updatedAt) > epoch(server.lastSavedAt)) {
        return {
            answers: local.answers,
            locale: local.locale,
            currentStepKey: local.currentStepKey,
            source: 'local',
            note: LOCAL_WINS_NOTE,
        };
    }

    return serverResult;
}
