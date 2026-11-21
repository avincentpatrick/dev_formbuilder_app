import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick, reactive, ref } from 'vue';
import AlreadyAnswered from '../components/AlreadyAnswered.vue';
import ConfirmationScreen from '../components/ConfirmationScreen.vue';
import ContinueOrStartOver from '../components/ContinueOrStartOver.vue';
import { createAutosave } from '../composables/useAutosave';
import { readAnswered, rememberAnswered } from '../lib/answered';
import { parseAmendLink } from '../lib/api-client';
import { readBootstrap } from '../lib/bootstrap';
import { adoptDraft, DRAFT_TTL_MS, earlierVisitDraft, openDb } from '../lib/db';
import { normalizeError } from '../lib/error-normalizer';
import { amendTokenFromPath, isAmendShell } from '../lib/resume-shell';

/**
 * M248 (`D118` A, `D232`, NHETE recommendation 6a) — the pieces of "the respondent's answers are kept", each alone:
 * which earlier draft is offered back, the device's memory of a sent response, the amend link's parsing and refusals,
 * the server-draft identity autosave carries, and the three screens' choices. `respondent-answers-boot.test.ts` mounts
 * `App.vue` to prove they are wired together.
 */

let n = 0;
const freshDb = () => openDb(`respondent-answers-${(n += 1)}`);
const NOW = Date.parse('2026-11-21T00:00:00Z');
const KEY = { formVersionId: 'v1', slug: 'my-form', checksum: 'c1' };

async function putRow(db: ReturnType<typeof openDb>, overrides: Record<string, unknown> = {}) {
    await db.draft_answers.put({
        form_version_id: 'v1',
        local_draft_id: 'my-form',
        checksum: 'c1',
        locale: 'en',
        current_step_key: 'step-1',
        answers: { name: 'Ana' },
        updated_at: new Date(NOW - 60_000).toISOString(),
        respondent_session_id: 'earlier',
        ...overrides,
    } as never);
}

describe('earlierVisitDraft — which draft is offered back (D118 A)', () => {
    it('offers an earlier visit\'s answered, fresh, same-schema row', async () => {
        const db = freshDb();
        await putRow(db);

        expect((await earlierVisitDraft(db, KEY, 'now', NOW))?.answers).toEqual({ name: 'Ana' });
    });

    it.each([
        ['this visit\'s own row, which restores silently', { respondent_session_id: 'now' }],
        ['a row for another version of the form', { checksum: 'c0' }],
        ['a row past the seven days', { updated_at: new Date(NOW - DRAFT_TTL_MS - 1000).toISOString() }],
        ['a row with no answer in it', { answers: { name: '', tags: [], grid: {} } }],
    ])('offers nothing for %s', async (_label, overrides) => {
        const db = freshDb();
        await putRow(db, overrides);

        expect(await earlierVisitDraft(db, KEY, 'now', NOW)).toBeNull();
    });

    it('offers nothing when there is no row at all', async () => {
        expect(await earlierVisitDraft(freshDb(), KEY, 'now', NOW)).toBeNull();
    });

    it('adoptDraft moves only the visit stamp', async () => {
        const db = freshDb();
        await putRow(db, { resume_token: 'tok', client_submission_uuid: 'uuid', base_content_checksum: 'base' });

        await adoptDraft(db, KEY, 'now');

        expect(await db.draft_answers.get(['v1', 'my-form'])).toMatchObject({
            respondent_session_id: 'now',
            answers: { name: 'Ana' },
            current_step_key: 'step-1',
            resume_token: 'tok',
            client_submission_uuid: 'uuid',
            base_content_checksum: 'base',
        });
    });
});

describe('the device\'s memory of a sent response (lib/answered.ts)', () => {
    it('is empty until something is remembered, then reads back what was', async () => {
        const db = freshDb();
        expect(await readAnswered(db, 'my-form')).toBeNull();

        await rememberAnswered(db, 'my-form', { reference: 'ABCD-1234', at: '2026-11-21T01:00:00Z', amendToken: 'tok' });

        expect(await readAnswered(db, 'my-form')).toEqual({ reference: 'ABCD-1234', at: '2026-11-21T01:00:00Z', amendToken: 'tok' });
        expect(await readAnswered(db, 'another-form')).toBeNull();
    });

    it('keeps what a queued send did not yet know, and fills it in when the replay delivers it', async () => {
        const db = freshDb();
        await rememberAnswered(db, 'my-form', { reference: null, at: '2026-11-21T01:00:00Z', amendToken: null });
        await rememberAnswered(db, 'my-form', { reference: 'ABCD-1234', at: '2026-11-21T02:00:00Z', amendToken: 'tok' });
        // A later write that knows less does not forget it.
        await rememberAnswered(db, 'my-form', { reference: null, at: '2026-11-21T03:00:00Z', amendToken: null });

        expect(await readAnswered(db, 'my-form')).toEqual({ reference: 'ABCD-1234', at: '2026-11-21T03:00:00Z', amendToken: 'tok' });
    });

    it('reads a damaged entry as nothing rather than throwing', async () => {
        const db = freshDb();
        await db.app_state.put({ key: 'answered:my-form', value: '{not json' });

        expect(await readAnswered(db, 'my-form')).toBeNull();
    });
});

describe('the amend link on the wire', () => {
    it('reads the submit response\'s link, and nothing malformed', () => {
        expect(parseAmendLink({ token: 't', url: 'https://a.example/f/amend/t', until: '2026-12-01T00:00:00Z' }))
            .toEqual({ token: 't', url: 'https://a.example/f/amend/t', until: '2026-12-01T00:00:00Z' });
        expect(parseAmendLink({ token: 't', url: 'https://a.example/f/amend/t' })?.until).toBeNull();
        expect(parseAmendLink(null)).toBeNull();
        expect(parseAmendLink({ token: '', url: 'x' })).toBeNull();
        expect(parseAmendLink({ url: 'x' })).toBeNull();
    });

    it.each([
        [403, 'amend_not_allowed'],
        [409, 'response_under_review'],
        [409, 'response_changed'],
    ])('classifies a %i %s as its own kind, never a refresh that would re-mint onto the current version', (status, code) => {
        expect(normalizeError(status, { error: { code, message: 'x' } }).kind).toBe('amend');
    });

    it('takes the token from an amend address only', () => {
        expect(amendTokenFromPath('/f/amend/v1.abc.def')).toBe('v1.abc.def');
        expect(amendTokenFromPath('/f/amend/v1.abc.def/')).toBe('v1.abc.def');
        expect(amendTokenFromPath('/f/resume/v1.abc.def')).toBe('');
        expect(amendTokenFromPath('/f/amendments')).toBe('');
        expect(isAmendShell('https://a.example/f/amend/v1.abc.def')).toBe(true);
        expect(isAmendShell('https://a.example/f/amendments')).toBe(false);

        const el = document.createElement('div');
        el.dataset.shareToken = 's';
        expect(readBootstrap(el, '/f/amend/v1.abc.def').amendToken).toBe('v1.abc.def');
        expect(readBootstrap(el, '/f/my-form').amendToken).toBe('');
    });
});

describe('autosave carries the server draft\'s identity (D232 answer 4)', () => {
    it('writes the uuid, baseline and resume token onto the row once a server draft exists, on a flush', async () => {
        const db = freshDb();
        const answers = reactive<Record<string, string>>({ name: 'Ana' });
        let server: { clientSubmissionUuid: string; baseContentChecksum: string | null; resumeToken: string } | null = null;
        const autosave = createAutosave({
            db,
            formId: 'f',
            formVersionId: 'v1',
            slug: 'my-form',
            checksum: 'c1',
            answers,
            locale: ref('en'),
            currentStepKey: ref('step-1'),
            now: () => NOW,
            sessionId: 'now',
            serverDraft: () => server,
        });

        autosave.flush();
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect((await db.draft_answers.get(['v1', 'my-form']))?.resume_token).toBeUndefined();

        // "Save and finish later" answered: the answers did not change, the identity did.
        server = { clientSubmissionUuid: 'uuid-1', baseContentChecksum: 'base-1', resumeToken: 'tok-1' };
        autosave.flush();
        await new Promise((resolve) => setTimeout(resolve, 20));

        expect(await db.draft_answers.get(['v1', 'my-form'])).toMatchObject({
            client_submission_uuid: 'uuid-1',
            base_content_checksum: 'base-1',
            resume_token: 'tok-1',
            respondent_session_id: 'now',
        });
        autosave.dispose();
    });
});

describe('the thank-you page, by the form\'s "After submitting" setting', () => {
    const base = { reference: 'ABCD-1234', queueTag: null, message: 'Thanks', redirect: null };
    const link = { token: 't', url: 'https://a.example/f/amend/t', until: null };

    it('offers another response by default, as every form did before', () => {
        const wrapper = mount(ConfirmationScreen, { props: base });

        expect(wrapper.text()).toContain('Submit another response');
        expect(wrapper.find('[data-amend-link]').exists()).toBe(false);
    });

    it('offers Change my answers and the link to it in `amend` mode, and no second response', async () => {
        const wrapper = mount(ConfirmationScreen, { props: { ...base, afterSubmit: 'amend', amendLink: link } });

        expect(wrapper.find('[data-amend-link]').exists()).toBe(true);
        expect((wrapper.find('[data-amend-link] input').element as HTMLInputElement).value).toBe(link.url);
        expect(wrapper.find('a[href^="mailto:"]').attributes('href')).toContain(encodeURIComponent(link.url));
        expect(wrapper.text()).not.toContain('Submit another response');

        await wrapper.find('[data-change-answers]').trigger('click');
        expect(wrapper.emitted('change')).toHaveLength(1);
    });

    it('does not count down to a destination while the link is on screen', async () => {
        const wrapper = mount(ConfirmationScreen, {
            props: { ...base, afterSubmit: 'amend', amendLink: link, redirect: { url: 'https://next.example', label: 'Next', delaySeconds: 5 } },
        });
        await nextTick();

        expect(wrapper.text()).not.toContain('Continuing in');
        expect(wrapper.text()).toContain('Continue now');
    });

    it('says a queued response can be changed once sent, in `amend` mode', () => {
        const wrapper = mount(ConfirmationScreen, { props: { ...base, reference: null, queueTag: 'Q-1', afterSubmit: 'amend', amendLink: null } });

        expect(wrapper.text()).toContain('Once this response is sent');
        expect(wrapper.text()).not.toContain('Submit another response');
    });

    it('offers nothing more in `once` mode', () => {
        const wrapper = mount(ConfirmationScreen, { props: { ...base, afterSubmit: 'once' } });

        expect(wrapper.text()).not.toContain('Submit another response');
        expect(wrapper.find('[data-amend-link]').exists()).toBe(false);
    });
});

describe('the two new screens', () => {
    it('ContinueOrStartOver asks, says when, and emits the choice', async () => {
        const wrapper = mount(ContinueOrStartOver, {
            props: { title: 'A form', savedAt: '2026-11-21T01:00:00Z', timeZone: 'UTC', locale: 'en' },
            attachTo: document.body,
        });
        await nextTick();

        expect(document.activeElement?.tagName).toBe('H1');
        expect(wrapper.text()).toContain('You started this form on this device');
        await wrapper.find('[data-continue]').trigger('click');
        expect(wrapper.emitted('continue')).toHaveLength(1);
        wrapper.unmount();

        const again = mount(ContinueOrStartOver, { props: { title: 'A form', savedAt: '2026-11-21T01:00:00Z', timeZone: 'UTC', locale: 'en' } });
        await again.find('[data-start-over]').trigger('click');
        expect(again.emitted('start-over')).toHaveLength(1);
    });

    it('AlreadyAnswered offers a change only when allowed, and otherwise says why', async () => {
        const props = { title: 'A form', reference: 'ABCD-1234', sentAt: '2026-11-21T01:00:00Z', until: null, timeZone: 'UTC', locale: 'en' };

        const can = mount(AlreadyAnswered, { props: { ...props, canChange: true, closedNote: null } });
        expect(can.text()).toContain('ABCD-1234');
        await can.find('[data-change-answers]').trigger('click');
        expect(can.emitted('change')).toHaveLength(1);

        const cannot = mount(AlreadyAnswered, { props: { ...props, canChange: false, closedNote: 'This form has closed.' } });
        expect(cannot.find('[data-change-answers]').exists()).toBe(false);
        expect(cannot.text()).toContain('This form has closed.');
    });
});
