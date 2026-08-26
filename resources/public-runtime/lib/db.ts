/**
 * The guest runtime's IndexedDB source of truth (Increment G8b), via Dexie. Five stores back the offline
 * engine (docs/offline-first-sync-design.md §3):
 *  - `cached_manifests` — a pinned form version's schema, keyed by form_version_id (forward-compatible with the
 *    authenticated sync/manifest surface; the guest schema itself is still served by the G8a SW schema cache).
 *  - `draft_answers`    — in-progress, not-yet-finalized answers (the G8a localStorage autosave migrates here).
 *  - `outbox`           — FINALIZED submissions queued for replay, keyed by the time-sortable client_submission_uuid.
 *                         From I10d a DELIVERED row is RETAINED (status `synced`, answers scrubbed) rather
 *                         than deleted, so the respondent can see what was sent; see outbox.ts.
 *  - `media_queue`      — respondent media blobs awaiting upload, referencing their parent outbox row by uuid.
 *  - `app_state`        — (H23b) small device-scoped scalars that outlive a page load and belong to no form.
 *
 * The SAME db name is opened from both the Vue app and the service worker, so a `sync` event can replay the
 * exact rows a tab enqueued. Helpers (outbox.ts / media-queue.ts / replay.ts) take a db instance as a param so
 * they stay framework-free and unit-testable against `fake-indexeddb`.
 */

import Dexie, { type Table } from 'dexie';
import type { AnswerMap, RawSchemaSnapshot } from './types';

export const DB_NAME = 'meridian-offline';

/** A pinned form version's manifest (choice_lists/media_refs are forward-compatible; empty in G8b). */
export interface CachedManifest {
    form_version_id: string;
    checksum: string;
    schema_snapshot: RawSchemaSnapshot;
    choice_lists: Record<string, unknown>;
    media_refs: unknown[];
    manifest_generated_at: string;
    cached_at: string;
}

/** One active draft per share link in G8b (`local_draft_id = slug`); the compound key leaves room for multi-draft. */
export interface DraftRow {
    form_version_id: string;
    local_draft_id: string;
    checksum: string;
    locale: string;
    current_step_key: string;
    answers: AnswerMap;
    updated_at: string;
    /**
     * Increment M21 — which VISIT wrote this draft, so an ABANDONED fill is never restored into the next
     * respondent's form. `respondent_session_id` on `OutboxRow` above answers the same question for
     * FINALIZED rows; this is the same question one channel earlier, on the tier that carries answers the
     * respondent is still typing. Un-indexed, so no `db.version()` bump — the rule
     * `docs/offline-first-sync-design.md` §3 states outright and that `conflict_code`, `server_reference`,
     * `synced_at`, `base_content_checksum` and M15's own session stamp have all now set.
     *
     * ⚠️ NULL IS A REAL VALUE AND IT MEANS "NOT THIS VISIT", exactly as on `OutboxRow`. A row written
     * before M21 has no session, and the safe reading of an unknown owner is *somebody else*.
     *
     * ⛔ IT IS DELIBERATELY NOT PART OF THE PRIMARY KEY, AND THE FIRST REASON IS MEASURED RATHER THAN
     * ARGUED: **DEXIE REFUSES.** `node_modules/dexie/dist/dexie.js:3832` throws
     * `Upgrade('Not yet support for changing primary key')` inside the upgrade transaction, so moving
     * `[form_version_id+local_draft_id]` to a three-part key does not degrade — **the database fails to
     * open, on every device that already has one**, taking the outbox and the queued media with it. The
     * comment at `:34` saying the compound key "leaves room for multi-draft" is about adding rows under the
     * existing shape, not about re-shaping it.
     *
     * The second reason is what the shared key is FOR. This table has two readers
     * (`useAutosave.restore()`, `App.vue`'s resume read), one deleter (`clear()`, which deletes only THIS
     * session's key) and **no enumerator at all** — the seven-day TTL is a branch inside `restore()`, not a
     * sweeper. An abandoned draft is collected by the next respondent's first keystroke landing on the SAME
     * primary key; a per-visit key removes that and leaves the answers unreachable on shared hardware.
     * ⚠️ Stated honestly, because it is a difference of RATE rather than of kind: uncollectable rows
     * already exist here — a republish moves `form_version_id`, so the pre-republish row is orphaned with
     * nothing able to reach it. That is a real pre-existing hole, filed in `docs/feature-backlog.md`, and
     * it is not this field's job to close.
     *
     * Sharing the key is what collects the row; the stamp is what stops it being read.
     * See `docs/adr/0021-respondent-scoped-device-outbox.md`.
     */
    respondent_session_id: string | null;
}

/**
 * Increment M21 — may the visit on screen READ this draft? The single predicate both of this table's two
 * readers consult, so they cannot drift apart.
 *
 * It lives here, beside the field it guards, rather than in `composables/useAutosave.ts`, for two reasons.
 * `App.vue`'s resume read is the OTHER reader and must apply the identical rule — the two disagreeing is
 * how the sibling `minor` came to exist one channel over. And this module is in `sw.ts`'s import graph,
 * which `tsconfig.sw.json` re-checks with no DOM lib, so the predicate takes the id as a plain string and
 * imports nothing: `lib/respondent-session.ts` reads `sessionStorage` and must never be pulled in here.
 *
 * ⚠️ `undefined` MEANS "DO NOT SCOPE" AND `null` MEANS "AN EARLIER VISIT" — two different absences, and
 * conflating them is the whole defect. `undefined` is the caller saying it has no visit concept (a bare
 * test mount), and it preserves every pre-M21 call shape. `null` on the ROW is a row written before M21 or
 * by an unscoped caller, and it never matches a real id, because the safe reading of an unknown owner is
 * *somebody else*. Same rule as `OutboxRow.respondent_session_id`, stated once for both.
 */
export function draftBelongsToVisit(
    row: Pick<DraftRow, 'respondent_session_id'>,
    sessionId: string | undefined,
): boolean {
    return sessionId === undefined || row.respondent_session_id === sessionId;
}

export type OutboxStatus = 'pending' | 'synced' | 'conflict' | 'needs_attention';

/** A finalized submission queued for idempotent replay through the guest submissions endpoint. */
export interface OutboxRow {
    client_submission_uuid: string;
    slug: string;
    form_version_id: string;
    checksum: string;
    answers: AnswerMap;
    locale: string;
    device_id: string;
    app_version: string;
    /**
     * Increment M15 — which VISIT to this device finalized this row, so the outbox surface can show a
     * respondent their own submissions and not the previous respondent's. Un-indexed, so no
     * `db.version()` bump, the same reasoning as `conflict_code` below; the list filters in JS over an
     * already-ordered collection.
     *
     * ⚠️ NULL IS A REAL VALUE AND IT MEANS "NOT THIS VISIT". A row written before M15 has no session,
     * and the safe reading of an unknown owner is *somebody else* — so it degrades to the anonymous
     * count rather than being shown. Every reader must treat null as an EARLIER session, never as a
     * wildcard that matches the current one. See `docs/adr/0021-respondent-scoped-device-outbox.md`.
     */
    respondent_session_id: string | null;
    /**
     * Increment P3a — the server-draft baseline this submission was finalized against; null when no server
     * draft existed. Un-indexed, so no Dexie version bump (`docs/offline-first-sync-design.md` §3). A row
     * written by an older build simply has `undefined` here, which replays exactly as it did before P3a —
     * which is why the SUBMIT channel checks only when a claim is present.
     */
    base_content_checksum: string | null;
    submitted_at: string;
    status: OutboxStatus;
    attempts: number;
    last_error: string | null;
    /** Increment G8c — which 409 parked a `conflict` row: `form_updated`/`submission_version_superseded` (the
     *  form changed) vs `submission_conflict` (a server-side copy already exists), so the resolve notice is accurate.
     *  Not indexed → no `db.version()` bump. */
    conflict_code: string | null;
    server_submission_id: string | null;
    /** Increment J2e — the server-issued short handle, recorded when the row settles so the outbox can stop
     *  showing its provisional queue tag and start showing the code the tenant can actually find. Un-indexed
     *  → no `db.version()` bump, the same reasoning as `conflict_code` above. Null until `synced` (and on any
     *  row synced by a build that predates J2e, which is why every reader tolerates null). */
    server_reference: string | null;
    /** Increment I10d — when the server accepted this row. Un-indexed → no `db.version()` bump, the same
     *  reasoning as `conflict_code` above. Null for every status except `synced`. */
    synced_at: string | null;
    created_at: string;
    updated_at: string;
}

export type MediaStatus = 'queued' | 'uploaded';

/** A respondent-picked media blob stashed while offline; uploaded independently at replay to obtain a real id. */
export interface MediaQueueRow {
    attachment_local_id: string;
    client_submission_uuid: string | null;
    field_key: string;
    blob: Blob;
    name: string;
    mime: string;
    size: number;
    status: MediaStatus;
    attachment_id: string | null;
    attempts: number;
    created_at: string;
}

/**
 * A device-scoped scalar that belongs to no form and must survive a page load (Increment H23b).
 *
 * One key today — `brand_version`, the fingerprint of the tenant ramp the cached guest shells were last
 * refreshed for (see brand-cache.ts). A dedicated store rather than localStorage because the guest
 * runtime's persistence story is Dexie: the G8a localStorage autosave was already migrated here, and a
 * second storage mechanism for one string would be a second thing to reason about when a respondent
 * clears site data.
 */
export interface AppStateRow {
    key: string;
    value: string;
}

export class MeridianDb extends Dexie {
    cached_manifests!: Table<CachedManifest, string>;
    draft_answers!: Table<DraftRow, [string, string]>;
    outbox!: Table<OutboxRow, string>;
    media_queue!: Table<MediaQueueRow, string>;
    app_state!: Table<AppStateRow, string>;

    constructor(name: string = DB_NAME) {
        super(name);
        this.version(1).stores({
            cached_manifests: 'form_version_id',
            draft_answers: '[form_version_id+local_draft_id], form_version_id, updated_at',
            outbox: 'client_submission_uuid, status, slug, form_version_id, created_at',
            media_queue: 'attachment_local_id, client_submission_uuid, status',
        });
        // Increment H23b — a NEW STORE, which is the one change that genuinely requires a version bump.
        // (Contrast `conflict_code` above: an un-indexed field added to an existing row shape needs none,
        // and G8c deliberately did not bump for it.) Dexie carries the v1 stores forward untouched and
        // upgrades in place, so a device mid-fill keeps its drafts, outbox and queued media.
        this.version(2).stores({
            app_state: 'key',
        });
    }
}

/** Open (or create) the offline database. Each context — app, service worker, a test — calls this once. */
export function openDb(name: string = DB_NAME): MeridianDb {
    return new MeridianDb(name);
}

/**
 * A plain, non-reactive deep copy of an answer document for IndexedDB storage. The runtime's answers are Vue
 * reactive proxies, and `structuredClone` (which every IndexedDB write uses) throws `DataCloneError` on a
 * Proxy — so we round-trip through JSON, which reads through the proxy and yields JSON-safe plain values
 * (answers are strings/numbers/arrays/attachment-ref objects; media Blobs live in a separate table).
 */
export function plainClone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}
