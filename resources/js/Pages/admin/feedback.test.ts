import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ConsoleFeedbackPageProps, ConsoleFeedbackRow } from '@/components/feedback/types';

/**
 * The platform feedback support console (Increment I7a, PRD Feature #11 / RBAC §9).
 *
 * The assertion that matters most is that THE PAGE DOES NOT KNOW THE LIFECYCLE. Every button comes from
 * `row.transitions`, which the presenter fills from `FeedbackStatus::allowedTransitions(Support)` — the same
 * source `SuperAdminService::transitionFeedback()` guards with. If this page ever re-derived the verbs in
 * TypeScript, the offered set and the accepted set would drift the first time the lifecycle changed, and
 * the symptom would be a button that errors rather than one that is missing.
 *
 * The page's accessibility is scanned in a real browser by `tests/e2e/admin-console-axe.spec.ts`; these unit
 * assertions cover what it does.
 */

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    patch: vi.fn(),
    pageProps: { errors: {} as Record<string, string>, auth: { user: { name: 'Platform Admin' }, can: {} } },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.get, patch: mocks.patch, post: vi.fn() },
    usePage: () => ({ props: mocks.pageProps }),
}));

vi.mock('@/Layouts/AdminLayout.vue', () => ({
    default: { name: 'AdminLayout', template: '<main><slot /></main>' },
}));

const AdminFeedback = (await import('./Feedback.vue')).default;

function row(overrides: Partial<ConsoleFeedbackRow> = {}): ConsoleFeedbackRow {
    return {
        id: '0192e2e0-0000-7000-8000-000000000001',
        tenant_id: '0192e2e0-0000-7000-8000-0000000000aa',
        tenant_name: 'Demo Workspace',
        route: '/dashboard',
        remarks: 'The dashboard totals look wrong after an import.',
        browser_info: { userAgent: 'PestUA', viewport: '1440x900' },
        status: 'new',
        status_label: 'New',
        submitted_at: '2026-08-05T09:30:00+00:00',
        resolved_at: null,
        reporter: { name: 'Demo Owner', email: 'owner@demo.test' },
        reporter_role_label: 'Form Editor',
        resolution_note: null,
        resolver: null,
        has_screenshot: false,
        type: 'bug',
        type_label: 'Bug',
        page_url: null,
        page_title: null,
        transitions: [
            { value: 'reviewed', label: 'Ongoing' },
            { value: 'resolved', label: 'Resolved' },
            { value: 'wont_fix', label: 'Declined' },
        ],
        source: 'button',
        source_label: null,
        reference: null,
        title: null,
        confirmer: null,
        comments: [],
        ...overrides,
    };
}

function props(overrides: Partial<ConsoleFeedbackPageProps> = {}): ConsoleFeedbackPageProps {
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
            tenants: [{ value: '0192e2e0-0000-7000-8000-0000000000aa', label: 'Demo Workspace' }],
            types: [
                { value: 'bug', label: 'Bug' },
                { value: 'suggestion', label: 'Suggestion' },
                { value: 'question', label: 'Question' },
            ],
            applied: { status: null, tenant_id: null, type: null },
        },
        empty_reason: null,
        ...overrides,
    };
}

function render(overrides: Partial<ConsoleFeedbackPageProps> = {}): VueWrapper {
    return mount(AdminFeedback, { props: props(overrides), global: { stubs: { teleport: true } } });
}

async function openDetail(wrapper: VueWrapper): Promise<void> {
    await wrapper.find('button[aria-label="Open report"]').trigger('click');
}

beforeEach(() => {
    mocks.get.mockClear();
    mocks.patch.mockClear();
    mocks.pageProps.errors = {};
});

describe('feedback console — transitions come from the server', () => {
    it('offers exactly the verbs the row was handed, and no others', async () => {
        const wrapper = render();
        await openDetail(wrapper);

        const labels = wrapper.findAll('button').map((button) => button.text());

        expect(labels).toContain('Mark Ongoing');
        expect(labels).toContain('Mark Resolved');
        expect(labels).toContain('Mark Declined');
        // Confirmed is a tester's word, never the support team's (M179, `D151`) — it is never handed here.
        expect(labels).not.toContain('Mark Confirmed working');

        wrapper.unmount();
    });

    it('offers only re-open on a closed report', async () => {
        const wrapper = render({
            data: [row({ status: 'resolved', status_label: 'Resolved', transitions: [{ value: 'reviewed', label: 'Ongoing' }] })],
        });
        await openDetail(wrapper);

        const labels = wrapper.findAll('button').map((button) => button.text());

        expect(labels).toContain('Mark Ongoing');
        expect(labels.filter((label) => label.startsWith('Mark'))).toHaveLength(1);

        wrapper.unmount();
    });

    it('PATCHes the chosen status, preserving scroll', async () => {
        const wrapper = render();
        await openDetail(wrapper);

        const button = wrapper.findAll('button').find((candidate) => candidate.text() === 'Mark Ongoing');
        await button!.trigger('click');

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        expect(mocks.patch.mock.calls[0][0]).toBe('/admin/feedback/0192e2e0-0000-7000-8000-000000000001');
        // M175: the reply travels with the status; left empty, it is sent as no reply at all.
        expect(mocks.patch.mock.calls[0][1]).toEqual({ status: 'reviewed', note: null });
        expect(mocks.patch.mock.calls[0][2]).toMatchObject({ preserveScroll: true });

        wrapper.unmount();
    });
});

describe('feedback console — what the operator sees', () => {
    it('shows the remarks in full and names the workspace they came from', async () => {
        const wrapper = render();
        await openDetail(wrapper);

        expect(wrapper.text()).toContain('The dashboard totals look wrong after an import.');
        expect(wrapper.text()).toContain('Demo Workspace');
        expect(wrapper.text()).toContain('owner@demo.test');

        wrapper.unmount();
    });

    it('renders whatever browser_info keys are present rather than assuming a fixed pair', async () => {
        const wrapper = render({ data: [row({ browser_info: { platform: 'demo-seed', locale: 'en-PH' } })] });
        await openDetail(wrapper);

        expect(wrapper.text()).toContain('platform');
        expect(wrapper.text()).toContain('demo-seed');
        expect(wrapper.text()).toContain('locale');

        wrapper.unmount();
    });

    it('links the screenshot at the console route, never the tenant one', async () => {
        const wrapper = render({ data: [row({ has_screenshot: true })] });
        await openDetail(wrapper);

        const image = wrapper.find('img[alt="Screenshot attached to this report"]');

        expect(image.exists()).toBe(true);
        expect(image.attributes('src')).toBe('/admin/feedback/0192e2e0-0000-7000-8000-000000000001/screenshot');

        wrapper.unmount();
    });

    it('surfaces a refused transition as the shared admin error, not a silent no-op', () => {
        mocks.pageProps.errors = { admin: 'A feedback report cannot go from New to Resolved.' };

        const wrapper = render();

        expect(wrapper.find('[role="alert"]').text()).toContain('cannot go from New to Resolved');

        wrapper.unmount();
    });
});

describe('feedback console — navigation', () => {
    it('replaces history when filtering by workspace', async () => {
        const wrapper = render();

        const selects = wrapper.findAll('select');
        await selects[1].setValue('0192e2e0-0000-7000-8000-0000000000aa');

        expect(mocks.get.mock.calls[0][0]).toBe('/admin/feedback');
        expect(mocks.get.mock.calls[0][1]).toEqual({ tenant_id: '0192e2e0-0000-7000-8000-0000000000aa' });
        expect(mocks.get.mock.calls[0][2]).toMatchObject({ replace: true });

        wrapper.unmount();
    });

    it('distinguishes an empty queue from a filter that matched nothing', () => {
        const filtered = render({ data: [], empty_reason: 'no_matches' });
        expect(filtered.text()).toContain('No matching reports');
        filtered.unmount();

        const empty = render({ data: [], empty_reason: 'no_rows' });
        expect(empty.text()).toContain('No feedback yet');
        empty.unmount();
    });

    it('keeps the filter heading that axe heading-order depends on, in both states', () => {
        // ⭐ ADDED IN J2e, AND IT WAS MISSING BEFORE — this page's sibling (admin/AuditLog.vue) has had the
        // equivalent case since I11a, while this one relied entirely on the /admin/feedback axe scan. That
        // scan is real coverage, but it runs only in CI and only against a SEEDED database, so the state the
        // heading actually protects — an empty list under AdminLayout's h1 and MdsEmptyState's h3 — is the
        // one it is least likely to reach. Migrating the markup into MdsFilterBar was the cheapest possible
        // moment to close that.
        const populated = render();
        expect(populated.find('.mds-filterbar h2').text()).toBe('Filters');
        populated.unmount();

        const empty = render({ data: [], empty_reason: 'no_rows' });
        expect(empty.find('.mds-filterbar h2').text()).toBe('Filters');
        empty.unmount();
    });
});

describe('feedback console — what kind, and which page (M175, `R-2ce2f274`)', () => {
    it('shows what kind each report is, and filters the queue by it', async () => {
        const wrapper = render({ data: [row({ type: 'suggestion', type_label: 'Suggestion' })] });

        // The table body, not the page: the kind filter lists every kind's name as an option.
        expect(wrapper.find('tbody').text()).toContain('Suggestion');

        await wrapper.find('select#admin-feedback-type').setValue('question');

        expect(mocks.get.mock.calls[0][0]).toBe('/admin/feedback');
        expect(mocks.get.mock.calls[0][1]).toEqual({ type: 'question' });
        expect(mocks.get.mock.calls[0][2]).toMatchObject({ replace: true });

        wrapper.unmount();
    });

    it('names the page by its title, linked to the address the report was sent from', async () => {
        const address = 'https://acme.meridian.test/forms/1/builder?tab=logic';
        const wrapper = render({ data: [row({ page_title: 'Builder — Household survey', page_url: address })] });
        await openDetail(wrapper);

        const link = wrapper.find(`a[href="${address}"]`);
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('Builder — Household survey');

        wrapper.unmount();
    });
});

describe('feedback console — the reply and the reporter (M175, `R-9bfd832e`)', () => {
    it('sends the reply typed in the note box with the new status', async () => {
        const wrapper = render({
            data: [row({ transitions: [{ value: 'resolved', label: 'Resolved' }, { value: 'wont_fix', label: 'Declined' }] })],
        });
        await openDetail(wrapper);

        await wrapper.find('textarea#admin-feedback-note').setValue('Fixed in version abc1234, live on staging.');
        const button = wrapper.findAll('button').find((candidate) => candidate.text() === 'Mark Resolved');
        await button!.trigger('click');

        expect(mocks.patch.mock.calls[0][1]).toEqual({ status: 'resolved', note: 'Fixed in version abc1234, live on staging.' });

        wrapper.unmount();
    });

    it("says the reporter is told, and shows a reply already sent", async () => {
        const wrapper = render({ data: [row({ status: 'resolved', status_label: 'Resolved', resolution_note: 'Fixed last week.' })] });
        await openDetail(wrapper);

        expect(wrapper.text()).toContain('Fixed last week.');
        expect(wrapper.text()).toMatch(/the reporter and everyone in the thread are told/i);

        wrapper.unmount();
    });

    it("names the reporter's role and email beside their name", async () => {
        const wrapper = render();
        await openDetail(wrapper);

        expect(wrapper.text()).toContain('Form Editor');
        expect(wrapper.text()).toContain('owner@demo.test');

        wrapper.unmount();
    });
});

describe('feedback console — the thread and a reply in it (M179, D151)', () => {
    it('shows each message with who wrote it, and where a relayed report came from', async () => {
        const wrapper = render({
            data: [
                row({
                    source: 'relayed',
                    reference: 'U3',
                    title: 'Larger label box',
                    source_label: 'Your items, Oct 16',
                    route: '',
                    status: 'confirmed',
                    status_label: 'Confirmed working',
                    confirmer: { name: 'Tess Tester', email: 'tess@demo.test' },
                    comments: [
                        { id: 'c1', author_kind: 'member', author: { name: 'Tess Tester', email: 'tess@demo.test' }, action: 'confirmed', body: null, created_at: '2026-10-20T01:00:00+00:00', has_screenshot: false },
                        { id: 'c2', author_kind: 'support', author: null, action: null, body: 'Thanks for checking.', created_at: '2026-10-20T02:00:00+00:00', has_screenshot: false },
                        { id: 'c3', author_kind: 'member', author: null, action: null, body: 'Me too.', created_at: '2026-10-20T03:00:00+00:00', has_screenshot: false },
                    ],
                }),
            ],
        });
        await openDetail(wrapper);

        const thread = wrapper.find('[data-console-thread]');
        expect(wrapper.text()).toContain('Larger label box');
        expect(wrapper.text()).toContain('Your items, Oct 16');
        expect(thread.text()).toContain('Tess Tester');
        expect(thread.text()).toContain('said it works');
        expect(thread.text()).toContain('Support team');
        expect(thread.text()).toContain('Former member');

        wrapper.unmount();
    });

    it('posts a reply to the report\'s thread, and refuses an empty one', async () => {
        const post = (await import('@inertiajs/vue3')).router.post as ReturnType<typeof vi.fn>;
        post.mockClear();

        const wrapper = render();
        await openDetail(wrapper);

        await wrapper.find('[data-console-reply]').trigger('click');
        expect(post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Write the reply first.');

        await wrapper.find('#admin-feedback-reply').setValue('Which browser are you on?');
        await wrapper.find('[data-console-reply]').trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin/feedback/0192e2e0-0000-7000-8000-000000000001/comments');
        expect(post.mock.calls[0][1]).toEqual({ body: 'Which browser are you on?' });

        wrapper.unmount();
    });
});
