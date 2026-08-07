import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ConsoleFeedbackPageProps, ConsoleFeedbackRow } from '@/components/feedback/types';

/**
 * The platform feedback support console (Increment I7a, PRD Feature #11 / RBAC §9).
 *
 * The assertion that matters most is that THE PAGE DOES NOT KNOW THE LIFECYCLE. Every button comes from
 * `row.transitions`, which the presenter fills from `FeedbackStatus::allowedTransitions()` — the same
 * source `SuperAdminService::transitionFeedback()` guards with. If this page ever re-derived the verbs in
 * TypeScript, the offered set and the accepted set would drift the first time the lifecycle changed, and
 * the symptom would be a button that errors rather than one that is missing.
 *
 * Admin pages are excluded from the Playwright axe sweep (`/admin/*` needs a TOTP in CI —
 * responsive-axe.spec.ts:61-65), so these unit assertions are the only automated coverage this page has.
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
        resolver: null,
        has_screenshot: false,
        transitions: [
            { value: 'reviewed', label: 'Reviewed' },
            { value: 'wont_fix', label: "Won't fix" },
        ],
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
            applied: { status: null, tenant_id: null },
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

        expect(labels).toContain('Mark Reviewed');
        expect(labels).toContain("Mark Won't fix");
        // `new` → `resolved` skips triage and the server refuses it, so it must not be offered here.
        expect(labels).not.toContain('Mark Resolved');

        wrapper.unmount();
    });

    it('offers only re-open on a closed report', async () => {
        const wrapper = render({
            data: [row({ status: 'resolved', status_label: 'Resolved', transitions: [{ value: 'reviewed', label: 'Reviewed' }] })],
        });
        await openDetail(wrapper);

        const labels = wrapper.findAll('button').map((button) => button.text());

        expect(labels).toContain('Mark Reviewed');
        expect(labels.filter((label) => label.startsWith('Mark'))).toHaveLength(1);

        wrapper.unmount();
    });

    it('PATCHes the chosen status, preserving scroll', async () => {
        const wrapper = render();
        await openDetail(wrapper);

        const button = wrapper.findAll('button').find((candidate) => candidate.text() === 'Mark Reviewed');
        await button!.trigger('click');

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        expect(mocks.patch.mock.calls[0][0]).toBe('/admin/feedback/0192e2e0-0000-7000-8000-000000000001');
        expect(mocks.patch.mock.calls[0][1]).toEqual({ status: 'reviewed' });
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
});
