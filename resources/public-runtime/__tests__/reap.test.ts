import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { openDb, type DraftRow, type MediaQueueRow, type MeridianDb } from '../lib/db';
import { enqueue, markSynced } from '../lib/outbox';
import { localMediaRefId } from '../lib/media-queue';
import { MEDIA_ORPHAN_GRACE_MS, reapAbandoned } from '../lib/reap';
import { replayOutbox } from '../lib/replay';
import { createSyncOutbox, type SyncOutbox } from '../composables/useSyncOutbox';

let n = 0;
let db: MeridianDb;
const drivers: SyncOutbox[] = [];

const NOW = Date.parse('2026-08-26T12:00:00.000Z');
const HOUR = 60 * 60 * 1000;
const DAY = 24 * HOUR;
const iso = (ms: number): string => new Date(ms).toISOString();

function draft(over: Partial<DraftRow> = {}): DraftRow {
    return {
        form_version_id: 'v1',
        local_draft_id: 'clinic-intake',
        checksum: 'c1',
        locale: 'en',
        current_step_key: 'step-1',
        answers: { full_name: 'Ada' },
        updated_at: iso(NOW),
        respondent_session_id: 'visit-1',
        ...over,
    };
}

function media(over: Partial<MediaQueueRow> = {}): MediaQueueRow {
    return {
        attachment_local_id: 'l1',
        client_submission_uuid: null,
        field_key: 'photo',
        blob: new Blob(['x'], { type: 'image/png' }),
        name: 'p.png',
        mime: 'image/png',
        size: 1,
        status: 'queued',
        attachment_id: null,
        attempts: 0,
        created_at: iso(NOW),
        ...over,
    };
}

/** An answer document that carries one `local:` media ref, exactly as a mid-fill pick leaves it. */
function withMediaRef(localId: string): DraftRow['answers'] {
    return { photo: [{ id: localMediaRefId(localId), name: 'p.png', mime: 'image/png' }] };
}

beforeEach(() => {
    db = openDb(`reap-test-${(n += 1)}`);
});

afterEach(() => {
    drivers.splice(0).forEach((driver) => driver.dispose());
    db.close();
});

// ── draft_answers: the seven-day TTL, finally reachable ────────────────────────────────────────────────
describe('reapAbandoned — draft_answers', () => {
    /**
     * THE ROW'S SHAPE (b), STATED AS A TEST. A republish moves `form_version_id`, so the session remounts
     * on a NEW primary key and the old row is never written, read or deleted again — including by the TTL,
     * which lives inside `restore()` and only ever fires against the key it just fetched. The reader-held
     * key here is `['v2', slug]`; nothing in the runtime holds `['v1', slug]` any more.
     */
    it('reaps the pre-republish orphan no reader holds a key for', async () => {
        await db.draft_answers.put(draft({ form_version_id: 'v1', updated_at: iso(NOW - 8 * DAY) }));
        await db.draft_answers.put(draft({ form_version_id: 'v2', updated_at: iso(NOW - 1 * DAY) }));

        expect(await reapAbandoned(db, NOW)).toMatchObject({ drafts: 1 });

        expect(await db.draft_answers.get(['v1', 'clinic-intake'])).toBeUndefined();
        expect(await db.draft_answers.get(['v2', 'clinic-intake'])).toMatchObject({ form_version_id: 'v2' });
    });

    /**
     * ⛔ THE ANTI-VACUITY CASE, AND IT IS THE ONE THAT MATTERS MOST ON THIS SURFACE. A sweeper that
     * deleted every row would pass the case above and destroy a respondent's live fill in production. Both
     * bounds are asserted: one second inside the window survives, one second outside it does not.
     */
    it('spares a draft inside the window and takes the one just outside it', async () => {
        await db.draft_answers.put(draft({ form_version_id: 'inside', updated_at: iso(NOW - 7 * DAY + 1000) }));
        await db.draft_answers.put(draft({ form_version_id: 'outside', updated_at: iso(NOW - 7 * DAY - 1000) }));

        expect(await reapAbandoned(db, NOW)).toMatchObject({ drafts: 1 });

        expect(await db.draft_answers.get(['inside', 'clinic-intake'])).toBeDefined();
        expect(await db.draft_answers.get(['outside', 'clinic-intake'])).toBeUndefined();
    });

    /**
     * EXPIRY IS NOT OWNERSHIP, AND THIS PINS THE DIFFERENCE DELIBERATELY. M21 scopes what may be READ to
     * the visit on screen; this sweeper cannot ask that question at all (it runs in the service worker,
     * where `sessionStorage` does not exist) and must not appear to. A foreign row inside the window stays
     * — contained by M21, not collected by this.
     */
    it('ignores whose visit wrote the row, in both directions', async () => {
        await db.draft_answers.put(
            draft({ form_version_id: 'fresh-foreign', respondent_session_id: 'somebody-else', updated_at: iso(NOW) }),
        );
        await db.draft_answers.put(
            draft({ form_version_id: 'old-mine', respondent_session_id: 'visit-1', updated_at: iso(NOW - 9 * DAY) }),
        );

        await reapAbandoned(db, NOW);

        expect(await db.draft_answers.get(['fresh-foreign', 'clinic-intake'])).toBeDefined();
        expect(await db.draft_answers.get(['old-mine', 'clinic-intake'])).toBeUndefined();
    });

    it('reports zero and touches nothing when every draft is fresh', async () => {
        await db.draft_answers.put(draft());

        expect(await reapAbandoned(db, NOW)).toEqual({ drafts: 0, media: 0 });
        expect(await db.draft_answers.count()).toBe(1);
    });
});

// ── media_queue: the orphan no uuid-keyed query can reach ──────────────────────────────────────────────
describe('reapAbandoned — media_queue', () => {
    /**
     * THE ROW'S SHAPE (a). `stash()` writes a null `client_submission_uuid`, and both deleters in
     * `lib/outbox.ts` match on a uuid string — which cannot reach a row IndexedDB never indexed. Abandoned,
     * unreferenced, past the grace: it goes.
     */
    it('reaps an abandoned blob that no answer document names', async () => {
        await db.media_queue.put(media({ created_at: iso(NOW - 2 * HOUR) }));

        expect(await reapAbandoned(db, NOW)).toMatchObject({ media: 1 });
        expect(await db.media_queue.count()).toBe(0);
    });

    /** REACHABILITY IS THE TEST. A blob a live draft still names is live, however old it is. */
    it('spares a blob a draft still names', async () => {
        await db.media_queue.put(media({ attachment_local_id: 'l1', created_at: iso(NOW - 5 * DAY) }));
        await db.draft_answers.put(draft({ answers: withMediaRef('l1'), updated_at: iso(NOW - 1 * DAY) }));

        expect(await reapAbandoned(db, NOW)).toMatchObject({ media: 0 });
        expect(await db.media_queue.get('l1')).toBeDefined();
    });

    /**
     * ⛔ THE DATA-LOSS GUARD, AND THE CASE THAT WOULD BE EASIEST TO OMIT. `attachToSubmission()` links only
     * rows whose uuid is still null (M21), so a ref that failed to link leaves the blob orphaned WHILE the
     * queued submission still carries its `local:` placeholder. `replay.ts` refuses to POST such a row
     * ('queued media is incomplete' → five attempts → `needs_attention`), so reaping this blob would park a
     * real submission forever. The outbox is read precisely to prevent that.
     */
    it('spares an orphaned blob that an UNSENT outbox row still names', async () => {
        await db.media_queue.put(media({ attachment_local_id: 'l1', created_at: iso(NOW - 5 * DAY) }));
        await enqueue(db, {
            client_submission_uuid: 'u1',
            slug: 'clinic-intake',
            form_version_id: 'v1',
            checksum: 'c1',
            answers: withMediaRef('l1'),
            locale: 'en',
            device_id: 'dev',
            app_version: 'test',
            respondent_session_id: 'visit-1',
            base_content_checksum: null,
        });

        expect(await reapAbandoned(db, NOW)).toMatchObject({ media: 0 });
        expect(await db.media_queue.get('l1')).toBeDefined();
    });

    /**
     * THE GRACE WINDOW EXISTS FOR EXACTLY ONE THING: a `local:` ref that is live in memory and not yet in
     * any row this module can read — the autosave debounce, and the conflict-review session, which runs
     * with autosave inert and writes no draft row at all.
     */
    it('spares an unreferenced blob younger than the grace', async () => {
        await db.media_queue.put(media({ created_at: iso(NOW - MEDIA_ORPHAN_GRACE_MS + 1000) }));

        expect(await reapAbandoned(db, NOW)).toMatchObject({ media: 0 });
        expect(await db.media_queue.count()).toBe(1);
    });

    /** A LINKED blob belongs to `markSynced`/`deleteRow`, never to this module — at any age. */
    it('never touches a blob that carries a submission uuid', async () => {
        await db.media_queue.put(
            media({ attachment_local_id: 'linked', client_submission_uuid: 'u-gone', created_at: iso(NOW - 90 * DAY) }),
        );

        expect(await reapAbandoned(db, NOW)).toMatchObject({ media: 0 });
        expect(await db.media_queue.get('linked')).toBeDefined();
    });

    /**
     * ⚠️ THE ORDER OF THE TWO SWEEPS, PROVEN RATHER THAN ASSERTED IN A COMMENT. The blob's only reference
     * is a draft that expires in this same pass. Drafts are swept first, so the blob is unreferenced by the
     * time media is considered and both go together. Sweeping media first would spare it — and on a device
     * somebody walked away from, nothing calls this again.
     */
    it('reaps a blob whose only reference was an expired draft, in the same pass', async () => {
        await db.media_queue.put(media({ attachment_local_id: 'l1', created_at: iso(NOW - 8 * DAY) }));
        await db.draft_answers.put(draft({ answers: withMediaRef('l1'), updated_at: iso(NOW - 8 * DAY) }));

        expect(await reapAbandoned(db, NOW)).toEqual({ drafts: 1, media: 1 });
        expect(await db.media_queue.count()).toBe(0);
    });

    it('reaps an unreferenced blob whose created_at is unparseable', async () => {
        await db.media_queue.put(media({ created_at: 'not-a-date' }));

        expect(await reapAbandoned(db, NOW)).toMatchObject({ media: 1 });
        expect(await db.media_queue.count()).toBe(0);
    });

    /**
     * A DELIVERED ROW'S ANSWERS ARE SCRUBBED AND ITS MEDIA DELETED IN ONE TRANSACTION (I10d), so `synced`
     * is excluded from the mark set. This pins that the exclusion is safe rather than merely cheap: after a
     * sync there is nothing left in that row to name a blob, and nothing left of the blob to name.
     */
    it('leaves the outbox itself untouched', async () => {
        await enqueue(db, {
            client_submission_uuid: 'u1',
            slug: 'clinic-intake',
            form_version_id: 'v1',
            checksum: 'c1',
            answers: { a: '1' },
            locale: 'en',
            device_id: 'dev',
            app_version: 'test',
            respondent_session_id: 'visit-1',
            base_content_checksum: null,
        });
        await markSynced(db, 'u1', 'srv-1', 'REF-1');

        await reapAbandoned(db, NOW);

        expect(await db.outbox.get('u1')).toMatchObject({ status: 'synced', server_reference: 'REF-1' });
    });
});

/**
 * ⛔⛔ A UNIT TEST PROVES WHAT A FUNCTION DOES WHEN CALLED; ONLY THESE PROVE THAT IT IS CALLED.
 *
 * That is M21's own finding, and it was expensive: M15 shipped a docblock claiming
 * `respondentSession()` refreshed its stamp "on every read", pinned the claim in a green unit test, and
 * ran for a whole increment while the runtime made that read exactly once, at boot. The test was correct
 * about the function and said nothing about the system. So the two call sites this increment adds are
 * asserted through their own entry points, against real time rather than an injected clock — because an
 * injected clock is the one thing the production callers do not have.
 */
describe('the call sites — reaping is wired, not merely available', () => {
    function fakeEnv() {
        const listeners: Record<string, Array<() => void>> = {};
        const add = (t: string, h: () => void): void => void (listeners[t] ??= []).push(h);
        const remove = (t: string, h: () => void): void =>
            void (listeners[t] = (listeners[t] ?? []).filter((x) => x !== h));
        return {
            nav: { onLine: false, storage: undefined } as unknown as Navigator,
            win: { addEventListener: add, removeEventListener: remove } as unknown as Window,
            doc: { visibilityState: 'hidden', addEventListener: add, removeEventListener: remove } as unknown as Document,
        };
    }

    /** Real elapsed time, so nothing here can pass because a clock was handed to it. */
    async function seedAbandoned(): Promise<void> {
        const realNow = Date.now();
        await db.draft_answers.put(draft({ form_version_id: 'stranded', updated_at: iso(realNow - 8 * DAY) }));
        await db.media_queue.put(media({ attachment_local_id: 'orphan', created_at: iso(realNow - 2 * HOUR) }));
    }

    it('the app driver reaps on refresh — the pass that runs at boot', async () => {
        await seedAbandoned();
        const env = fakeEnv();
        const driver = createSyncOutbox(db, {
            slug: 'clinic-intake',
            sessionId: 'visit-1',
            fetch: vi.fn() as unknown as typeof fetch,
            navigator: env.nav,
            window: env.win,
            document: env.doc,
        });
        drivers.push(driver);

        await driver.refresh();

        expect(await db.draft_answers.get(['stranded', 'clinic-intake'])).toBeUndefined();
        expect(await db.media_queue.get('orphan')).toBeUndefined();
    });

    /**
     * The service-worker path. `replayOutbox` is what `sw.ts` calls on a Background-Sync `sync` event, and
     * an EMPTY outbox is the right fixture: it proves the sweep is not hiding inside the per-row loop, and
     * it needs no network at all.
     */
    it('a replay pass reaps, which is the only path a device with no tab open has', async () => {
        await seedAbandoned();

        await replayOutbox(db, vi.fn() as unknown as typeof fetch);

        expect(await db.draft_answers.get(['stranded', 'clinic-intake'])).toBeUndefined();
        expect(await db.media_queue.get('orphan')).toBeUndefined();
    });
});
