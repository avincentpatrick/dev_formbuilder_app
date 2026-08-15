import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

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
        viewAuditLog: true,
        viewFeedback: true,
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

/**
 * The Audit log destination (Increment I2).
 *
 * SHAPED DIFFERENTLY FROM ITS NEIGHBOURS ON PURPOSE. Analytics, Webhooks, Integrations and Domains each
 * AND a permission with a plan feature, so their suites include an `entitlements: null` fail-closed case.
 * This item has NO `feature:` — PlanCatalog carries no audit key on any tier, because an audit trail is a
 * baseline obligation rather than an upsell — so an entitlements case here would pass for a reason that has
 * nothing to do with the claim (it would be exercising `can.*`, not the entitlement path). Its absence is
 * deliberate; do not add one back by symmetry.
 *
 * The negative that DOES matter is the ROLE one. RolePermissionSeeder grants `audit_log.view` to Owner and
 * Admin only and excludes Viewer with a comment saying so, and Playwright only ever loads the Owner
 * fixture — so this is the only thing in the suite proving a Viewer, Reviewer or Form Editor never sees a
 * destination that would show them every act in the organization.
 */
/**
 * The Feedback destination (Increment I7a, PRD Feature #11).
 *
 * Shaped like the Audit log's suite and for the same two reasons: no `feature:` (seeing what your own
 * people reported about the product is a baseline, not a tier), and a ROLE negative that nothing else
 * covers. `RolePermissionSeeder` grants `feedback.submit` to every role but `feedback.view` to Owner and
 * Admin only — so a Viewer who can SEND feedback must not see the destination that READS the workspace's
 * whole pile of it, and Playwright only ever loads the Owner fixture.
 */
describe('Sidebar — the Feedback destination', () => {
    it('shows Feedback to a user who may read the workspace list', () => {
        const wrapper = render(allAbilities(), {});

        expect(labels(wrapper).join(' ')).toContain('Feedback');
        wrapper.unmount();
    });

    it('HIDES it from a user without viewFeedback, even with every entitlement on', () => {
        // The Viewer case: they still hold feedback.submit and the shell's Send Feedback button, which is
        // exactly why the destination disappearing is the thing worth asserting.
        const wrapper = render(allAbilities({ viewFeedback: false }), {
            advanced_analytics: true,
            webhooks: true,
            native_connectors: true,
            custom_domain: true,
        });

        expect(labels(wrapper).join(' ')).not.toContain('Feedback');
        wrapper.unmount();
    });

    it('shows it on a plan with NO features at all — it is not an upsell', () => {
        const wrapper = render(allAbilities(), {});

        expect(labels(wrapper).join(' ')).toContain('Feedback');
        expect(labels(wrapper).join(' ')).not.toContain('Analytics');
        wrapper.unmount();
    });
});

describe('Sidebar — the Audit log destination', () => {
    it('shows the Audit log to a user who may read the ledger', () => {
        const wrapper = render(allAbilities(), {});

        expect(labels(wrapper).join(' ')).toContain('Audit log');
        wrapper.unmount();
    });

    it('HIDES it from a user without viewAuditLog, even with every entitlement on', () => {
        const wrapper = render(allAbilities({ viewAuditLog: false }), {
            advanced_analytics: true,
            webhooks: true,
            native_connectors: true,
            custom_domain: true,
        });

        expect(labels(wrapper).join(' ')).not.toContain('Audit log');
        wrapper.unmount();
    });

    it('shows it on a plan with NO features at all — it is not an upsell', () => {
        // The property that makes it unlike its four neighbours: a Free tenant that can see nothing else
        // in this group still gets its own accountability record.
        const wrapper = render(allAbilities(), {});

        expect(labels(wrapper).join(' ')).toContain('Audit log');
        expect(labels(wrapper).join(' ')).not.toContain('Analytics');
        wrapper.unmount();
    });
});

/**
 * Grouping (J4b). The nav was a flat twelve whose grouping lived only in three comments; this is the
 * data model catching up with the prose. Nothing about which items a user sees changes — the cases above
 * pass unedited, which is the evidence — so what needs proving is the structure and, above all, that a
 * heading never appears over nothing.
 */
describe('Sidebar — grouping', () => {
    const ALL_FEATURES = {
        advanced_analytics: true,
        webhooks: true,
        native_connectors: true,
        custom_domain: true,
    };

    /** Group labels only — the helper above deliberately cannot see them, which is the point of the <div>. */
    function groupLabels(wrapper: VueWrapper): string[] {
        return wrapper.findAll('.sidebar__group-label').map((n) => n.text().trim());
    }

    it('does not reorder the nav — the flat order is derived from the groups', async () => {
        // ⭐ The whole claim that this change is presentational. `navItems` is built by flattening the
        // groups, so if a future edit moves an item between runs this is what notices.
        const { navGroups, navItems } = await import('./nav-model');

        expect(navItems.map((i) => i.key)).toEqual([
            'forms', 'submissions', 'dashboard', 'analytics',
            'members', 'scopes', 'audit', 'feedback',
            'webhooks', 'integrations', 'domains',
            'settings',
        ]);
        expect(navGroups.flatMap((g) => g.items.map((i) => i.key))).toEqual(navItems.map((i) => i.key));
    });

    it('gives every item exactly one group', async () => {
        // Cheap, and it catches the copy-paste that lands an item in two runs or drops it from all of them.
        const { navGroups } = await import('./nav-model');
        const keys = navGroups.flatMap((g) => g.items.map((i) => i.key));

        expect(new Set(keys).size).toBe(keys.length);
        expect(new Set(navGroups.map((g) => g.key)).size).toBe(navGroups.length);
    });

    it('puts nothing but list items directly inside a list', () => {
        // ⭐ axe's `list` rule is WCAG-tagged and therefore inside the end-to-end scan's tag set, and the
        // sidebar is scanned on every whole-page assertClean — so the obvious markup (a heading between the
        // <ul> and its <li>s) would redden roughly forty cases at once. This asserts it in milliseconds
        // instead, which is the only reason it is cheap to get right.
        const wrapper = render(allAbilities(), ALL_FEATURES);

        for (const list of wrapper.findAll('ul')) {
            for (const child of Array.from(list.element.children)) {
                expect(child.tagName).toBe('LI');
            }
        }
        wrapper.unmount();
    });

    it('labels the two runs that need a name, and leaves the other two unlabelled', () => {
        const wrapper = render(allAbilities(), ALL_FEATURES);

        expect(groupLabels(wrapper)).toEqual(['Administration', 'Connections']);
        expect(wrapper.findAll('.sidebar__group')).toHaveLength(4);
        wrapper.unmount();
    });

    it('renders a group label as a DIV, and never as text the item-label helper can see', () => {
        // ⭐ TWO GATES PIN THIS ELEMENT TYPE AND THEY PULL IN OPPOSITE DIRECTIONS. A <span> would join
        // `labels()`, whose six negative assertions above check that four item names are ABSENT for gated
        // users — a group called "Connections" is safe, but the guard has to exist before someone renames
        // one. An <h2> would put shell chrome at the top of every page's heading outline, since this nav
        // renders ahead of the page's own <h1>.
        const wrapper = render(allAbilities(), ALL_FEATURES);

        for (const label of wrapper.findAll('.sidebar__group-label')) {
            expect(label.element.tagName).toBe('DIV');
            expect(label.findAll('span')).toHaveLength(0);
        }

        const itemText = labels(wrapper).join(' ');
        for (const name of groupLabels(wrapper)) {
            expect(itemText).not.toContain(name);
        }
        wrapper.unmount();
    });

    it('names a labelled list through aria-labelledby, and leaves an unlabelled one with no attribute', () => {
        // An empty string would be a dangling idref rather than an absent name, which axe flags and which
        // reads to a screen reader as a group named after nothing.
        const wrapper = render(allAbilities(), ALL_FEATURES);
        const lists = wrapper.findAll('ul');

        expect(lists[0]?.attributes('aria-labelledby')).toBeUndefined();

        const named = lists.filter((l) => l.attributes('aria-labelledby') !== undefined);
        expect(named).toHaveLength(2);
        for (const list of named) {
            const id = list.attributes('aria-labelledby');
            expect(wrapper.find(`#${id}`).exists()).toBe(true);
        }
        wrapper.unmount();
    });

    it('carries list semantics explicitly, since list-style: none strips them in Safari', () => {
        const wrapper = render(allAbilities(), ALL_FEATURES);

        for (const list of wrapper.findAll('ul')) {
            expect(list.attributes('role')).toBe('list');
        }
        wrapper.unmount();
    });

    it('drops a group whose items are ALL gated away, rather than heading an empty run', () => {
        // ⭐ THE CASE THE WHOLE FILTER EXISTS FOR, ON A REAL CONFIGURATION RATHER THAN A CONTRIVED ONE. An
        // Owner on Free holds every permission and no plan features, so all three Connections items vanish
        // while Administration stays whole. An unconditional heading prints a category over nothing here.
        const wrapper = render(allAbilities(), {});

        expect(groupLabels(wrapper)).toEqual(['Administration']);
        expect(labels(wrapper).join(' ')).not.toContain('Webhooks');
        wrapper.unmount();
    });

    it('renders no headings at all at the two-item floor', () => {
        // ⭐ Off-tenant chrome resolves every ability false and every feature absent, leaving only the two
        // ungated destinations. Two runs, two items, zero labels — a heading over one item is noise, and a
        // heading over none is a defect.
        const wrapper = render({}, {});

        expect(groupLabels(wrapper)).toEqual([]);
        expect(wrapper.findAll('.sidebar__group')).toHaveLength(2);
        expect(labels(wrapper).join(' ')).toContain('Dashboard');
        expect(labels(wrapper).join(' ')).toContain('Settings');
        wrapper.unmount();
    });
});

/**
 * The mobile drawer taking the page (J4b).
 *
 * Before this increment the drawer rendered a full-viewport scrim over a page that stayed entirely
 * tabbable and entirely announced — the defect DSR §3.6 forbids in as many words, and the one I10a had
 * already removed from MdsModal. None of it had any unit coverage at all.
 *
 * Everything here fails SILENTLY in a browser: a page left inert throws nothing and looks normal until a
 * keyboard user finds they cannot reach it. So these are assertions about state that has no appearance.
 */
describe('Sidebar — the drawer takes the page', () => {
    const ALL_FEATURES = {
        advanced_analytics: true,
        webhooks: true,
        native_connectors: true,
        custom_domain: true,
    };

    /**
     * happy-dom ships a real MediaQueryList, but stubbing keeps these independent of its media engine.
     *
     * ⚠️ IT MUST KEY ON THE QUERY, AND THE FIRST VERSION OF THIS HELPER DID NOT. The sidebar registers
     * TWO queries — the drawer's maximum and the rail's band — so a stub that ignores its argument hands
     * both listeners the same slot and the second registration silently overwrites the first. Firing then
     * drives the wrong one, and the case fails looking exactly like a component that forgot to emit.
     *
     * ⚠️ And the returned `fire` belongs to THIS stub. Calling `stubMatchMedia` again to fire a change
     * installs a fresh closure the mounted component never registered on.
     */
    function stubMatchMedia(matching: string[]): { fire: (query: string, next: boolean) => void } {
        const handlers = new Map<string, (e: MediaQueryListEvent) => void>();
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            configurable: true,
            value: (query: string) => ({
                matches: matching.includes(query),
                addEventListener: (_: string, fn: (e: MediaQueryListEvent) => void) => {
                    handlers.set(query, fn);
                },
                removeEventListener: () => handlers.delete(query),
            }),
        });
        return {
            fire: (query: string, next: boolean) =>
                handlers.get(query)?.({ matches: next } as MediaQueryListEvent),
        };
    }

    const MOBILE = '(max-width: 480px)';
    const RAIL = '(min-width: 481px) and (max-width: 1024px)';

    /**
     * ⚠️ MOUNTED WRAPPERS ARE TRACKED AND UNMOUNTED BEFORE THE BODY IS CLEARED, AND THIS IS NOT HYGIENE.
     * A case that fails mid-test never reaches its own `unmount()`, so a bare `innerHTML = ''` tears the
     * teleport anchors out from under a component that is still mounted — and the NEXT case then dies in
     * Vue's renderer with `Cannot read properties of null (reading 'insertBefore')`. That is one real
     * failure wearing the costume of two, and the second one points at innocent code.
     */
    const mounted: VueWrapper[] = [];

    function renderDrawer(open: boolean): VueWrapper {
        mocks.pageProps.props = {
            auth: { user: { name: 'Demo Owner' }, can: allAbilities() },
            entitlements: { features: ALL_FEATURES },
        };
        const wrapper = mount(Sidebar, { props: { drawerOpen: open }, attachTo: document.body });
        mounted.push(wrapper);
        return wrapper;
    }

    /** A stand-in for the shell content the walk should mark. */
    function background(): HTMLElement {
        const el = document.createElement('main');
        document.body.appendChild(el);
        return el;
    }

    afterEach(() => {
        while (mounted.length > 0) mounted.pop()?.unmount();
        document.body.innerHTML = '';
    });

    it('takes NOTHING above the mobile breakpoint, however open the drawer is', async () => {
        // ⭐ THE BUG THIS GATE EXISTS FOR. The seam is viewport-agnostic and `drawerOpen` is layout state
        // that survives Inertia visits, so without the media gate a stale-open drawer would mark <main>
        // inert at desktop — where there is no drawer and no scrim to explain why the page went dead.
        stubMatchMedia([RAIL]);
        const bg = background();
        const wrapper = renderDrawer(true);
        await flushPromises();

        expect(bg.hasAttribute('inert')).toBe(false);
        wrapper.unmount();
    });

    it('marks the page inert at the mobile breakpoint and releases it on close', async () => {
        stubMatchMedia([MOBILE]);
        const bg = background();
        const wrapper = renderDrawer(false);
        await flushPromises();
        expect(bg.hasAttribute('inert')).toBe(false);

        await wrapper.setProps({ drawerOpen: true });
        await flushPromises();
        expect(bg.hasAttribute('inert')).toBe(true);

        await wrapper.setProps({ drawerOpen: false });
        await flushPromises();
        expect(bg.hasAttribute('inert')).toBe(false);

        wrapper.unmount();
    });

    it('never marks its own scrim, which is what the filed blocker said it would', async () => {
        // ⭐ THE ASSERTION THAT REFUTES THE BACKLOG ROW. It held that this stack could not be reused
        // because the walk "would mark its own siblings within the shell". The scrim is a CHILD of the
        // pushed root, so it is on the path and never visited — and if it were marked, the drawer's
        // primary dismiss affordance would stop taking clicks.
        stubMatchMedia([MOBILE]);
        background();
        const wrapper = renderDrawer(true);
        await flushPromises();

        const scrim = document.querySelector('.sidebar-scrim') as HTMLElement;
        expect(scrim).not.toBeNull();
        expect(scrim.hasAttribute('inert')).toBe(false);
        expect(scrim.closest('[inert]')).toBeNull();

        wrapper.unmount();
    });

    it('moves focus into the drawer on open', async () => {
        // ⭐ Not a nicety: by this point the opener is inside the inert subtree, so leaving focus there
        // makes the user agent drop it to <body> — from where the root-bound Escape handler can never
        // fire. That is a keyboard trap, reached through the very change meant to prevent one.
        stubMatchMedia([MOBILE]);
        background();
        const wrapper = renderDrawer(true);
        await flushPromises();

        expect(document.activeElement).toBe(document.querySelector('.sidebar__item'));
    });

    it('closes itself when the viewport grows past the breakpoint', async () => {
        const media = stubMatchMedia([MOBILE]);
        const wrapper = renderDrawer(true);
        await flushPromises();

        media.fire(MOBILE, false);
        await flushPromises();

        expect(wrapper.emitted('close')).toBeTruthy();
    });

    it('emits close on Escape while open, and stays silent while closed', async () => {
        stubMatchMedia([MOBILE]);
        const wrapper = renderDrawer(true);
        await flushPromises();

        await wrapper.get('#app-drawer').trigger('keydown', { key: 'Escape' });
        expect(wrapper.emitted('close')).toHaveLength(1);

        const closed = renderDrawer(false);
        await closed.get('#app-drawer').trigger('keydown', { key: 'Escape' });
        expect(closed.emitted('close')).toBeUndefined();
    });

    it('releases the page if it unmounts while open', async () => {
        // A drawer unmounted mid-navigation would otherwise strand the page it left behind.
        stubMatchMedia([MOBILE]);
        const bg = background();
        const wrapper = renderDrawer(true);
        await flushPromises();
        expect(bg.hasAttribute('inert')).toBe(true);

        wrapper.unmount();
        expect(bg.hasAttribute('inert')).toBe(false);
    });
});
