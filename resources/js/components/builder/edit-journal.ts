/**
 * M170 (`R-bfa3eb93`) — the builder's edits the server has not confirmed yet, kept for this tab so a refresh puts them
 * back.
 *
 * The user's report (2026-10-14): a setting changed and then a plain refresh, with nothing clicked in between, was gone —
 * while the toolbar already said "All changes saved". The store waits 600 ms after the last keystroke before it saves,
 * and a refresh inside that wait took the edit with it.
 *
 * ⛔ SENDING THE EDIT AS THE PAGE GOES IS NOT ENOUGH ON ITS OWN, AND THAT IS WHY THIS FILE EXISTS. Chrome asks for the
 * new page before the old one's `pagehide` runs, and even a save sent from `beforeunload` races the request for the new
 * page — the server can render the builder before the save commits, and the refreshed page shows the old value. So
 * every edit is written here the moment it is made, and the store puts back on load whatever the server does not hold
 * yet, then saves it. The flush on leaving still runs: it is what saves an edit when the tab is CLOSED.
 *
 * ⚠️ `sessionStorage`, NOT `localStorage`: one tab's unsent edit is that tab's. A second tab of the same builder must
 * never replay the first one's half-typed label, and a shared computer must not hand one author's unsaved form to the
 * next person who opens it. A refresh keeps `sessionStorage`; closing the tab ends it.
 *
 * ⚠️ EVERY ACCESS IS GUARDED. Storage can be refused (a private window, blocked site data) or full; the builder then
 * works exactly as it did before M170, minus the put-back.
 */

export type JournalKind = 'field' | 'section';

export interface JournalEntry {
    kind: JournalKind;
    /** The server id — a refresh mints new client uids, so the uid cannot be the key. */
    id: string;
    /** The server's version token the edit was made against. A different one on load means the row moved on since. */
    base: string | null;
    /** The edited content, as the store's own snapshot of it. */
    snapshot: Record<string, unknown>;
    /**
     * EVERY content this tab has SENT for the row since `base`. ⚠️ THE ENTRY IS NOT DROPPED WHEN THE TOKEN MOVED IF THE
     * SERVER NOW HOLDS ONE OF THESE — a move to something this tab sent is this tab's own move. Measured in a real browser,
     * twice: (1) an earlier edit was still on its way when the next one was made and the page refreshed, so the newer
     * edit's `base` predated a save of this tab's own; (2) a page going makes its in-flight `keepalive` request FAIL on the
     * page's side while it still reaches the server, so the queue sent the next edit at the old token, the server refused
     * it with a 409, and only the earlier write landed. A list, because (2) means the write that lands need not be the
     * last one sent.
     */
    sent?: Record<string, unknown>[];
    /** When it was last written, so an abandoned entry expires rather than replaying forever. */
    at: number;
}

const PREFIX = 'meridian:builder-edits:';

/** A day. An edit older than that is not one an author is waiting to see come back. */
export const JOURNAL_TTL_MS = 24 * 60 * 60 * 1000;

/** How many sent contents an entry remembers. */
const SENT_KEPT = 10;

function defaultStorage(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.sessionStorage;
    } catch {
        return null;
    }
}

function isEntry(value: unknown): value is JournalEntry {
    if (typeof value !== 'object' || value === null) return false;
    const entry = value as Partial<JournalEntry>;

    return (
        (entry.kind === 'field' || entry.kind === 'section')
        && typeof entry.id === 'string'
        && (entry.base === null || typeof entry.base === 'string')
        && typeof entry.snapshot === 'object'
        && entry.snapshot !== null
        && (entry.sent === undefined || Array.isArray(entry.sent))
        && typeof entry.at === 'number'
    );
}

export interface EditJournal {
    /** Every live entry; expired and malformed ones are dropped as they are read. */
    read(): JournalEntry[];
    /** Keep the row's edit. An entry's `sent` survives a newer edit unless the caller says what it now is. */
    put(entry: Omit<JournalEntry, 'at' | 'sent'> & { sent?: Record<string, unknown>[] }): void;
    /** Add what is being sent for the row to its `sent`, if an edit of it is kept. */
    markSent(kind: JournalKind, id: string, sent: Record<string, unknown>): void;
    drop(kind: JournalKind, id: string): void;
}

/**
 * The journal of one draft. Keyed by the DRAFT, not the form: a publish starts a new draft whose rows have new ids, so
 * an entry from the old one could never match anything and would only linger.
 */
export function editJournal(draftId: string, storage: Storage | null = defaultStorage(), now: () => number = () => Date.now()): EditJournal {
    const key = `${PREFIX}${draftId}`;

    function load(): JournalEntry[] {
        if (storage === null) return [];
        try {
            const raw = storage.getItem(key);
            const parsed: unknown = raw === null ? [] : JSON.parse(raw);

            return Array.isArray(parsed) ? parsed.filter(isEntry) : [];
        } catch {
            return [];
        }
    }

    function save(entries: JournalEntry[]): void {
        if (storage === null) return;
        try {
            if (entries.length === 0) storage.removeItem(key);
            else storage.setItem(key, JSON.stringify(entries));
        } catch {
            // Quota or a refused store: the edit still saves the ordinary way, it just cannot be put back.
        }
    }

    return {
        read() {
            const all = load();
            const live = all.filter((entry) => now() - entry.at <= JOURNAL_TTL_MS);
            if (live.length !== all.length) save(live);

            return live;
        },
        put(entry) {
            const all = load();
            const previous = all.find((other) => other.kind === entry.kind && other.id === entry.id);
            const rest = all.filter((other) => other !== previous);
            save([...rest, { ...entry, sent: entry.sent ?? previous?.sent ?? [], at: now() }]);
        },
        markSent(kind, id, sent) {
            const all = load();
            const entry = all.find((other) => other.kind === kind && other.id === id);
            if (!entry) return;
            const written = JSON.stringify(sent);
            // The newest last, each content once, and a bounded list: a row edited for an hour without a save landing is
            // not one whose fifteenth-oldest attempt is going to be what the server holds.
            entry.sent = [...(entry.sent ?? []).filter((other) => JSON.stringify(other) !== written), sent].slice(-SENT_KEPT);
            save(all);
        },
        drop(kind, id) {
            const all = load();
            const rest = all.filter((entry) => !(entry.kind === kind && entry.id === id));
            if (rest.length !== all.length) save(rest);
        },
    };
}
