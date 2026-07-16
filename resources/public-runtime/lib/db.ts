/**
 * The guest runtime's IndexedDB source of truth (Increment G8b), via Dexie. Four stores back the offline
 * engine (docs/offline-first-sync-design.md §3):
 *  - `cached_manifests` — a pinned form version's schema, keyed by form_version_id (forward-compatible with the
 *    authenticated sync/manifest surface; the guest schema itself is still served by the G8a SW schema cache).
 *  - `draft_answers`    — in-progress, not-yet-finalized answers (the G8a localStorage autosave migrates here).
 *  - `outbox`           — FINALIZED submissions queued for replay, keyed by the time-sortable client_submission_uuid.
 *  - `media_queue`      — respondent media blobs awaiting upload, referencing their parent outbox row by uuid.
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
    submitted_at: string;
    status: OutboxStatus;
    attempts: number;
    last_error: string | null;
    /** Increment G8c — which 409 parked a `conflict` row: `form_updated`/`submission_version_superseded` (the
     *  form changed) vs `submission_conflict` (a server-side copy already exists), so the resolve notice is accurate.
     *  Not indexed → no `db.version()` bump. */
    conflict_code: string | null;
    server_submission_id: string | null;
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

export class MeridianDb extends Dexie {
    cached_manifests!: Table<CachedManifest, string>;
    draft_answers!: Table<DraftRow, [string, string]>;
    outbox!: Table<OutboxRow, string>;
    media_queue!: Table<MediaQueueRow, string>;

    constructor(name: string = DB_NAME) {
        super(name);
        this.version(1).stores({
            cached_manifests: 'form_version_id',
            draft_answers: '[form_version_id+local_draft_id], form_version_id, updated_at',
            outbox: 'client_submission_uuid, status, slug, form_version_id, created_at',
            media_queue: 'attachment_local_id, client_submission_uuid, status',
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
