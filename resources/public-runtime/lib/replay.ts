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
import { listPending, markConflict, markNeedsAttention, markSynced, recordAttempt, setAnswers } from './outbox';
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

type RowOutcome = keyof ReplayResult;

const EMPTY: ReplayResult = { synced: 0, conflict: 0, needsAttention: 0, retry: 0 };

/** A per-context guard so overlapping triggers (online + visibility) don't run concurrent passes in one context. */
let inFlight: Promise<ReplayResult> | null = null;

/** Drain every `pending` outbox row once, oldest-first. Concurrent calls in the same context share one pass. */
export function replayOutbox(db: MeridianDb, fetchFn: typeof fetch = fetch): Promise<ReplayResult> {
    if (inFlight !== null) {
        return inFlight;
    }
    inFlight = run(db, fetchFn).finally(() => {
        inFlight = null;
    });
    return inFlight;
}

async function run(db: MeridianDb, fetchFn: typeof fetch): Promise<ReplayResult> {
    const rows = await listPending(db);
    const result: ReplayResult = { ...EMPTY };
    // Per-PASS, not module-level, so a republish between two drains is always seen. Several queued rows for
    // one form therefore cost one schema read, not one each.
    const schemas = new Map<string, SchemaResponse>();
    for (const row of rows) {
        const outcome = await replayRow(db, fetchFn, row, schemas);
        result[outcome] += 1;
    }
    return result;
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
        await client.submit({
            answers,
            clientSubmissionUuid: uuid,
            locale: row.locale,
            deviceId: row.device_id,
            appVersion: row.app_version,
        });
        await markSynced(db, uuid);
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
