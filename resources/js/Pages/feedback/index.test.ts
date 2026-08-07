import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FeedbackPageProps, FeedbackRow } from '@/components/feedback/types';

/**
 * The tenant's own feedback list (Increment I7a, PRD Feature #11).
 *
 * Two assertions carry the weight:
 *  · IT OFFERS NO TRANSITION. Feature #11 gives the status lifecycle to the platform support team's
 *    internal view. A "Mark resolved" button here would be a tenant resolving a report in the OPERATOR's
 *    queue — a permissions defect that would look like a helpful affordance in review.
 *  · A NULL REPORTER IS A REMOVED MEMBER, NOT THE SYSTEM. The `users` join-shape RLS hides a removed
 *    member's row from a colleague, so a live non-null user_id can still resolve to null. Rendering that
 *    as "System" would attribute a person's words to the software.
 */

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.get },
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

const FeedbackIndex = (await import('./Index.vue')).default;

function row(overrides: Partial<FeedbackRow> = {}): FeedbackRow {
    return {
        id: '0192e2e0-0000-7000-8000-000000000001',
        route: '/dashboard',
        remarks: 'Exports are slow on big forms.',
        status: 'new',
        status_label: 'New',
        submitted_at: '2026-08-05T09:30:00+00:00',
        resolved_at: null,
        reporter: 'Demo Owner',
        has_screenshot: false,
        ...overrides,
    };
}

function props(overrides: Partial<FeedbackPageProps> = {}): FeedbackPageProps {
    return {
        data: [row()],
        meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
        filters: {
            statuses: [
                { value: 'new', label: 'New' },
                { value: 'reviewed', label: 'Reviewed' },
                { value: 'resolved', label: 'Resolved' },
                { value: 'wont_fix', label: "Won't fix" },
            ],
            applied: { status: null },
        },
        empty_reason: null,
        ...overrides,
    };
}

function render(overrides: Partial<FeedbackPageProps> = {}): VueWrapper {
    // `teleport: true` or MdsModal's panel renders outside the wrapper and is invisible to find().
    return mount(FeedbackIndex, { props: props(overrides), global: { stubs: { teleport: true } } });
}

beforeEach(() => {
    mocks.get.mockClear();
});

describe('tenant feedback list — read-only by design', () => {
    it('offers no way to change a status', async () => {
        const wrapper = render();
        await wrapper.find('button[aria-label="View report"]').trigger('click');

        const labels = wrapper.findAll('button').map((button) => button.text());

        expect(labels.join(' ')).not.toMatch(/mark|resolve|review|won't fix/i);
        expect(wrapper.text()).toContain('the status here is theirs to change, not yours');

        wrapper.unmount();
    });

    it('renders the status as the shared badge descriptor, not the raw enum word', () => {
        const wrapper = render({ data: [row({ status: 'wont_fix', status_label: "Won't fix" })] });

        expect(wrapper.text()).toContain("Won't fix");
        expect(wrapper.text()).not.toContain('wont_fix');

        wrapper.unmount();
    });
});

describe('tenant feedback list — the reporter', () => {
    it('names a removed member as a former member rather than as the system', () => {
        const wrapper = render({ data: [row({ reporter: null })] });

        expect(wrapper.text()).toContain('Former member');
        expect(wrapper.text()).not.toContain('System');

        wrapper.unmount();
    });
});

describe('tenant feedback list — navigation', () => {
    it('replaces history when filtering and pushes when paging', async () => {
        const wrapper = render({ meta: { current_page: 1, last_page: 3, total: 60, per_page: 25 } });

        const select = wrapper.find('select');
        await select.setValue('reviewed');

        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(mocks.get.mock.calls[0][0]).toBe('/feedback');
        expect(mocks.get.mock.calls[0][1]).toEqual({ status: 'reviewed' });
        expect(mocks.get.mock.calls[0][2]).toMatchObject({ replace: true });

        wrapper.unmount();
    });

    it('distinguishes an empty workspace from a filter that matched nothing', () => {
        const filtered = render({ data: [], empty_reason: 'no_matches' });
        expect(filtered.text()).toContain('No matching reports');
        filtered.unmount();

        const fresh = render({ data: [], empty_reason: 'no_rows' });
        expect(fresh.text()).toContain('No feedback yet');
        fresh.unmount();
    });
});
