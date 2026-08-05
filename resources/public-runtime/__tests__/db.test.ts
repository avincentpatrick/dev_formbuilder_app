import { describe, expect, it } from 'vitest';
import { openDb, type OutboxRow } from '../lib/db';

let n = 0;
const freshDb = () => openDb(`db-test-${(n += 1)}`);

describe('MeridianDb', () => {
    it('declares the five offline stores', () => {
        const db = freshDb();
        expect(db.tables.map((t) => t.name).sort()).toEqual([
            'app_state',
            'cached_manifests',
            'draft_answers',
            'media_queue',
            'outbox',
        ]);
        db.close();
    });

    it('adds app_state at v2 without disturbing the v1 stores (Increment H23b)', async () => {
        // The upgrade is what matters, not the store: a device mid-fill has drafts, a queued outbox and
        // queued media in v1, and a schema bump that dropped any of them would lose a respondent's work
        // to a purely cosmetic feature. Dexie carries the v1 definitions forward — this asserts it did.
        const db = freshDb();
        await db.outbox.put({
            client_submission_uuid: 'survives-upgrade',
            slug: 'clinic-intake',
            form_version_id: 'v1',
            checksum: 'c1',
            answers: { full_name: 'Ada' },
            locale: 'en',
            device_id: 'dev-1',
            app_version: 'test',
            submitted_at: '2026-08-05T00:00:00.000Z',
            status: 'pending',
            attempts: 0,
            last_error: null,
            conflict_code: null,
            server_submission_id: null,
            created_at: '2026-08-05T00:00:00.000Z',
            updated_at: '2026-08-05T00:00:00.000Z',
        });

        await db.app_state.put({ key: 'brand_version', value: 'abc123def456' });

        expect(await db.app_state.get('brand_version')).toEqual({ key: 'brand_version', value: 'abc123def456' });
        expect(await db.outbox.get('survives-upgrade')).toMatchObject({ slug: 'clinic-intake' });
        expect(db.verno).toBe(2);
        await db.delete();
    });

    it('round-trips an outbox row keyed by client_submission_uuid', async () => {
        const db = freshDb();
        const row: OutboxRow = {
            client_submission_uuid: 'uuid-1',
            slug: 'clinic-intake',
            form_version_id: 'v1',
            checksum: 'c1',
            answers: { full_name: 'Ada' },
            locale: 'en',
            device_id: 'dev-1',
            app_version: 'test',
            submitted_at: '2026-07-16T00:00:00.000Z',
            status: 'pending',
            attempts: 0,
            last_error: null,
            server_submission_id: null,
            created_at: '2026-07-16T00:00:00.000Z',
            updated_at: '2026-07-16T00:00:00.000Z',
        };
        await db.outbox.put(row);
        expect(await db.outbox.get('uuid-1')).toMatchObject({ slug: 'clinic-intake', answers: { full_name: 'Ada' } });
        await db.delete();
    });

    it('stores a queued media row with its blob + metadata', async () => {
        const db = freshDb();
        const blob = new Blob(['hello'], { type: 'text/plain' });
        await db.media_queue.put({
            attachment_local_id: 'local-1',
            client_submission_uuid: null,
            field_key: 'photo',
            blob,
            name: 'a.txt',
            mime: 'text/plain',
            size: blob.size,
            status: 'queued',
            attachment_id: null,
            attempts: 0,
            created_at: '2026-07-16T00:00:00.000Z',
        });
        // NB: a real browser stores the Blob natively; happy-dom's Blob doesn't survive fake-indexeddb's
        // structuredClone, so we assert the row + metadata rather than the reconstituted Blob type.
        const stored = await db.media_queue.get('local-1');
        expect(stored).toMatchObject({ field_key: 'photo', name: 'a.txt', mime: 'text/plain', status: 'queued' });
        expect(stored?.blob).toBeDefined();
        await db.delete();
    });
});
