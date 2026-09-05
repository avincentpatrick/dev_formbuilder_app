import { mount } from '@vue/test-utils';
import App from '../App.vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Increment M76 — THE RESUME BOOT FETCHES ITS SCHEMA AT THE DRAFT'S VERSION, NOT AT THE PUBLISHED ONE.
 *
 * ⛔ WHY THIS FILE EXISTS AT ALL, AND WHY IT MOUNTS `App.vue` RATHER THAN TESTING `api-client` ALONE.
 * The defect is a WIRING defect: `resumeDraft()` has always returned a correctly-pinned share token and
 * `App.vue` never read it. A unit test on `api-client` proves `adoptToken()` works and would stay green
 * forever if `App.vue` stopped calling it — which is precisely the regression worth guarding. So the
 * component is mounted and its dependency is faked, rather than the other way round.
 *
 * ⚠️ `App.vue` IS MOUNTED BY NOTHING ELSE IN THE REPOSITORY, WHICH IS WHY THE BUG SURVIVED. The backlog row
 * said so explicitly — *"a naive fix ships with a green suite that proves nothing"* — and it was right.
 *
 * ── WHAT WENT WRONG, SO A LATER READER DOES NOT "SIMPLIFY" THE ADOPTION AWAY ──────────────────────────
 * A resume boot is the boot most likely to be served from the service worker's cached shell, and that HTML
 * carries whatever `data-share-token` was minted when it was cached — up to seven days old against a 24h
 * token. `fetchSchema()` then 401s, `withFreshToken()` transparently re-mints through `GET /f/{slug}`, and
 * that route pins `current_published_version_id`. The page renders the NEW schema while the draft's answers
 * belong to the OLD one, `reconcileDraft` drops the respondent's newer local tier on the mismatch with no
 * note, and their first autosave 409s them onto a fresh uuid — abandoning the server draft and the emailed
 * resume link with it.
 */

const fetchSchema = vi.fn(async () => ({
    form: { id: 'form-1', title: 'A form', bot_challenge: 'none', sections: [], fields: [] },
    version: { id: 'version-PINNED', number: 1 },
}));
const adoptToken = vi.fn();
const remint = vi.fn();
const token = vi.fn(() => 'shell-token-STALE');

/** Call order across the two mocks, which is the half a per-mock assertion cannot see. */
const calls: string[] = [];

const resumeDraft = vi.fn(async () => {
    calls.push('resumeDraft');

    return {
        id: 'draft-1',
        completenessPercent: 10,
        clientSubmissionUuid: 'uuid-1',
        // The draft's pin. The whole defect is this disagreeing with the schema that gets rendered.
        formVersionId: 'version-PINNED',
        answers: {},
        lastSavedAt: '2026-09-01T00:00:00Z',
        draftCurrentStep: null,
        locale: 'en',
        // ⚠️ MINTED BY `GuestDraftResumeController` AGAINST THE DRAFT'S VERSION. This is the value that was
        // decoded, returned, documented as handed on — and read by nothing.
        shareToken: 'resume-token-PINNED',
        shareTokenExpiresAt: '2026-09-02T00:00:00Z',
        contentChecksum: null,
    };
});

vi.mock('../lib/api-client', () => ({
    createApiClient: () => ({
        fetchSchema: async () => {
            calls.push('fetchSchema');

            return fetchSchema();
        },
        adoptToken: (t: string) => {
            calls.push('adoptToken');
            adoptToken(t);
        },
        submit: vi.fn(),
        saveDraft: vi.fn(),
        remint,
        token,
    }),
    resumeDraft: (...args: unknown[]) => resumeDraft(...(args as [])),
    ApiError: class extends Error {},
}));

function bootstrap(overrides: Record<string, unknown> = {}) {
    return {
        shareToken: 'shell-token-STALE',
        expiresAt: '2026-09-02T00:00:00Z',
        formId: 'form-1',
        formTitle: 'A form',
        slug: 'my-form',
        defaultLocale: 'en',
        resumeToken: 'resume-link-token',
        brandVersion: 'none',
        ...overrides,
    };
}

function mountApp(props: Record<string, unknown>) {
    return mount(App, {
        props: { bootstrap: props as never },
        global: {
            stubs: {
                RuntimeSession: true,
                ConfirmationScreen: true,
                SyncStatus: true,
                OfflineIndicator: true,
                MdsEmptyState: true,
                MdsSpinner: true,
            },
        },
    });
}

describe('resume boot — which version the schema is fetched at', () => {
    beforeEach(() => {
        calls.length = 0;
        adoptToken.mockClear();
        fetchSchema.mockClear();
        resumeDraft.mockClear();
    });

    it('adopts the resume-minted share token before fetching the schema', async () => {
        const wrapper = mountApp(bootstrap());
        await vi.waitFor(() => expect(calls).toContain('fetchSchema'));

        // ⛔ THE TOKEN, AND THEN THE ORDER — BOTH, BECAUSE EITHER ALONE PASSES A BROKEN VERSION.
        // Adopting the right token AFTER the schema fetch would leave the rendered schema pinned to the
        // published version, which is the entire defect with the fix apparently present.
        expect(adoptToken).toHaveBeenCalledWith('resume-token-PINNED');
        expect(calls.indexOf('adoptToken')).toBeGreaterThan(calls.indexOf('resumeDraft'));
        expect(calls.indexOf('adoptToken')).toBeLessThan(calls.indexOf('fetchSchema'));

        wrapper.unmount();
    });

    it('does not adopt anything on an ordinary entry, which has no draft to be pinned to', async () => {
        // The permissive control. A file that only asserts adoption would pass against a component that
        // adopted unconditionally — and on the plain `/f/{slug}` path there is no resume token to adopt,
        // so doing it anyway would be reading `undefined` into the client's credential.
        const wrapper = mountApp(bootstrap({ resumeToken: '' }));
        await vi.waitFor(() => expect(calls).toContain('fetchSchema'));

        expect(resumeDraft).not.toHaveBeenCalled();
        expect(adoptToken).not.toHaveBeenCalled();

        wrapper.unmount();
    });
});
