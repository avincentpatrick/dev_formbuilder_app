/**
 * The Dexie `media_queue` store (Increment G8b) — respondent media picked while offline. Each blob is stashed
 * with a placeholder id (`local:<attachment_local_id>`) that rides the answer document exactly like a real
 * AttachmentRef; at replay the blob is uploaded to obtain a real attachment id and the answer is rewritten.
 * Pure, db-injected; media-ref helpers walk only TOP-LEVEL media answers (media is banned inside repeat groups).
 */

import type { AnswerMap, MediaAnswer } from './types';
import type { MeridianDb, MediaQueueRow } from './db';

export const LOCAL_MEDIA_PREFIX = 'local:';

export function localMediaRefId(attachmentLocalId: string): string {
    return `${LOCAL_MEDIA_PREFIX}${attachmentLocalId}`;
}

export function isLocalMediaId(id: unknown): id is string {
    return typeof id === 'string' && id.startsWith(LOCAL_MEDIA_PREFIX);
}

export interface StashInput {
    attachment_local_id: string;
    field_key: string;
    blob: Blob;
    name: string;
    mime: string;
    size: number;
}

/**
 * Stash a picked file offline; the parent submission uuid is linked later, at finalize.
 *
 * ⚠️ `client_submission_uuid: null` IS THE WHOLE OF INCREMENT M22's SUBJECT, AND IT IS CORRECT HERE. The
 * submission does not exist yet, so there is nothing honest to write. What was missing is what happens when
 * the fill is ABANDONED and finalize never runs: this row's null puts it outside the
 * `client_submission_uuid` index — IndexedDB does not index null — so neither of `lib/outbox.ts`'s two
 * uuid-keyed deleters could ever reach it, and a respondent's photo, signature or ID scan stayed on shared
 * hardware until the browser evicted the origin. `lib/reap.ts` collects it by REACHABILITY instead: once no
 * answer document still carries its `local:` ref, nothing can use it and it goes.
 */
export async function stash(db: MeridianDb, input: StashInput): Promise<MediaQueueRow> {
    const row: MediaQueueRow = {
        ...input,
        client_submission_uuid: null,
        status: 'queued',
        attachment_id: null,
        attempts: 0,
        created_at: new Date().toISOString(),
    };
    await db.media_queue.put(row);
    return row;
}

/**
 * Link every still-unassigned local blob referenced by a finalized submission to its outbox row.
 *
 * ⛔ INCREMENT M21 — "STILL-UNASSIGNED" IS NOW ENFORCED RATHER THAN ASSERTED. This docblock has claimed it
 * since G8b and the `.modify()` never filtered on it, so any `local:` ref already owned by another outbox
 * row was silently RE-POINTED to this one. That is not theoretical: an abandoned draft restored into the
 * next respondent's form carried the previous respondent's `local:` refs, `collectLocalMediaIds()` found
 * them, and `replay.ts` uploaded that person's photo or signature as THIS submission's attachment. M21
 * closes the restore that fed it; this narrows the write itself, because a claim a docblock makes and the
 * code does not keep is the failure this project has now recorded five times.
 */
export async function attachToSubmission(db: MeridianDb, attachmentLocalIds: string[], uuid: string): Promise<void> {
    if (attachmentLocalIds.length === 0) {
        return;
    }
    await db.media_queue
        .where('attachment_local_id')
        .anyOf(attachmentLocalIds)
        .filter((row) => row.client_submission_uuid === null)
        .modify({ client_submission_uuid: uuid });
}

export function listForSubmission(db: MeridianDb, uuid: string): Promise<MediaQueueRow[]> {
    return db.media_queue.where('client_submission_uuid').equals(uuid).toArray();
}

export function markUploaded(db: MeridianDb, attachmentLocalId: string, attachmentId: string): Promise<number> {
    return db.media_queue.update(attachmentLocalId, { status: 'uploaded', attachment_id: attachmentId });
}

export async function recordMediaAttempt(db: MeridianDb, attachmentLocalId: string): Promise<number> {
    const row = await db.media_queue.get(attachmentLocalId);
    const attempts = (row?.attempts ?? 0) + 1;
    await db.media_queue.update(attachmentLocalId, { attempts });
    return attempts;
}

// ── Answer-document media-ref helpers ────────────────────────────────────────────────────────

/** Is this answer value a media answer (a list of AttachmentRef-shaped objects)? */
function asMediaAnswer(value: unknown): MediaAnswer | null {
    if (!Array.isArray(value)) {
        return null;
    }
    const looksLikeRef = value.every(
        (entry) => entry !== null && typeof entry === 'object' && typeof (entry as { id?: unknown }).id === 'string',
    );
    return looksLikeRef ? (value as MediaAnswer) : null;
}

/** The attachment_local_ids of every `local:` placeholder in a finalized answer map (top-level media only). */
export function collectLocalMediaIds(answers: AnswerMap): string[] {
    const ids: string[] = [];
    for (const value of Object.values(answers)) {
        const media = asMediaAnswer(value);
        if (media === null) {
            continue;
        }
        for (const ref of media) {
            if (isLocalMediaId(ref.id)) {
                ids.push(ref.id.slice(LOCAL_MEDIA_PREFIX.length));
            }
        }
    }
    return ids;
}

/**
 * Return a copy of `answers` with each `local:<localId>` media ref replaced by its uploaded attachment id
 * (dropping the transient `pending` flag). Refs without a mapping are left as-is (their upload has not resolved
 * yet, so the caller must not POST the submission until every local ref maps).
 */
export function rewriteLocalMediaIds(answers: AnswerMap, mapping: Record<string, string>): AnswerMap {
    const out: AnswerMap = {};
    for (const [key, value] of Object.entries(answers)) {
        const media = asMediaAnswer(value);
        if (media === null) {
            out[key] = value;
            continue;
        }
        out[key] = media.map((ref) => {
            if (!isLocalMediaId(ref.id)) {
                return ref;
            }
            const realId = mapping[ref.id.slice(LOCAL_MEDIA_PREFIX.length)];
            if (realId === undefined) {
                return ref;
            }
            const { pending: _pending, ...rest } = ref as MediaAnswer[number] & { pending?: boolean };
            return { ...rest, id: realId };
        });
    }
    return out;
}
