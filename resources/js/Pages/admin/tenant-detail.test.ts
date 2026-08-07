import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    ConsoleDomainRow,
    PlanCatalogEntry,
    TenantDetailPageProps,
    UsageRow,
} from '@/components/admin/types';

/**
 * The super-admin workspace detail page (Increment I7b, RBAC §9 console scope).
 *
 * Admin pages are excluded from the Playwright axe sweep (`/admin/*` needs a TOTP in CI), so these unit
 * assertions are the only automated coverage this page has.
 *
 * ⚠️ Unlike `admin/feedback.test.ts`, this file's `@inertiajs/vue3` mock must provide `useForm` — and the
 * factory is therefore ASYNC, so `reactive` can be imported inside it. A hoisted `vi.mock` factory that
 * referenced a top-level import would throw at collection time.
 */

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    formPost: vi.fn(),
    forms: [] as Array<Record<string, unknown>>,
    pageProps: { errors: {} as Record<string, string>, auth: { user: { name: 'Platform Admin' }, can: {} } },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Head: { name: 'Head', render: () => null },
        Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: { get: mocks.get, post: mocks.post, patch: vi.fn() },
        usePage: () => ({ props: mocks.pageProps }),
        useForm: (fields: Record<string, unknown>) => {
            const form = reactive({
                ...fields,
                processing: false,
                recentlySuccessful: false,
                errors: {} as Record<string, string>,
                post: mocks.formPost,
                reset: vi.fn(),
                clearErrors: vi.fn(),
            });
            mocks.forms.push(form);

            return form;
        },
    };
});

// `props` declared on the stub so the page-heading assertion can read `title` — the workspace name lives
// only there, and a stub with no props would silently report it as undefined.
vi.mock('@/Layouts/AdminLayout.vue', () => ({
    default: { name: 'AdminLayout', props: ['title', 'icon'], template: '<main><slot /></main>' },
}));

const TenantDetail = (await import('./TenantDetail.vue')).default;

const TENANT_ID = '0192e2e0-0000-7000-8000-0000000000aa';
const FREE_ID = '0192e2e0-0000-7000-8000-0000000000f1';
const PRO_ID = '0192e2e0-0000-7000-8000-0000000000f2';
const BUSINESS_ID = '0192e2e0-0000-7000-8000-0000000000f3';

function plan(overrides: Partial<PlanCatalogEntry> = {}): PlanCatalogEntry {
    return {
        id: FREE_ID,
        code: 'free',
        name: 'Free',
        description: 'The starting tier.',
        is_active: true,
        sort_order: 0,
        feature_flags: { webhooks: false },
        interval_options: [
            { value: 'monthly', label: 'Monthly' },
            { value: 'yearly', label: 'Yearly' },
        ],
        ...overrides,
    };
}

function usageRow(overrides: Partial<UsageRow> = {}): UsageRow {
    return {
        metric: 'forms_count',
        label: 'Forms',
        limit: 20,
        used: 3,
        unlimited: false,
        display: '3 / 20',
        at_limit: false,
        ...overrides,
    };
}

function domainRow(overrides: Partial<ConsoleDomainRow> = {}): ConsoleDomainRow {
    return {
        domain: 'forms.example.test',
        status: 'live',
        verified_at: '2026-08-01T10:00:00+00:00',
        activated_at: '2026-08-02T10:00:00+00:00',
        last_checked_at: '2026-08-06T10:00:00+00:00',
        failure_reason: null,
        awaiting_operator: false,
        is_primary: true,
        is_public_host: true,
        can_be_primary: false,
        failure_hint: null,
        ...overrides,
    };
}

function props(overrides: Partial<TenantDetailPageProps> = {}): TenantDetailPageProps {
    return {
        tenant: {
            id: TENANT_ID,
            name: 'Demo Workspace',
            slug: 'demo',
            status: 'active',
            status_label: 'Active',
            is_active: true,
            created_at: '2026-07-01T09:00:00+00:00',
            default_locale: 'en',
            maintenance_mode: false,
            maintenance_message: null,
            app_host: 'demo.localhost',
            public_host: 'forms.example.test',
            owner: { name: 'Ada Owner', email: 'ada@demo.test' },
        },
        plan: {
            current: {
                plan_id: FREE_ID,
                code: 'free',
                name: 'Free',
                interval: 'monthly',
                interval_label: 'Monthly',
                stripe_status: 'active',
                subscription_name: 'default',
                assigned_at: '2026-07-01T09:00:00+00:00',
            },
            effective: { code: 'free', name: 'Free' },
            catalog: [
                plan(),
                plan({ id: PRO_ID, code: 'professional', name: 'Professional', sort_order: 2 }),
                plan({
                    id: BUSINESS_ID,
                    code: 'business',
                    name: 'Business',
                    sort_order: 3,
                    is_active: false,
                    interval_options: [{ value: 'yearly', label: 'Yearly' }],
                }),
            ],
            intervals: [
                { value: 'monthly', label: 'Monthly' },
                { value: 'yearly', label: 'Yearly' },
            ],
        },
        usage: {
            available: true,
            gauges: [usageRow(), usageRow({ metric: 'storage_bytes', label: 'Storage', display: '1.2 GB / 5.0 GB' })],
            flows: [usageRow({ metric: 'submissions_count', label: 'Submissions', display: '412 / 5,000', used: 412 })],
        },
        features: [{ key: 'webhooks', label: 'Webhooks', plan_grants: true, effective: false, reason: 'tenant_disabled' }],
        domains: { rows: [domainRow()], app_host: 'demo.localhost', public_host: 'forms.example.test' },
        ...overrides,
    };
}

function render(overrides: Partial<TenantDetailPageProps> = {}): VueWrapper {
    return mount(TenantDetail, { props: props(overrides), global: { stubs: { teleport: true } } });
}

beforeEach(() => {
    vi.clearAllMocks();
    mocks.forms.length = 0;
    mocks.pageProps.errors = {};
});

describe('admin/TenantDetail', () => {
    it('renders identity, owner and hosts', () => {
        const wrapper = render();
        const text = wrapper.text();

        // The workspace NAME is the page heading, which AdminLayout owns — assert the prop it is handed
        // rather than the body text, or this passes for the wrong reason once the layout is stubbed.
        expect(wrapper.findComponent({ name: 'AdminLayout' }).props('title')).toBe('Demo Workspace');
        expect(text).toContain('demo');
        expect(text).toContain('Ada Owner');
        expect(text).toContain('ada@demo.test');
        expect(text).toContain('demo.localhost');
    });

    it('says the owner is not an active member rather than rendering a dash', () => {
        // Null is an honest, distinct state — the workspace may have an owner_user_id whose membership was
        // revoked. An em dash would read as "no data" and send an operator looking for a bug.
        const text = render({ tenant: { ...props().tenant, owner: null } }).text();

        expect(text).toContain('Owner is not an active member');
    });

    it('offers every plan, labelling the held-from-sale tiers rather than hiding them', () => {
        // ADR-0008 §D6: Business is seeded inactive and stays admin-assignable. Hiding it would make the
        // form unable to do what SuperAdminAssignPlanTest proves the service can.
        const options = render().get('#tenant-plan').findAll('option').map((option) => option.text());

        expect(options).toContain('Free');
        expect(options).toContain('Professional');
        expect(options).toContain('Business — held from sale');
    });

    it('derives the interval options from the SELECTED plan', async () => {
        const wrapper = render();

        expect(wrapper.get('#tenant-interval').findAll('option')).toHaveLength(2);

        // Business offers yearly only.
        await wrapper.get('#tenant-plan').setValue(BUSINESS_ID);

        const options = wrapper.get('#tenant-interval').findAll('option').map((option) => option.text());
        expect(options).toEqual(['Yearly']);
    });

    it('posts the assignment to the plan route with the current selection', async () => {
        const wrapper = render();

        await wrapper.get('#tenant-plan').setValue(PRO_ID);
        await wrapper.get('form').trigger('submit');

        expect(mocks.formPost).toHaveBeenCalledWith(
            `/admin/tenants/${TENANT_ID}/plan`,
            expect.objectContaining({ preserveScroll: true }),
        );

        const form = mocks.forms[0];
        expect(form.plan_id).toBe(PRO_ID);
        expect(form.billing_interval).toBe('monthly');
    });

    it('pre-selects the current plan and interval', () => {
        render({
            plan: {
                ...props().plan,
                current: { ...props().plan.current!, plan_id: PRO_ID, interval: 'yearly' },
            },
        });

        expect(mocks.forms[0].plan_id).toBe(PRO_ID);
        expect(mocks.forms[0].billing_interval).toBe('yearly');
    });

    it('distinguishes no subscription from no catalog', () => {
        const noSubscription = render({ plan: { ...props().plan, current: null } }).text();
        expect(noSubscription).toContain('No subscription on record');

        const noCatalog = render({
            plan: { current: null, effective: null, catalog: [], intervals: [] },
            usage: { available: false, gauges: [], flows: [] },
            features: [],
        }).text();
        expect(noCatalog).toContain('No plan catalog seeded');
        expect(noCatalog).toContain('No plan resolved for this workspace');
    });

    it('renders a zero as a zero, never as an em dash', () => {
        // MdsStatTile's value is `?? EM_DASH`, so a genuine zero must arrive as a real string. The moment
        // anyone "simplifies" the binding to `row.used || null`, a zero becomes a dash.
        const text = render({
            usage: {
                available: true,
                gauges: [usageRow({ used: 0, display: '0 / 20' })],
                flows: [],
            },
        }).text();

        expect(text).toContain('0 / 20');
    });

    it('captions an unlimited metric instead of printing a fake ceiling', () => {
        const text = render({
            usage: {
                available: true,
                gauges: [usageRow({ limit: null, unlimited: true, display: '3' })],
                flows: [],
            },
        }).text();

        expect(text).toContain('Unlimited on this plan');
    });

    it('renders storage in bytes-with-units, formatted by the server', () => {
        expect(render().text()).toContain('1.2 GB / 5.0 GB');
    });

    it('explains a capability the plan grants but the workspace switched off', () => {
        expect(render().text()).toContain('Switched off by the workspace');
    });

    it('explains a grandfathered capability', () => {
        const text = render({
            features: [{ key: 'ocr_single', label: 'OCR (single)', plan_grants: false, effective: true, reason: 'legacy_override' }],
        }).text();

        expect(text).toContain('Grandfathered');
    });

    it('renders domains read-only, with no verify, primary or remove control anywhere', () => {
        // Pins the decision, not merely the current markup: a domain write from the console would succeed
        // and emit NO audit row, because CustomDomainService::auditDomain() fails silent off-context.
        const labels = render().findAll('button').map((button) => button.text().toLowerCase());

        expect(labels.some((label) => label.includes('verify'))).toBe(false);
        expect(labels.some((label) => label.includes('primary'))).toBe(false);
        expect(labels.some((label) => label.includes('remove'))).toBe(false);
        expect(labels.some((label) => label.includes('check dns'))).toBe(false);
    });

    it('badges the respondent host and shows an empty state when there are none', () => {
        expect(render().text()).toContain('Respondent host');

        const empty = render({
            domains: { rows: [], app_host: 'demo.localhost', public_host: 'demo.localhost' },
        }).text();
        expect(empty).toContain('No custom domains');
    });

    it('offers suspend for an active workspace and reactivate for a suspended one', () => {
        expect(render().text()).toContain('Suspend workspace');

        const suspended = render({
            tenant: { ...props().tenant, status: 'suspended', status_label: 'Suspended', is_active: false },
        }).text();
        expect(suspended).toContain('Reactivate workspace');
        expect(suspended).not.toContain('Suspend workspace');
    });

    it('posts the lifecycle action only after the confirm modal', async () => {
        const wrapper = render();

        await wrapper.findAll('button').find((button) => button.text() === 'Suspend workspace')!.trigger('click');
        expect(mocks.post).not.toHaveBeenCalled();

        await wrapper.findAll('button').find((button) => button.text() === 'Suspend')!.trigger('click');
        expect(mocks.post).toHaveBeenCalledWith(
            `/admin/tenants/${TENANT_ID}/suspend`,
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('surfaces a service refusal in an alert', () => {
        mocks.pageProps.errors = { admin: 'That workspace is already suspended.' };

        expect(render().get('[role="alert"]').text()).toContain('already suspended');
    });
});
