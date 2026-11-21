/**
 * The device's memory that it SENT a form's response — M248 (`D232`, NHETE recommendation 6a).
 *
 * A form whose "After submitting" setting is `amend` or `once` asks the device to remember it answered: reopening the
 * form's link then shows "You've already answered this form" instead of a blank form, and in `amend` mode offers
 * "Change my answers" through the token kept here. Guests do not sign in, so this is the whole of "one response" —
 * another phone or a cleared browser starts afresh (`D232` answer 2).
 *
 * ⛔ IN DEXIE'S `app_state`, NOT `localStorage`, BECAUSE TWO PATHS DELIVER A RESPONSE. The page sends it when online;
 * the service worker's Background Sync replays it when it was queued offline (`replay.ts`), and a worker has no
 * `localStorage`. Both reach this table, so both can remember. That is also why this module imports nothing but `db.ts`
 * — it is in `sw.ts`'s import graph, which `tsconfig.sw.json` re-checks with no DOM lib.
 *
 * ⚠️ Keyed by the share link's SLUG, not the form id: an outbox row carries the slug and no form id, and "the form's
 * link" is what a respondent reopens. A renamed link is a different address, so starting afresh there is correct.
 *
 * ⚠️ THE ACCEPTED COST (`D232`, extending `D118`): on a shared phone the next person sees "already answered", and in
 * `amend` mode can open the previous respondent's answers. The kiosk-mode row is where this is switched off.
 */
import type { MeridianDb } from './db';

/** What the device remembers about the response it sent. */
export interface AnsweredRecord {
    /** The server's reference, or null for a response still queued on this device. */
    reference: string | null;
    /** When it was sent (ISO). */
    at: string;
    /** The amend token, in `amend` mode once the server has issued one; null otherwise. */
    amendToken: string | null;
}

const KEY_PREFIX = 'answered:';

function keyFor(slug: string): string {
    return `${KEY_PREFIX}${slug}`;
}

/** What this device remembers sending on the form at `slug`, or null. Never throws: a refused read is "nothing". */
export async function readAnswered(db: MeridianDb, slug: string): Promise<AnsweredRecord | null> {
    try {
        const row = await db.app_state.get(keyFor(slug));
        if (row === undefined) {
            return null;
        }
        const parsed = JSON.parse(row.value) as Partial<AnsweredRecord>;
        if (typeof parsed.at !== 'string') {
            return null;
        }

        return {
            reference: typeof parsed.reference === 'string' ? parsed.reference : null,
            at: parsed.at,
            amendToken: typeof parsed.amendToken === 'string' && parsed.amendToken !== '' ? parsed.amendToken : null,
        };
    } catch {
        return null;
    }
}

/**
 * Remember that this device sent the response on the form at `slug`. A later write keeps what the earlier one knew
 * and the later one did not — a queued response is remembered at once with no reference or token, and the replay that
 * delivers it fills both in. Best-effort: a refused write leaves the device able to answer again, which is today.
 */
export async function rememberAnswered(db: MeridianDb, slug: string, record: AnsweredRecord): Promise<void> {
    try {
        const before = await readAnswered(db, slug);
        const merged: AnsweredRecord = {
            reference: record.reference ?? before?.reference ?? null,
            at: record.at,
            amendToken: record.amendToken ?? before?.amendToken ?? null,
        };
        await db.app_state.put({ key: keyFor(slug), value: JSON.stringify(merged) });
    } catch {
        // Quota or storage refused — the memory is a convenience, never a gate.
    }
}
