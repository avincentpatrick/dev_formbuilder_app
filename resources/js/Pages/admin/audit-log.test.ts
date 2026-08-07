import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AuditChange, AuditRow, ConsoleAuditPageProps } from '@/components/audit/types';

/**
 * The PLATFORM audit ledger (Increment I7b, PRD Feature #12 / RBAC §9).
 *
 * Admin pages are excluded from the Playwright axe sweep (`/admin/*` needs a TOTP in CI —
 * responsive-axe.spec.ts), so these unit assertions are the ONLY automated coverage this page has. Two of
 * them exist specifically to stand in for gates that cannot run here: the filter heading (axe heading-order
 * fails without it, but only in the empty state) and the exact honesty clause in the empty-ledger copy.
 *
 * `AuditChangeModal` is deliberately NOT stubbed — mounting it for real is the point of the second case.
 * It is the tenant viewer's component used verbatim, and this is what proves it works from an admin page.
 */

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    pageProps: { errors: {} as Record<string, string>, auth: { user: { name: 'Platform Admin' }, can: {} } },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.get, patch: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: mocks.pageProps }),
}));

// Capital L — the real directory is resources/js/Layouts. Windows hides a casing mismatch that Linux CI
// would not.
vi.mock('@/Layouts/AdminLayout.vue', () => ({
    default: { name: 'AdminLayout', template: '<main><slot /></main>' },
}));

const AdminAuditLog = (await import('./AuditLog.vue')).default;

function change(overrides: Partial<AuditChange> = {}): AuditChange {
    return { key: 'registration.open_signup', old: 'true', new: 'false', redacted: false, ...overrides };
}

function row(overrides: Partial<AuditRow> = {}): AuditRow {
    return {
        id: '0192e2e0-0000-7000-8000-000000000001',
        created_at: '2026-08-07T09:30:00+00:00',
        event: 'updated',
        event_label: 'Updated',
        target: {
            type: 'settings',
            type_label: 'Settings',
            id: '0192e2e0-0000-7000-8000-0000000000ff',
            label: null,
            url: null,
        },
        actor: 'Platform Operator',
        is_system: false,
        ip_address: '203.0.113.9',
        changes: [change()],
        redacted_fields: [],
        ...overrides,
    };
}

function props(overrides: Partial<ConsoleAuditPageProps> = {}): ConsoleAuditPageProps {
    return {
        data: [row()],
        meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
        filters: {
            actors: [{ value: '0192e2e0-0000-7000-8000-0000000000a1', label: 'Platform Operator' }],
            applied: { user_id: null, from: null, to: null },
        },
        empty_reason: null,
        ...overrides,
    };
}

function render(overrides: Partial<ConsoleAuditPageProps> = {}): VueWrapper {
    return mount(AdminAuditLog, { props: props(overrides), global: { stubs: { teleport: true } } });
}

beforeEach(() => {
    vi.clearAllMocks();
    mocks.pageProps.errors = {};
});

describe('admin/AuditLog', () => {
    it('renders the event label and operator the server sent, never re-derived', () => {
        const text = render().text();

        expect(text).toContain('Updated');
        expect(text).toContain('Platform Operator');
    });

    it('opens the shared change modal and shows before and after', async () => {
        // ⭐ Proves `components/audit/AuditChangeModal.vue` works UNMODIFIED from an admin page — the reason
        // the platform presenter emits the same `AuditRow` shape (including two always-null target keys)
        // rather than a narrower type that would have forced a fork.
        const wrapper = render();

        await wrapper.get('button[aria-label="View changes"]').trigger('click');

        const text = wrapper.text();
        expect(text).toContain('registration.open_signup');
        expect(text).toContain('true');
        expect(text).toContain('false');
    });

    it('renders a redacted change as a badge, never as a value', async () => {
        // ⭐ Audit spec §2.1: the "before" value was never stored, so a renderer presenting either side as
        // real is advertising something the ledger does not have. No PHP test and no axe scan can see this.
        const wrapper = render({
            data: [row({ changes: [change({ key: 'secret', old: 'oldvalue', new: 'newvalue', redacted: true })] })],
        });

        await wrapper.get('button[aria-label="View changes"]').trigger('click');

        const text = wrapper.text();
        expect(text).toContain('Redacted');
        expect(text).not.toContain('oldvalue');
        expect(text).not.toContain('newvalue');
    });

    it('renders the target as its type label, without a bare uuid', () => {
        // The tenant page falls back to a truncated uuid when a target has no label; here that would fire
        // on EVERY row, and `auditable_id` is the acting operator's own user uuid — an object the operator
        // would reasonably try to go and find.
        const wrapper = render();
        const table = wrapper.get('table').text();

        expect(table).toContain('Settings');
        expect(table).not.toContain('0192e2e0-0000-7000-8000-0000000000ff');
        expect(table).not.toContain('0192e2e0');
    });

    it('filtering by operator replaces the history entry', async () => {
        const wrapper = render();

        await wrapper.get('#platform-audit-actor').setValue('0192e2e0-0000-7000-8000-0000000000a1');

        expect(mocks.get).toHaveBeenCalledWith(
            '/admin/audit-log',
            { user_id: '0192e2e0-0000-7000-8000-0000000000a1' },
            expect.objectContaining({ replace: true }),
        );
    });

    it('paging does NOT replace the history entry', async () => {
        // The asymmetry is the contract: a filter change rewrites where you are, a page change is a step.
        const wrapper = render({ meta: { current_page: 1, last_page: 3, total: 60, per_page: 25 } });

        await wrapper.findComponent({ name: 'Pagination' }).vm.$emit('update:page', 2);

        const options = mocks.get.mock.calls[0][2];
        expect(options).not.toHaveProperty('replace');
    });

    it('paging carries the active filters', async () => {
        const wrapper = render({ meta: { current_page: 1, last_page: 3, total: 60, per_page: 25 } });

        await wrapper.get('#platform-audit-actor').setValue('0192e2e0-0000-7000-8000-0000000000a1');
        await wrapper.findComponent({ name: 'Pagination' }).vm.$emit('update:page', 2);

        expect(mocks.get).toHaveBeenLastCalledWith(
            '/admin/audit-log',
            { user_id: '0192e2e0-0000-7000-8000-0000000000a1', page: 2 },
            expect.anything(),
        );
    });

    it('clearing filters visits with no params at all', async () => {
        const wrapper = render({
            filters: {
                actors: [{ value: '0192e2e0-0000-7000-8000-0000000000a1', label: 'Platform Operator' }],
                applied: { user_id: '0192e2e0-0000-7000-8000-0000000000a1', from: null, to: null },
            },
            data: [],
            empty_reason: 'no_matches',
        });

        await wrapper.get('button').trigger('click');

        expect(mocks.get).toHaveBeenCalledWith('/admin/audit-log', {}, expect.objectContaining({ replace: true }));
    });

    it('keeps the filter heading that axe heading-order depends on, in both states', () => {
        // ⭐ AdminLayout renders the h1 and MdsEmptyState renders an h3, so without this h2 the empty state
        // skips a level. /admin/* is never axe-scanned, so this assertion is the only guard that exists.
        expect(render().find('h2#platform-audit-filters-heading').exists()).toBe(true);
        expect(
            render({ data: [], empty_reason: 'no_rows' }).find('h2#platform-audit-filters-heading').exists(),
        ).toBe(true);
    });

    it('distinguishes an empty platform ledger from a filter that matched nothing', () => {
        expect(render({ data: [], empty_reason: 'no_matches' }).text()).toContain('No matching activity');

        // ⭐ The exact honesty clause. An operator reading an empty COMPLIANCE viewer with no explanation
        // reasonably concludes it is broken; this sentence is what stops that, so it is asserted verbatim.
        const empty = render({ data: [], empty_reason: 'no_rows' }).text();
        expect(empty).toContain('No platform activity yet');
        expect(empty).toContain('not because anything is wrong');
    });

    it('says platform actions only, so nobody looks here for a workspace action', () => {
        // Without this sentence an operator hunts for the suspension they performed five minutes ago,
        // does not find it, and files a bug. It is the user-facing form of the RBAC §9 posture.
        const text = render().text();

        expect(text).toContain('platform actions only');
        expect(text).toContain("that workspace's own audit log");
    });

    it('offers no export', () => {
        const labels = render().findAll('button').map((button) => button.text());

        expect(labels).not.toContain('Export CSV');
        expect(labels).not.toContain('Export XLSX');
    });

    it('surfaces a service refusal in an alert', () => {
        mocks.pageProps.errors = { admin: 'Something went wrong.' };

        expect(render().get('[role="alert"]').text()).toContain('Something went wrong.');
    });
});
