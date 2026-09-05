import { beforeEach, describe, expect, it } from 'vitest';
import { openDb, type MeridianDb } from '../lib/db';
import {
    attachToSubmission,
    collectLocalMediaIds,
    isLocalMediaId,
    listForSubmission,
    localMediaRefId,
    markUploaded,
    repointToSubmission,
    rewriteLocalMediaIds,
    stash,
    type StashInput,
} from '../lib/media-queue';

let n = 0;
let db: MeridianDb;

function stashInput(localId: string, over: Partial<StashInput> = {}): StashInput {
    return {
        attachment_local_id: localId,
        field_key: 'photo',
        blob: new Blob(['x'], { type: 'image/png' }),
        name: 'p.png',
        mime: 'image/png',
        size: 1,
        ...over,
    };
}

beforeEach(() => {
    db = openDb(`media-test-${(n += 1)}`);
});

describe('media-queue helpers', () => {
    it('local ref helpers round-trip', () => {
        const ref = localMediaRefId('abc');
        expect(ref).toBe('local:abc');
        expect(isLocalMediaId(ref)).toBe(true);
        expect(isLocalMediaId('att-123')).toBe(false);
    });

    it('stashes a blob as queued, then links it to a submission', async () => {
        await stash(db, stashInput('l1'));
        expect(await listForSubmission(db, 'u1')).toHaveLength(0); // not yet linked
        await attachToSubmission(db, ['l1'], 'u1');
        const linked = await listForSubmission(db, 'u1');
        expect(linked).toHaveLength(1);
        expect(linked[0]).toMatchObject({ status: 'queued', client_submission_uuid: 'u1' });
    });

    it('never RE-POINTS a blob that already belongs to another submission (Increment M21)', async () => {
        // The docblock has claimed "still-unassigned" since G8b and the `.modify()` never filtered on it.
        // Reached for real through the abandoned-draft restore M21 closes: the previous respondent's
        // `local:` refs rode their draft into the next respondent's answers, `collectLocalMediaIds()` found
        // them, and their photo or signature was uploaded as THIS submission's attachment.
        await stash(db, stashInput('l1'));
        await attachToSubmission(db, ['l1'], 'u1');

        await attachToSubmission(db, ['l1'], 'u2');

        expect(await listForSubmission(db, 'u2')).toHaveLength(0);
        expect(await listForSubmission(db, 'u1')).toHaveLength(1);
    });

    it('collects local ids from top-level media answers only', () => {
        const answers = {
            full_name: 'Ada', // scalar — ignored
            colours: ['red', 'blue'], // multi-select scalars — ignored
            photo: [{ id: localMediaRefId('l1'), name: 'a' }],
            doc: [{ id: 'att-real' }, { id: localMediaRefId('l2') }],
        };
        expect(collectLocalMediaIds(answers).sort()).toEqual(['l1', 'l2']);
    });

    it('rewrites local ids to real attachment ids and drops the pending flag', () => {
        const answers = {
            photo: [{ id: localMediaRefId('l1'), name: 'a', pending: true }],
            doc: [{ id: 'att-real' }],
        };
        const rewritten = rewriteLocalMediaIds(answers, { l1: 'att-uploaded' });
        expect(rewritten.photo).toEqual([{ id: 'att-uploaded', name: 'a' }]);
        expect(rewritten.doc).toEqual([{ id: 'att-real' }]);
    });

    it('leaves an unmapped local ref untouched (so the caller can defer the POST)', () => {
        const answers = { photo: [{ id: localMediaRefId('l1') }] };
        const rewritten = rewriteLocalMediaIds(answers, {});
        expect(collectLocalMediaIds(rewritten)).toEqual(['l1']);
    });

    it('markUploaded flips status + records the real id', async () => {
        await stash(db, stashInput('l1'));
        await markUploaded(db, 'l1', 'att-9');
        expect(await db.media_queue.get('l1')).toMatchObject({ status: 'uploaded', attachment_id: 'att-9' });
    });
});

describe('repointToSubmission (M72)', () => {
    it("moves the source row's blobs and refuses to touch anybody else's", async () => {
        // ⛔ THE FILTER IS THE POINT, NOT THE MOVE. `attachToSubmission` claims only UNOWNED rows because
        // M21 narrowed it after an abandoned draft's refs were silently re-pointed and a stranger's photo
        // uploaded as somebody else's attachment. This helper moves OWNED rows, so it has to prove it moves
        // only the ones the caller names — otherwise it is that same defect under a new name.
        await stash(db, stashInput('mine'));
        await stash(db, stashInput('theirs'));
        await stash(db, stashInput('unowned'));
        await attachToSubmission(db, ['mine'], 'review-uuid');
        await attachToSubmission(db, ['theirs'], 'stranger-uuid');

        await repointToSubmission(db, ['mine', 'theirs', 'unowned'], 'review-uuid', 'parked-uuid');

        expect((await listForSubmission(db, 'parked-uuid')).map((r) => r.attachment_local_id)).toEqual(['mine']);
        expect((await listForSubmission(db, 'stranger-uuid')).map((r) => r.attachment_local_id)).toEqual(['theirs']);
        expect(await listForSubmission(db, 'review-uuid')).toEqual([]);
        // The unowned row stays unowned: this helper is not a second way to claim one.
        expect((await db.media_queue.get('unowned'))?.client_submission_uuid).toBeNull();
    });

    it('is a no-op on an empty list and on a self-move', async () => {
        // A self-move is what a caller passes when the review never minted a distinct uuid. Rewriting a row
        // to the value it already holds is harmless, but returning early makes that a stated property
        // rather than an accident of Dexie semantics.
        await stash(db, stashInput('solo'));
        await attachToSubmission(db, ['solo'], 'same-uuid');

        await repointToSubmission(db, [], 'same-uuid', 'other-uuid');
        expect((await db.media_queue.get('solo'))?.client_submission_uuid).toBe('same-uuid');

        await repointToSubmission(db, ['solo'], 'same-uuid', 'same-uuid');
        expect((await db.media_queue.get('solo'))?.client_submission_uuid).toBe('same-uuid');
    });
});
