import { describe, expect, it } from 'vitest';
import { openDb, type OutboxRow } from '../lib/db';

let n = 0;
const freshDb = () => openDb(`db-test-${(n += 1)}`);

describe('MeridianDb', () => {
    it('declares the four offline stores', () => {
        const db = freshDb();
        expect(db.tables.map((t) => t.name).sort()).toEqual([
            'cached_manifests',
            'draft_answers',
            'media_queue',
            'outbox',
        ]);
        db.close();
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
