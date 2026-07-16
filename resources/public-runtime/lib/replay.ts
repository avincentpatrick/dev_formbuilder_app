/**
 * The shared offline-replay engine (Increment G8b). One framework-free function drains the Dexie `outbox`,
 * imported by BOTH the Vue app driver (`useSyncOutbox`) and the service worker (`sw.ts` on a Background-Sync
 * `sync` event) — so a queued submission replays whether or not a tab is open. It reuses the stateful
 * `createApiClient` (token mint + remint + error normalization), so replay speaks exactly the same guest
 * protocol as a live submit; idempotency (`client_submission_uuid`) makes a duplicated replay a 200 no-op.
 *
 * Per row: mint a fresh token → upload any queued media first (rewriting `local:` placeholder refs to real
 * attachment ids) → POST the submission → map the outcome. Structured answers are NEVER dropped: a rejected
 * payload (422) or 5 exhausted network retries become `needs_attention` (a human decides), a version conflict
 * (409) becomes `conflict` (the G8c UX resolves it), and a transient failure stays `pending` for the next pass.
 */

import { createApiClient } from './api-client';
import { ApiError, normalizeError } from './error-normalizer';
import type { MeridianDb, MediaQueueRow, OutboxRow } from './db';
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
    for (const row of rows) {
        const outcome = await replayRow(db, fetchFn, row);
        result[outcome] += 1;
    }
    return result;
}

async function replayRow(db: MeridianDb, fetchFn: typeof fetch, row: OutboxRow): Promise<RowOutcome> {
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

    // 2. Upload queued media first, mapping each local id → its real attachment id, then rewrite the answers.
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

    // 3. POST the submission (idempotent by client_submission_uuid).
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
