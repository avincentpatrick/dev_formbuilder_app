import { flushPromises, mount } from '@vue/test-utils';
import App from '../App.vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { openDb } from '../lib/db';
import { readAnswered, rememberAnswered } from '../lib/answered';
import { respondentSession } from '../lib/respondent-session';

/**
 * M248 (`D118` A, `D232`, NHETE recommendation 6a) — what `App.vue` shows BEFORE the form, and how it opens a sent
 * response to change it. Mounted whole, with the API faked, for `resume-boot.test.ts`'s reason: these are wiring
 * decisions, and a unit test of `earlierVisitDraft()` or `readAmend()` stays green if `App.vue` stops calling them.
 *
 *   · An earlier visit's answers are offered back behind "Continue where you left off" / "Start over" — never shown
 *     first (`D118`). Continue makes the row this visit's; with a server draft behind it, the session carries on THAT
 *     draft through the resume read (`D232` answer 4).
 *   · A one-response form remembers the device answered, and says so instead of a blank form; in `amend` mode it
 *     offers "Change my answers", which opens the response through its token, on its own version, under its own clock.
 */

const VERSION = 'version-1';
const CHECKSUM = 'checksum-1';
const SLUG = 'my-form';

let afterSubmit: 'another' | 'amend' | 'once' = 'another';
let acceptance = 'open';
const calls: string[] = [];
const adoptToken = vi.fn();

const fetchSchema = vi.fn(async () => {
    calls.push('fetchSchema');

    return {
        form: {
            id: 'form-1',
            title: 'A form',
            bot_challenge: 'off',
            after_submit: afterSubmit,
            schedule: { opens_at: null, closes_at: '2026-12-31T00:00:00Z', timezone: 'UTC', max_responses: null, acceptance, remaining: null },
        },
        version: { id: VERSION, version_number: 1, checksum: CHECKSUM, schema: { sections: [], fields: [] } },
    };
});

const resumeDraft = vi.fn(async (token: string) => {
    calls.push(`resumeDraft:${token}`);

    return {
        id: 'draft-1',
        completenessPercent: 40,
        clientSubmissionUuid: 'server-uuid',
        formVersionId: VERSION,
        answers: { name: 'from the server' },
        lastSavedAt: '2026-01-01T00:00:00Z',
        draftCurrentStep: null,
        locale: 'en',
        shareToken: 'resume-share-token',
        shareTokenExpiresAt: '2026-12-31T00:00:00Z',
        contentChecksum: 'server-baseline',
    };
});

const readAmend = vi.fn(async (token: string) => {
    calls.push(`readAmend:${token}`);

    return {
        id: 'submission-1',
        reference: 'ABCD-1234',
        formVersionId: VERSION,
        answers: { name: 'sent answer' },
        contentChecksum: 'sent-baseline',
        submittedAt: '2026-03-04T05:06:07+00:00',
        locale: 'en',
        until: '2026-12-31T00:00:00Z',
        amendedAt: null,
        shareToken: 'amend-share-token',
    };
});

vi.mock('../lib/api-client', () => ({
    createApiClient: () => ({
        fetchSchema: () => fetchSchema(),
        adoptToken: (t: string) => {
            calls.push(`adoptToken:${t}`);
            adoptToken(t);
        },
        submit: vi.fn(),
        saveDraft: vi.fn(),
        remint: vi.fn(),
        token: () => 'shell-token',
    }),
    resumeDraft: (token: string) => resumeDraft(token),
    readAmend: (token: string) => readAmend(token),
    sendAmend: vi.fn(),
    ApiError: class extends Error {},
}));

function bootstrap(overrides: Record<string, unknown> = {}) {
    return {
        shareToken: 'shell-token',
        expiresAt: '2026-12-31T00:00:00Z',
        formId: 'form-1',
        formTitle: 'A form',
        slug: SLUG,
        defaultLocale: 'en',
        resumeToken: '',
        amendToken: '',
        brandVersion: 'none',
        ...overrides,
    };
}

function mountApp(overrides: Record<string, unknown> = {}) {
    return mount(App, {
        props: { bootstrap: bootstrap(overrides) as never },
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

async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) {
        await flushPromises();
    }
}

async function earlierRow(overrides: Record<string, unknown> = {}): Promise<void> {
    await openDb().draft_answers.put({
        form_version_id: VERSION,
        local_draft_id: SLUG,
        checksum: CHECKSUM,
        locale: 'en',
        current_step_key: 'step-2',
        answers: { name: 'Ana' },
        updated_at: new Date().toISOString(),
        respondent_session_id: 'an-earlier-visit',
        ...overrides,
    } as never);
}

beforeEach(async () => {
    calls.length = 0;
    afterSubmit = 'another';
    acceptance = 'open';
    adoptToken.mockClear();
    resumeDraft.mockClear();
    readAmend.mockClear();
    const db = openDb();
    await db.draft_answers.clear();
    await db.app_state.clear();
});

describe('Continue where you left off, or Start over (D118 A)', () => {
    it('asks before any answer appears when an earlier visit left answers here', async () => {
        await earlierRow();
        const wrapper = mountApp();
        await settle();

        expect(wrapper.find('[data-continue-choice]').exists()).toBe(true);
        expect(wrapper.findComponent({ name: 'RuntimeSession' }).exists()).toBe(false);
        // Nothing of the answers is on the page while it asks.
        expect(wrapper.text()).not.toContain('Ana');
    });

    it('Continue makes the row this visit\'s, so the session restores it', async () => {
        await earlierRow();
        const wrapper = mountApp();
        await settle();

        await wrapper.find('[data-continue]').trigger('click');
        await settle();

        const row = await openDb().draft_answers.get([VERSION, SLUG]);
        expect(row?.respondent_session_id).toBe(respondentSession());
        expect(row?.answers).toEqual({ name: 'Ana' });
        expect(wrapper.findComponent({ name: 'RuntimeSession' }).exists()).toBe(true);
    });

    it('Continue carries on the SAME server draft when the row shadows one (D232 answer 4)', async () => {
        await earlierRow({ client_submission_uuid: 'server-uuid', base_content_checksum: 'server-baseline', resume_token: 'row-resume-token' });
        const wrapper = mountApp();
        await settle();

        await wrapper.find('[data-continue]').trigger('click');
        await settle();

        expect(resumeDraft).toHaveBeenCalledWith('row-resume-token');
        const session = wrapper.findComponent({ name: 'RuntimeSession' });
        expect(session.exists()).toBe(true);
        expect((session.props('resume') as { uuid: string; contentChecksum: string } | null)?.uuid).toBe('server-uuid');
        expect((session.props('resume') as { contentChecksum: string } | null)?.contentChecksum).toBe('server-baseline');
    });

    it('Start over leaves the earlier row as it was and opens a blank form', async () => {
        await earlierRow();
        const wrapper = mountApp();
        await settle();

        await wrapper.find('[data-start-over]').trigger('click');
        await settle();

        const row = await openDb().draft_answers.get([VERSION, SLUG]);
        expect(row?.respondent_session_id).toBe('an-earlier-visit');
        const session = wrapper.findComponent({ name: 'RuntimeSession' });
        expect(session.exists()).toBe(true);
        expect(session.props('initialAnswers')).toBeUndefined();
        expect(session.props('resume')).toBeNull();
    });

    it('does not ask about this visit\'s own row, an empty row, or one written for another version of the form', async (ctx) => {
        for (const overrides of [
            { respondent_session_id: respondentSession() },
            { answers: {} },
            { checksum: 'an-older-checksum' },
        ]) {
            await openDb().draft_answers.clear();
            await earlierRow(overrides);
            const wrapper = mountApp();
            await settle();

            expect(wrapper.find('[data-continue-choice]').exists(), JSON.stringify(overrides) + ` (${ctx.task.name})`).toBe(false);
            wrapper.unmount();
        }
    });
});

describe('a one-response form remembers this device answered (D232)', () => {
    it('says so instead of a blank form, and offers no change, in `once` mode', async () => {
        afterSubmit = 'once';
        await rememberAnswered(openDb(), SLUG, { reference: 'ABCD-1234', at: '2026-03-04T05:06:07Z', amendToken: null });
        const wrapper = mountApp();
        await settle();

        expect(wrapper.find('[data-already-answered]').exists()).toBe(true);
        expect(wrapper.text()).toContain('ABCD-1234');
        expect(wrapper.find('[data-change-answers]').exists()).toBe(false);
        expect(wrapper.findComponent({ name: 'RuntimeSession' }).exists()).toBe(false);
    });

    it('forgets nothing but ignores the memory once the form offers another response again', async () => {
        afterSubmit = 'another';
        await rememberAnswered(openDb(), SLUG, { reference: 'ABCD-1234', at: '2026-03-04T05:06:07Z', amendToken: null });
        const wrapper = mountApp();
        await settle();

        expect(wrapper.find('[data-already-answered]').exists()).toBe(false);
        expect(wrapper.findComponent({ name: 'RuntimeSession' }).exists()).toBe(true);
    });

    it('offers Change my answers in `amend` mode, even when the form is full, and opens the response through its token', async () => {
        afterSubmit = 'amend';
        acceptance = 'capacity_reached';
        await rememberAnswered(openDb(), SLUG, { reference: 'ABCD-1234', at: '2026-03-04T05:06:07Z', amendToken: 'kept-amend-token' });
        const wrapper = mountApp();
        await settle();

        await wrapper.find('[data-change-answers]').trigger('click');
        await settle();

        expect(readAmend).toHaveBeenCalledWith('kept-amend-token');
        // The response's own version: its share token is adopted BEFORE the schema is fetched.
        expect(calls.indexOf('adoptToken:amend-share-token')).toBeLessThan(calls.lastIndexOf('fetchSchema'));
        const session = wrapper.findComponent({ name: 'RuntimeSession' });
        expect(session.props('amend')).toEqual({ token: 'kept-amend-token', baseline: 'sent-baseline' });
        expect(session.props('initialAnswers')).toEqual({ name: 'sent answer' });
        // The day it was sent is the clock, as the server checks it.
        expect(String(session.props('now'))).toContain('2026-03-04');
        // No URL prefill into a sent response.
        expect(session.props('search')).toBe('');
    });

    it('offers no change once the form has closed, and says why', async () => {
        afterSubmit = 'amend';
        acceptance = 'closed';
        await rememberAnswered(openDb(), SLUG, { reference: 'ABCD-1234', at: '2026-03-04T05:06:07Z', amendToken: 'kept-amend-token' });
        const wrapper = mountApp();
        await settle();

        expect(wrapper.find('[data-change-answers]').exists()).toBe(false);
        expect(wrapper.text()).toContain('can no longer be changed');
    });

    it('remembers a response sent on a one-response form, with its token in `amend` mode', async () => {
        afterSubmit = 'amend';
        const wrapper = mountApp();
        await settle();

        wrapper.findComponent({ name: 'RuntimeSession' }).vm.$emit('submitted', 'submission-1', 'ABCD-1234', null, null, {
            token: 'issued-amend-token',
            url: 'https://acme.example/f/amend/issued-amend-token',
            until: null,
        });
        await settle();

        expect(await readAnswered(openDb(), SLUG)).toMatchObject({ reference: 'ABCD-1234', amendToken: 'issued-amend-token' });
    });

    it('remembers nothing on a form that offers another response', async () => {
        afterSubmit = 'another';
        const wrapper = mountApp();
        await settle();

        wrapper.findComponent({ name: 'RuntimeSession' }).vm.$emit('submitted', 'submission-1', 'ABCD-1234', null, null, null);
        await settle();

        expect(await readAnswered(openDb(), SLUG)).toBeNull();
    });
});

describe('opened from an amend link', () => {
    it('reads the response, adopts its version, and opens a session that changes it', async () => {
        afterSubmit = 'amend';
        const wrapper = mountApp({ amendToken: 'link-amend-token' });
        await settle();

        expect(readAmend).toHaveBeenCalledWith('link-amend-token');
        expect(calls[0]).toBe('readAmend:link-amend-token');
        expect(calls.indexOf('adoptToken:amend-share-token')).toBeLessThan(calls.indexOf('fetchSchema'));
        expect(wrapper.findComponent({ name: 'RuntimeSession' }).props('amend')).toEqual({ token: 'link-amend-token', baseline: 'sent-baseline' });
    });
});
