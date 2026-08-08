/**
 * The shared offline-replay engine (Increment G8b). One framework-free function drains the Dexie `outbox`,
 * imported by BOTH the Vue app driver (`useSyncOutbox`) and the service worker (`sw.ts` on a Background-Sync
 * `sync` event) — so a queued submission replays whether or not a tab is open. It reuses the stateful
 * `createApiClient` (token mint + remint + error normalization), so replay speaks exactly the same guest
 * protocol as a live submit; idempotency (`client_submission_uuid`) makes a duplicated replay a 200 no-op.
 *
 * Per row: mint a fresh token → CHECK THE VERSION (Increment H21b) → upload any queued media first (rewriting
 * `local:` placeholder refs to real attachment ids) → POST the submission → map the outcome. Structured answers
 * are NEVER dropped: a rejected payload (422) or 5 exhausted network retries become `needs_attention` (a human
 * decides), a version conflict (409) becomes `conflict` (the G8c UX resolves it), and a transient failure stays
 * `pending` for the next pass.
 */

import { createApiClient } from './api-client';
import { ApiError, normalizeError } from './error-normalizer';
import type { MeridianDb, MediaQueueRow, OutboxRow } from './db';
import type { SchemaResponse } from './types';
import { listPending, markConflict, markNeedsAttention, markSynced, pruneSynced, recordAttempt, setAnswers } from './outbox';
import {
    collectLocalMediaIds,
    listForSubmission,
    markUploaded,
    recordMediaAttempt,
    rewriteLocalMediaIds,
} from './media-queue';

const MAX_ATTEMPTS = 5;

export interface ReplayResult {
    synced: number;
    conflict: number;
    needsAttention: number;
    retry: number;
}

export type RowOutcome = keyof ReplayResult;

const EMPTY: ReplayResult = { synced: 0, conflict: 0, needsAttention: 0, retry: 0 };

/**
 * Per-row progress callbacks (Increment I10d), so the UI can show "Sending…" on the ONE row being sent.
 *
 * Hooks rather than shared state, because this module is deliberately framework-free and injectable — that
 * is the property the whole file is built around, and it is what lets `sw.ts` call the same function with no
 * Vue anywhere. `useSyncOutbox` passes hooks; `sw.ts` passes none and is untouched.
 */
export interface ReplayHooks {
    onRowStart?(uuid: string): void;
    onRowSettled?(uuid: string, outcome: RowOutcome): void;
}

/** A per-context guard so overlapping triggers (online + visibility) don't run concurrent passes in one context. */
let inFlight: Promise<ReplayResult> | null = null;

/**
 * Rows currently being sent, ACROSS passes (I10d).
 *
 * `inFlight` above guards a whole pass; this guards a row. They are not the same thing now that
 * `replayOne()` exists: a per-item "Retry now" can fire while a full drain is already walking the queue, and
 * two concurrent `replayRow()` calls on one uuid race the media leg — it reads `status === 'uploaded'` and
 * then writes it, so the same blob can be uploaded twice and the second id wins. Server-side idempotency
 * makes the double POST harmless; it does not make the double upload harmless.
 */
const rowsInFlight = new Set<string>();

/** Drain every `pending` outbox row once, oldest-first. Concurrent calls in the same context share one pass. */
export function replayOutbox(db: MeridianDb, fetchFn: typeof fetch = fetch, hooks: ReplayHooks = {}): Promise<ReplayResult> {
    if (inFlight !== null) {
        // NOTE: the shared promise means a second caller's hooks are dropped. Only useSyncOutbox passes
        // hooks and it has its own `running` flag, so this cannot bite today — but it is a property, not an
        // accident, and a second hook-passing caller would need this revisited.
        return inFlight;
    }
    inFlight = run(db, fetchFn, hooks).finally(() => {
        inFlight = null;
    });
    return inFlight;
}

/**
 * Replay exactly ONE row (Increment I10d) — the per-item "Retry now" of UX spec §7.3.
 *
 * Returns null when the row is gone, is not pending, or is already being sent by another pass.
 */
export async function replayOne(
    db: MeridianDb,
    uuid: string,
    fetchFn: typeof fetch = fetch,
    hooks: ReplayHooks = {},
): Promise<RowOutcome | null> {
    const row = await db.outbox.get(uuid);

    if (row === undefined || row.status !== 'pending' || rowsInFlight.has(uuid)) {
        return null;
    }

    const outcome = await sendRow(db, fetchFn, row, new Map(), hooks);
    await pruneSynced(db);

    return outcome;
}

async function run(db: MeridianDb, fetchFn: typeof fetch, hooks: ReplayHooks): Promise<ReplayResult> {
    const rows = await listPending(db);
    const result: ReplayResult = { ...EMPTY };
    // Per-PASS, not module-level, so a republish between two drains is always seen. Several queued rows for
    // one form therefore cost one schema read, not one each.
    const schemas = new Map<string, SchemaResponse>();
    for (const row of rows) {
        const outcome = await sendRow(db, fetchFn, row, schemas, hooks);
        if (outcome !== null) {
            result[outcome] += 1;
        }
    }

    // Prune HERE and not only in the composable: sw.ts calls replayOutbox() directly with no tab open, so a
    // device that only ever syncs through Background Sync would otherwise accumulate receipts forever.
    await pruneSynced(db);

    return result;
}

/** `replayRow` plus the in-flight guard and the progress hooks. Returns null if the row is already in flight. */
async function sendRow(
    db: MeridianDb,
    fetchFn: typeof fetch,
    row: OutboxRow,
    schemas: Map<string, SchemaResponse>,
    hooks: ReplayHooks,
): Promise<RowOutcome | null> {
    const uuid = row.client_submission_uuid;

    if (rowsInFlight.has(uuid)) {
        return null;
    }

    rowsInFlight.add(uuid);
    hooks.onRowStart?.(uuid);

    let outcome: RowOutcome | null = null;

    try {
        outcome = await replayRow(db, fetchFn, row, schemas);

        return outcome;
    } finally {
        // BOTH in the `finally`, and the second one is the whole point. `rowsInFlight` is this module's own
        // bookkeeping; the thing the RESPONDENT sees is `syncingUuids` in useSyncOutbox, which is driven by
        // `onRowSettled`. Leaving that inside the `try` meant a throw out of replayRow — a Dexie failure, a
        // fetch that rejects rather than resolving, anything not caught as an ApiError — left the row
        // rendering "Sending…" forever with every action hidden behind `isSyncing`. `retry` is the honest
        // outcome for an unfinished attempt: the row is still pending and the next pass will take it.
        rowsInFlight.delete(uuid);
        hooks.onRowSettled?.(uuid, outcome ?? 'retry');
    }
}

async function replayRow(
    db: MeridianDb,
    fetchFn: typeof fetch,
    row: OutboxRow,
    schemas: Map<string, SchemaResponse>,
): Promise<RowOutcome> {
    const uuid = row.client_submission_uuid;
    const client = createApiClient({ token: '', slug: row.slug, fetch: fetchFn });

    // 1. Mint a fresh token — this also confirms the form is still live/guest-enabled.
    try {
        await client.remint();
    } catch (error) {
        if (error instanceof ApiError && error.normalized.kind === 'terminal') {
            await markNeedsAttention(db, uuid, error.normalized.message);
            return 'needsAttention';
        }
        return backoff(db, uuid, error);
    }

    // 2. Increment H21b, Doc #27 §5.4 — the guard that was already sitting unused in the row.
    //
    // The row records the `form_version_id` its answers were captured against, and until now replay read it
    // never. Step 1 above re-mints, and a fresh token pins the form's CURRENT published version, so the
    // server's own guard (`GuestSubmissionController` — `current_published_version_id !== $token->formVersionId`)
    // compares that column against itself and can never fire. Answers pruned client-side against version N were
    // then validated and persisted against N+1's relevance graph: if N+1 makes a branch irrelevant the server
    // silently prunes those answers, returns 201, and the row is marked `synced` — unattributable data loss
    // behind a green sync badge. Branching makes republishing the graph the routine authoring act.
    //
    // Checked BEFORE the media leg on purpose: `GuestAttachmentController` carries the identical guard against
    // the same fresh token, so uploading first would land the blobs under N+1 too.
    //
    // `row.checksum` stays deliberately unread. The server compares the version ID, and matching the server's
    // rule exactly is the point of the guard — a checksum comparison here would park rows the server accepts.
    //
    // NARROWING, recorded: a row queued by a RESUMED draft session carries its pinned version, and the server's
    // draft path skips its own version guard (that path is version-safe by construction, since `promote()`
    // re-asserts the draft's version). Such a row now parks for review where it would have promoted. It fails
    // safe and costs one review click, and the alternative — a `from_draft` flag on `OutboxRow` read by exactly
    // one branch — buys back less than it complicates.
    let current: SchemaResponse;
    try {
        const cached = schemas.get(row.slug);
        current = cached ?? (await client.fetchSchema());
        schemas.set(row.slug, current);
    } catch (error) {
        if (error instanceof ApiError && error.normalized.kind === 'terminal') {
            await markNeedsAttention(db, uuid, error.normalized.message);
            return 'needsAttention';
        }
        return backoff(db, uuid, error);
    }

    if (row.form_version_id !== current.version.id) {
        // The same landing point a live 409 uses, so the G8c review-and-resubmit UX picks it up unchanged:
        // `SyncStatus`'s Review CTA → `beginConflictReview()` → the drift notice keyed off `conflict_code`.
        await markConflict(db, uuid, 'This form has been updated. Please reload and try again.', 'form_updated');
        return 'conflict';
    }

    // 3. Upload queued media first, mapping each local id → its real attachment id, then rewrite the answers.
    let answers = row.answers;
    if (collectLocalMediaIds(answers).length > 0) {
        const mediaRows = await listForSubmission(db, uuid);
        const mapping: Record<string, string> = {};
        for (const media of mediaRows) {
            if (media.status === 'uploaded' && media.attachment_id !== null) {
                mapping[media.attachment_local_id] = media.attachment_id;
                continue;
            }
            try {
                const attachmentId = await uploadMedia(fetchFn, client, media);
                await markUploaded(db, media.attachment_local_id, attachmentId);
                mapping[media.attachment_local_id] = attachmentId;
            } catch (error) {
                // A rejected file (bad type/size → 422, or the form is gone → terminal) needs a human; a
                // transient failure just waits for the next pass.
                if (error instanceof ApiError && (error.normalized.kind === 'field' || error.normalized.kind === 'terminal')) {
                    await markNeedsAttention(db, uuid, error.normalized.message);
                    return 'needsAttention';
                }
                await recordMediaAttempt(db, media.attachment_local_id);
                return backoff(db, uuid, error);
            }
        }
        answers = rewriteLocalMediaIds(answers, mapping);
        if (collectLocalMediaIds(answers).length > 0) {
            // Not every placeholder resolved (a media row is missing) — do not POST a submission with a dead ref.
            return backoff(db, uuid, new Error('queued media is incomplete'));
        }
        await setAnswers(db, uuid, answers);
    }

    // 4. POST the submission (idempotent by client_submission_uuid).
    try {
        const result = await client.submit({
            answers,
            clientSubmissionUuid: uuid,
            locale: row.locale,
            deviceId: row.device_id,
            appVersion: row.app_version,
        });
        // I10d — the server id is recorded on the retained row. Note the LIST still derives its reference
        // from the client uuid, deliberately: this id is for support to resolve, not for display.
        await markSynced(db, uuid, result.id);
        return 'synced';
    } catch (error) {
        if (error instanceof ApiError) {
            const kind = error.normalized.kind;
            if (kind === 'field') {
                await markNeedsAttention(db, uuid, error.normalized.message);
                return 'needsAttention';
            }
            if (kind === 'refresh') {
                await markConflict(db, uuid, error.normalized.message, error.normalized.code);
                return 'conflict';
            }
            if (kind === 'terminal') {
                await markNeedsAttention(db, uuid, error.normalized.message);
                return 'needsAttention';
            }
        }
        // rate_limited / unknown / a thrown network error — retry on the next pass.
        return backoff(db, uuid, error);
    }
}

/** Upload one queued blob to the guest attachments endpoint via fetch+FormData (works in the app and the SW). */
async function uploadMedia(fetchFn: typeof fetch, client: ReturnType<typeof createApiClient>, media: MediaQueueRow): Promise<string> {
    const send = (token: string): Promise<Response> => {
        const form = new FormData();
        form.append('file', media.blob, media.name);
        form.append('field_key', media.field_key);
        return fetchFn(`/api/v1/public/f/${encodeURIComponent(token)}/attachments`, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: form,
        });
    };

    let response = await send(client.token());
    if (response.status === 401) {
        await client.remint();
        response = await send(client.token());
    }
    const body = (await response.json().catch(() => null)) as { data?: { id?: string } } | null;
    if (!response.ok || typeof body?.data?.id !== 'string') {
        throw new ApiError(normalizeError(response.status, body, response.headers.get('Retry-After')));
    }
    return body.data.id;
}

/** Record a transient failure, keeping the row `pending`; escalate to `needs_attention` after 5 attempts. */
async function backoff(db: MeridianDb, uuid: string, error: unknown): Promise<RowOutcome> {
    const message = error instanceof Error ? error.message : String(error);
    const attempts = await recordAttempt(db, uuid, message);
    if (attempts >= MAX_ATTEMPTS) {
        await markNeedsAttention(db, uuid, message);
        return 'needsAttention';
    }
    return 'retry';
}
