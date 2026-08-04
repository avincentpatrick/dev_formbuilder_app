import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The shell's nav gating (Increment H24b2 — the repo's first Sidebar test).
 *
 * `Sidebar.vue` has ANDed a NavItem's `gate` and `feature` since H14, and the Analytics item is a one-line
 * addition to that list — so what needs proving is not that it RENDERS (Playwright loads the seeded
 * Business tenant and would catch that) but that it DOES NOT, for the two tiers and roles that must never
 * see it. A negative is exactly what an e2e fixture on one entitled tenant cannot demonstrate.
 *
 * ADR-0011 §D9 makes the negative load-bearing rather than cosmetic: Business is seeded `is_active: false`,
 * so a visible-but-locked item would offer a plan nobody can buy.
 */

const mocks = vi.hoisted(() => ({
    pageProps: {
        url: '/dashboard',
        props: {} as Record<string, unknown>,
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => mocks.pageProps,
}));

const Sidebar = (await import('./Sidebar.vue')).default;

/** Every ability true, so `feature` is the only variable unless a case says otherwise. */
function allAbilities(overrides: Record<string, boolean> = {}): Record<string, boolean> {
    return {
        manageMembers: true,
        transferOwnership: true,
        manageForms: true,
        viewSubmissions: true,
        manageScopes: true,
        manageWebhooks: true,
        manageIntegrations: true,
        viewAnalytics: true,
        manageDomains: true,
        ...overrides,
    };
}

function render(can: Record<string, boolean>, features: Record<string, boolean>): VueWrapper {
    mocks.pageProps.props = {
        auth: { user: { name: 'Demo Owner' }, can },
        entitlements: { features },
    };

    return mount(Sidebar);
}

function labels(wrapper: VueWrapper): string[] {
    return wrapper.findAll('.app-sidebar__item, .app-sidebar__link, nav a, nav span').map((n) => n.text().trim());
}

describe('Sidebar — the Analytics destination', () => {
    it('shows Analytics to an entitled tenant whose user may read it', () => {
        const wrapper = render(allAbilities(), { advanced_analytics: true });

        expect(labels(wrapper).join(' ')).toContain('Analytics');
        wrapper.unmount();
    });

    it('HIDES Analytics from a tenant whose plan lacks advanced_analytics', () => {
        // Playwright proves the positive on the seeded Business tenant; only this proves the negative, and
        // the negative is the decision.
        const wrapper = render(allAbilities(), { advanced_analytics: false });

        expect(labels(wrapper).join(' ')).not.toContain('Analytics');
        wrapper.unmount();
    });

    it('hides Analytics from a user without viewAnalytics even on an entitled plan', () => {
        const wrapper = render(allAbilities({ viewAnalytics: false }), { advanced_analytics: true });

        expect(labels(wrapper).join(' ')).not.toContain('Analytics');
        wrapper.unmount();
    });

    it('hides it when there is no entitlement snapshot at all — fail closed', () => {
        // `entitlements` is null off-tenant. A `?.` that resolved to "show it" would put a destination in
        // front of someone who would only bounce off its route guard.
        mocks.pageProps.props = {
            auth: { user: { name: 'Demo Owner' }, can: allAbilities() },
            entitlements: null,
        };
        const wrapper = mount(Sidebar);

        expect(labels(wrapper).join(' ')).not.toContain('Analytics');
        wrapper.unmount();
    });

    it('leaves the ungated items alone, so the gate is not simply hiding everything', () => {
        const wrapper = render(allAbilities(), { advanced_analytics: false });

        const text = labels(wrapper).join(' ');
        expect(text).toContain('Dashboard');
        expect(text).toContain('Settings');
        wrapper.unmount();
    });
});

/**
 * The Domains destination (Increment H22b).
 *
 * ⚠️ HIDING THE NAV ITEM IS NOT THE SAME AS CLOSING THE SURFACE, and that asymmetry is the reason these
 * tests are worth their lines. ADR-0012 §D9 leaves `/domains`' read and delete UNGATED on the plan feature,
 * because a tenant downgraded off Business keeps a LIVE, resolving hostname and must be able to remove it.
 * So a reader who sees this item disappear on downgrade must not conclude the page went with it — the
 * Settings card (pinned in DomainWebTest) is the remaining path, and Playwright covers the positive on the
 * seeded Business tenant.
 */
describe('Sidebar — the Domains destination', () => {
    it('shows Domains to an entitled tenant whose user may manage settings', () => {
        const wrapper = render(allAbilities(), { custom_domain: true });

        expect(labels(wrapper).join(' ')).toContain('Domains');
        wrapper.unmount();
    });

    it('HIDES Domains from a tenant whose plan lacks custom_domain', () => {
        // Business is seeded is_active:false (ADR-0008 §D6), so a visible-but-locked item would offer an
        // upgrade to a plan nobody can buy — the same reasoning that hides Analytics.
        const wrapper = render(allAbilities(), { custom_domain: false });

        expect(labels(wrapper).join(' ')).not.toContain('Domains');
        wrapper.unmount();
    });

    it('hides Domains from a user without manageDomains even on an entitled plan', () => {
        const wrapper = render(allAbilities({ manageDomains: false }), { custom_domain: true });

        expect(labels(wrapper).join(' ')).not.toContain('Domains');
        wrapper.unmount();
    });

    it('hides it when there is no entitlement snapshot at all — fail closed', () => {
        mocks.pageProps.props = {
            auth: { user: { name: 'Demo Owner' }, can: allAbilities() },
            entitlements: null,
        };
        const wrapper = mount(Sidebar);

        expect(labels(wrapper).join(' ')).not.toContain('Domains');
        wrapper.unmount();
    });
});
