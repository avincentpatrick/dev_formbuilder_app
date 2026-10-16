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
 *  · IT IS READ-ONLY. A reporter cannot move their own report through the operator's queue.
 *  · THE SCREENSHOT IS NAMED, NEVER SHOWN. ADR-0015 §D9 reads a feedback screenshot under `feedback.view`
 *    on every route that serves it or on none — and most reporters do not hold it.
 *  · THE REPLY APPEARS ONLY WHERE THERE IS ONE, so an empty box never reads as "they said nothing".
 */

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.get },
}));

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
        ...overrides,
    };
}

function render(overrides: Partial<MyFeedbackPageProps> = {}): VueWrapper {
    return mount(MyFeedback, {
        props: {
            data: [row()],
            meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
            empty_reason: null,
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

    it('offers no way to change a status', () => {
        const wrapper = render();

        const labels = wrapper.findAll('button').map((button) => button.text()).join(' ');
        expect(labels).not.toMatch(/mark|resolve|decline|ongoing/i);

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
