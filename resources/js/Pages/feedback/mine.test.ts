import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { MyFeedbackPageProps, MyFeedbackRow } from '@/components/feedback/types';

/**
 * "My reports" — every member's own feedback (M175, `R-9bfd832e`, `D139`).
 *
 * The testers asked to be told what became of what they sent (*"also update user if its fixed/resolved"*).
 * The bell and the email say THAT it changed; this page is where the reporter reads WHAT — the status, in
 * the words New · Ongoing · Resolved · Declined, and the support team's reply.
 *
 * Three things carry the weight:
 *  · THE SUPPORT LIFECYCLE IS NOT HERE. A reporter cannot triage, resolve or decline their own report; since
 *    M179 (`D151`) they can only say It works / Still a problem on a fix, and only where the SERVER offers it.
 *  · THE SCREENSHOT IS NAMED, NEVER SHOWN. ADR-0015 §D9 reads a feedback screenshot under `feedback.view`
 *    on every route that serves it or on none — and most reporters do not hold it.
 *  · THE REPLY APPEARS ONLY WHERE THERE IS ONE, so an empty box never reads as "they said nothing".
 */

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Head: { name: 'Head', render: () => null },
        Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: { get: mocks.get },
        useForm: (initial: Record<string, unknown>) => reactive({ ...initial, errors: {}, processing: false, post: vi.fn(), reset: vi.fn(), setError: vi.fn() }),
    };
});

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', props: ['title'], template: '<header><h1>{{ title }}</h1><slot name="actions" /></header>' },
}));

const MyFeedback = (await import('./Mine.vue')).default;

function row(overrides: Partial<MyFeedbackRow> = {}): MyFeedbackRow {
    return {
        id: '0192e2e0-0000-7000-8000-000000000001',
        type: 'bug',
        type_label: 'Bug',
        route: '/forms/1/builder',
        page_url: 'https://acme.meridian.test/forms/1/builder',
        page_title: 'Builder — Household survey',
        remarks: 'Dragging a question does nothing.',
        status: 'new',
        status_label: 'New',
        submitted_at: '2026-10-14T09:30:00+00:00',
        resolved_at: null,
        resolution_note: null,
        has_screenshot: false,
        source: 'button',
        source_label: null,
        reference: null,
        title: null,
        confirmed_at: null,
        confirmer: null,
        comments: [],
        actions: [],
        can_comment: true,
        ...overrides,
    };
}

/** A relayed report — a comment the team loaded; every member sees it under Testing-round comments (M179). */
function relayed(reference: string, overrides: Partial<MyFeedbackRow> = {}): MyFeedbackRow {
    return row({
        id: `relayed-${reference}`,
        route: '',
        page_url: null,
        page_title: null,
        source: 'relayed',
        source_label: 'Your items, Oct 16',
        reference,
        title: `Relayed ${reference}`,
        status: 'resolved',
        status_label: 'Resolved',
        resolution_note: 'Fixed in version a5b718b, live on staging.',
        actions: [
            { verb: 'confirm', label: 'It works' },
            { verb: 'reopen', label: 'Still a problem' },
        ],
        ...overrides,
    });
}

function render(overrides: Partial<MyFeedbackPageProps> = {}): VueWrapper {
    return mount(MyFeedback, {
        props: {
            data: [row()],
            meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
            empty_reason: null,
            testing_round: { open: 0, data: [] },
            view: 'mine',
            ...overrides,
        },
    });
}

beforeEach(() => {
    mocks.get.mockClear();
});

describe('my reports — what became of what I sent', () => {
    it('shows each report with its kind, its page and its status in the words the server uses', () => {
        const wrapper = render({ data: [row({ status: 'reviewed', status_label: 'Ongoing' })] });

        expect(wrapper.text()).toContain('Bug');
        expect(wrapper.text()).toContain('Ongoing');
        expect(wrapper.text()).not.toContain('reviewed');
        expect(wrapper.text()).toContain('Dragging a question does nothing.');

        const link = wrapper.find('a[href="https://acme.meridian.test/forms/1/builder"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('Builder — Household survey');

        wrapper.unmount();
    });

    it("shows the support team's reply where there is one, and no empty reply where there is not", () => {
        const answered = render({
            data: [row({ status: 'resolved', status_label: 'Resolved', resolution_note: 'Fixed in version abc1234, live on staging.' })],
        });
        expect(answered.text()).toContain('Fixed in version abc1234, live on staging.');
        expect(answered.find('[data-feedback-reply]').exists()).toBe(true);
        answered.unmount();

        const waiting = render();
        expect(waiting.find('[data-feedback-reply]').exists()).toBe(false);
        waiting.unmount();
    });

    it('names an attached screenshot but never shows or links it (ADR-0015 §D9)', () => {
        const wrapper = render({ data: [row({ has_screenshot: true })] });

        expect(wrapper.text()).toContain('Screenshot attached');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.html()).not.toContain('/screenshot');

        wrapper.unmount();
    });

    it('offers no support-side verb — only what the server offers a tester, and here it offers none', () => {
        const wrapper = render();

        const labels = wrapper.findAll('button').map((button) => button.text()).join(' ');
        expect(labels).not.toMatch(/mark|resolve|decline|ongoing/i);
        expect(labels).not.toMatch(/it works|still a problem/i);

        wrapper.unmount();
    });
});

describe('feedback & testing — the testing-round tab (M179, D150)', () => {
    it('shows no tab strip at all while nothing relayed is open', () => {
        const wrapper = render({ testing_round: { open: 0, data: [relayed('U1', { status: 'confirmed', status_label: 'Confirmed working', actions: [] })] } });

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.find('[data-testing-round]').exists()).toBe(false);
        expect(wrapper.find('[data-my-reports]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('names how many are open on the tab, and opens on it when the server says so', async () => {
        const wrapper = render({
            view: 'testing',
            testing_round: { open: 2, data: [relayed('U3'), relayed('U8', { status: 'reviewed', status_label: 'Ongoing', actions: [] })] },
        });

        const tabs = wrapper.findAll('[role="tab"]').map((tab) => tab.text());
        expect(tabs).toEqual(['My reports', 'Testing-round comments (2 open)']);
        expect(wrapper.find('[data-testing-round]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Relayed U3');
        expect(wrapper.text()).toContain('From: Your items, Oct 16');
        expect(wrapper.text()).toContain('Fixed in version a5b718b, live on staging.');

        await wrapper.findAll('[role="tab"]')[0].trigger('click');
        expect(wrapper.find('[data-my-reports]').exists()).toBe(true);
        expect(wrapper.find('[data-testing-round]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('offers It works and Still a problem only on the rows the server offers them on', () => {
        const wrapper = render({
            view: 'testing',
            testing_round: { open: 2, data: [relayed('U3'), relayed('U8', { status: 'reviewed', status_label: 'Ongoing', actions: [] })] },
        });

        const fixed = wrapper.find('[data-feedback-report="U3"]');
        const inHand = wrapper.find('[data-feedback-report="U8"]');
        expect(fixed.find('[data-verdict="confirm"]').exists()).toBe(true);
        expect(fixed.find('[data-verdict="reopen"]').exists()).toBe(true);
        expect(inHand.find('[data-feedback-verdict]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('says who confirmed a fix', () => {
        const wrapper = render({
            view: 'testing',
            testing_round: { open: 1, data: [relayed('U1'), relayed('R1-4', { status: 'confirmed', status_label: 'Confirmed working', confirmer: 'Tess Tester', actions: [] })] },
        });

        expect(wrapper.find('[data-feedback-report="R1-4"] [data-feedback-confirmed]').text()).toContain('Confirmed working by Tess Tester');

        wrapper.unmount();
    });
});

describe('my reports — empty and paged', () => {
    it('says how to send one when there is nothing yet', () => {
        const wrapper = render({ data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 }, empty_reason: 'no_rows' });

        expect(wrapper.text()).toContain("You haven't sent any feedback yet");
        expect(wrapper.text()).toContain('Feedback');

        wrapper.unmount();
    });

    it('pushes a history entry when paging', async () => {
        const wrapper = render({ meta: { current_page: 1, last_page: 2, total: 30, per_page: 25 } });

        await wrapper.find('button[aria-label="Next page"]').trigger('click');

        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(mocks.get.mock.calls[0][0]).toBe('/feedback/mine');
        expect(mocks.get.mock.calls[0][1]).toEqual({ page: 2 });
        expect(mocks.get.mock.calls[0][2]).not.toMatchObject({ replace: true });

        wrapper.unmount();
    });
});
