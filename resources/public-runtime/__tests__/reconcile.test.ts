import { describe, expect, it } from 'vitest';
import { reconcileDraft, type LocalDraft } from '../lib/reconcile';
import type { ResumeDraftResult } from '../lib/types';

function server(overrides: Partial<ResumeDraftResult> = {}): ResumeDraftResult {
    return {
        id: 'sub-1',
        completenessPercent: 50,
        clientSubmissionUuid: 'uuid-server',
        formVersionId: 'ver-1',
        answers: { name: 'Server' },
        lastSavedAt: '2026-07-23T10:00:00Z',
        draftCurrentStep: 'sec-server',
        locale: 'en',
        shareToken: 'share',
        shareTokenExpiresAt: 'later',
        ...overrides,
    };
}

function local(overrides: Partial<LocalDraft> = {}): LocalDraft {
    return {
        checksum: 'chk-1',
        locale: 'es',
        currentStepKey: 'sec-local',
        answers: { name: 'Local' },
        updatedAt: '2026-07-23T09:00:00Z',
        ...overrides,
    };
}

describe('reconcileDraft', () => {
    it('server wins when there is no local draft (fresh device), with no note', () => {
        const r = reconcileDraft(server(), 'chk-1', undefined);
        expect(r.source).toBe('server');
        expect(r.answers).toEqual({ name: 'Server' });
        expect(r.currentStepKey).toBe('sec-server');
        expect(r.note).toBeNull();
    });

    it('server wins when the local draft is from a different schema version (checksum mismatch)', () => {
        const r = reconcileDraft(server(), 'chk-1', local({ checksum: 'chk-OTHER', updatedAt: '2999-01-01T00:00:00Z' }));
        // Even though the local row is "newer", a version mismatch makes it stale → server wins silently.
        expect(r.source).toBe('server');
        expect(r.note).toBeNull();
    });

    it('local wins when a same-version local draft is newer, with a keep-your-work note', () => {
        const r = reconcileDraft(
            server({ lastSavedAt: '2026-07-23T08:00:00Z' }),
            'chk-1',
            local({ checksum: 'chk-1', updatedAt: '2026-07-23T12:00:00Z' }),
        );
        expect(r.source).toBe('local');
        expect(r.answers).toEqual({ name: 'Local' });
        expect(r.locale).toBe('es');
        expect(r.currentStepKey).toBe('sec-local');
        expect(r.note).not.toBeNull();
    });

    it('server wins when the same-version local draft is older or equal', () => {
        const r = reconcileDraft(
            server({ lastSavedAt: '2026-07-23T12:00:00Z' }),
            'chk-1',
            local({ checksum: 'chk-1', updatedAt: '2026-07-23T08:00:00Z' }),
        );
        expect(r.source).toBe('server');
        expect(r.note).toBeNull();
    });

    it('treats a null server lastSavedAt as oldest so any real local draft wins', () => {
        const r = reconcileDraft(server({ lastSavedAt: null }), 'chk-1', local({ checksum: 'chk-1' }));
        expect(r.source).toBe('local');
    });
});
