import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The hamburger's disclosure state (J4b — the repo's first TopNav test).
 *
 * Before this increment the button carried no `aria-expanded`, no `aria-controls`, and a label that said
 * "Open navigation" whether the drawer was open or shut. All three are invisible to every gate the project
 * runs: axe has no rule requiring a disclosure relationship, and a wrong label is still a label, so the
 * scan is green and a screen-reader user is simply told the wrong thing.
 */

const mocks = vi.hoisted(() => ({
    page: {
        component: 'Dashboard',
        url: '/dashboard',
        props: { auth: { user: { name: 'Demo Owner', email: 'owner@demo.test' }, can: {} } },
    },
    /** The reactive proxy the component actually reads. Assigned by the factory below. */
    live: null as null | { component: string; url: string; props: Record<string, unknown> },
}));

// ⚠️ THE PROXY IS REQUIRED BY EXACTLY ONE CASE AND HARMLESS TO THE REST — the same shape, and the same
// reason, as `AppLayout.test.ts`. `usePage()` is reactive in Inertia, and M23's search-query computed
// depends on it; against a PLAIN object the arrival case still passes while the reactivity case cannot,
// so a half-extended stub would report the fix as broken rather than the stub as too weak. Setting values
// on `mocks.page` before mount still works for every pre-M23 case, because reads pass through the proxy;
// only change NOTIFICATION has to go through `mocks.live`.
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    mocks.live = reactive(mocks.page);

    return {
        Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: { post: vi.fn(), visit: vi.fn() },
        usePage: () => mocks.live,
    };
});

const TopNav = (await import('./TopNav.vue')).default;

const stubs = {
    NotificationBell: true,
    FeedbackButton: true,
    AccountMenu: true,
    ThemeQuickToggle: true,
};

function render(drawerOpen: boolean, component = 'Dashboard', url = '/dashboard'): VueWrapper {
    mocks.page.component = component;
    mocks.page.url = url;

    return mount(TopNav, { props: { scrolled: false, drawerOpen }, global: { stubs } });
}

describe('TopNav — the hamburger is a disclosure control', () => {
    it('reports the drawer’s state and points at it', () => {
        const closed = render(false);
        const button = closed.get('.topnav__hamburger');

        expect(button.attributes('aria-expanded')).toBe('false');
        expect(button.attributes('aria-controls')).toBe('app-drawer');
        closed.unmount();

        const open = render(true);
        expect(open.get('.topnav__hamburger').attributes('aria-expanded')).toBe('true');
        open.unmount();
    });

    it('changes what it says it will do', () => {
        // ⭐ The label was fixed at "Open navigation" in both states, so a screen-reader user was told the
        // button would open something that was already open. The visual affordance never carried the
        // difference either — it is the same glyph — so nothing on screen contradicted it.
        expect(render(false).get('.topnav__hamburger').attributes('aria-label')).toBe('Open navigation');
        expect(render(true).get('.topnav__hamburger').attributes('aria-label')).toBe('Close navigation');
    });

    it('asks the layout to toggle rather than deciding for itself', () => {
        const wrapper = render(false);
        wrapper.get('.topnav__hamburger').trigger('click');

        expect(wrapper.emitted('toggle-drawer')).toHaveLength(1);
        wrapper.unmount();
    });

    it('keeps `aria-controls` resolvable at every width, because the drawer is never v-if’d away', () => {
        // The referenced element is hidden with `visibility`, not removed — so the idref always resolves and
        // `aria-valid-attr-value` has nothing to flag. Recorded because the tempting "optimisation" of
        // rendering the drawer only below 480px would silently break this attribute at every other width.
        const wrapper = render(false);
        expect(wrapper.get('.topnav__hamburger').attributes('aria-controls')).toBe('app-drawer');
        wrapper.unmount();
    });
});

/**
 * The global search field's seeded value (Increment M23).
 *
 * ⛔ THE BUG WAS THAT THIS BAR IS NEVER REMOUNTED. `app.ts` assigns `AppLayout` at module level, so Inertia
 * patches one layout instance for the life of the tab, and the field seeded itself from a computed over
 * `window.location.search` — no reactive dependency, so it evaluated once and held that value through every
 * client-side visit underneath it. The docblock it replaced asserted that a browser Back "arrives as a
 * fresh render"; Inertia intercepts popstate and swaps the page in place, so Back was broken too.
 *
 * ⛔ AND THE HALF THE BACKLOG ROW DID NOT HAVE: `q` is NOT this feature's private parameter. Six other list
 * pages — forms, members, webhooks, the audit ledger, feedback, the submissions inbox — use `?q=` as their
 * own filter key and commit it client-side with `preserveState`. Reading it unconditionally would put the
 * audit ledger's filter term into a box labelled "Search this workspace", where pressing Enter posts it to
 * `/search`. The last two cases are that regression, pinned so it cannot be reintroduced by a "simplifying"
 * removal of the component gate.
 */
describe('TopNav — the global search field shows the active query', () => {
    const field = (wrapper: VueWrapper) => wrapper.get('#topnav-search').element as HTMLInputElement;

    it('shows the query the results page is running, on arrival', () => {
        const wrapper = render(false, 'search/Index', '/search?q=clinic%20intake&entity=forms');

        // Also pins the decode (%20 → space) and that a second parameter does not confuse the parse.
        expect(field(wrapper).value).toBe('clinic intake');

        wrapper.unmount();
    });

    it('tracks a client-side visit, because this component is never remounted', async () => {
        // ⭐ THE DEFECT ITSELF, and the only case that cannot pass against the old code under any
        // environment: a computed with no reactive dependency never invalidates, so the field stays ''.
        // It is also the case that needs the reactive proxy — against a plain-object stub it fails even
        // WITH the fix, which is why the stub had to be extended rather than reused.
        const wrapper = render(false);
        expect(field(wrapper).value).toBe('');

        mocks.live!.component = 'search/Index';
        mocks.live!.url = '/search?q=clinic';
        await wrapper.vm.$nextTick();

        expect(field(wrapper).value).toBe('clinic');
        wrapper.unmount();
    });

    it('stays empty on a list page that uses ?q= as its own filter', async () => {
        // ⭐ THE REGRESSION THE ROW WOULD HAVE SHIPPED. Mutation: delete the `page.component` gate from
        // `activeQuery` and this reddens with 'overdue'. The workspace-search box would then be pre-filled
        // with the audit ledger's filter term, and Enter would post it to /search — silently turning
        // "filter this list" into "search everything".
        const wrapper = render(false, 'audit/Index', '/audit-log?q=overdue');

        expect(field(wrapper).value).toBe('');

        mocks.live!.url = '/audit-log?q=overdue&actor=me';
        await wrapper.vm.$nextTick();

        expect(field(wrapper).value).toBe('');
        wrapper.unmount();
    });

    it('does not clobber what the user is typing when an unrelated visit lands', async () => {
        // The field is deliberately uncontrolled — `:model-value` with no `v-model` — so it only re-renders
        // when the computed's VALUE changes. This pins that a visit which leaves `q` alone leaves the
        // half-typed text alone, and turns red the moment someone "improves" the binding with a watcher, a
        // mirror ref, or a v-model, all of which would write the field on every url change.
        const wrapper = render(false, 'search/Index', '/search?q=clinic');
        await wrapper.get('#topnav-search').setValue('clinic intake');

        mocks.live!.url = '/search?q=clinic&entity=forms';
        await wrapper.vm.$nextTick();

        expect(field(wrapper).value).toBe('clinic intake');
        wrapper.unmount();
    });
});
