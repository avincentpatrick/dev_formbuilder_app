import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The shell's two layout modifiers (Increment JR4 — the repo's first AppLayout test).
 *
 * ⚠️ THIS EXISTS BECAUSE THE WIDTH CLASS HAS NO OTHER GATE AND FAILS SILENTLY. `.app-shell__inner--wide`
 * raises the content cap from 1200 to 1600, which changes NOTHING at any viewport Playwright runs (its
 * widest project is 1440×900, where the cap is not even reached) and nothing any Pest, PHPStan or axe
 * check can observe. Two ways it could ship as a no-op with all six CI jobs green: a page name typed
 * wrong in `WIDE_PAGES` — the strings are case-sensitive and not obvious (`Dashboard`, `forms/Index`,
 * `submissions/Inbox`) — or the modifier losing to `.app-shell__inner` on a specificity tie. This test
 * covers the first; source order in the stylesheet covers the second.
 *
 * The both-sets invariant is the other half: `forms/Builder` is full-bleed, and a future editor added to
 * both sets must keep `max-width: none` rather than acquire a 1600px cap.
 */

const mocks = vi.hoisted(() => ({
    page: {
        component: 'Dashboard',
        url: '/dashboard',
        props: {} as Record<string, unknown>,
    },
    /** The reactive proxy the component actually reads. Assigned by the factory below. */
    live: null as null | { component: string; url: string; props: Record<string, unknown> },
}));

// ⚠️ THE PROXY IS NEEDED FOR ONE CASE AND HARMLESS FOR THE REST. `usePage()` is reactive in Inertia, and
// J4b's drawer reset watches `page.url` — a plain object would let the watcher be written wrong and still
// pass. Mutating `mocks.page` directly still WORKS for every pre-J4b case, because those set their values
// before mounting and reads pass through the proxy; only change NOTIFICATION requires going through
// `mocks.live`, which is exactly what the navigation case does.
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    mocks.live = reactive(mocks.page);
    return { usePage: () => mocks.live };
});

const AppLayout = (await import('./AppLayout.vue')).default;

const stubs = {
    TopNav: true,
    Sidebar: true,
    ImpersonationBanner: true,
    CommandPalette: true,
    MdsToastHost: true,
};

function render(component: string): VueWrapper {
    mocks.page.component = component;
    mocks.page.props = {};

    return mount(AppLayout, { global: { stubs } });
}

function innerClasses(wrapper: VueWrapper): string[] {
    return wrapper.find('.app-shell__inner').classes();
}

describe('AppLayout — the wide list-page column', () => {
    it('leaves an ordinary page at the default cap', () => {
        // Settings is the worked example of why this is opt-IN: 640px cards in a 1600px column would be
        // stranded at the left with 900px of dead canvas.
        expect(innerClasses(render('Settings/Index'))).not.toContain('app-shell__inner--wide');
    });

    it.each([
        'Dashboard',
        'analytics/Index',
        'audit/Index',
        'feedback/Index',
        'forms/Index',
        'forms/Templates',
        'integrations/Index',
        'members/Index',
        'submissions/Inbox',
        'webhooks/Index',
    ])('widens %s', (component) => {
        expect(innerClasses(render(component))).toContain('app-shell__inner--wide');
    });

    it.each(['domains/Index', 'search/Index', 'scopes/Index', 'forms/Show', 'submissions/Encode'])(
        'leaves %s at the default cap, deliberately',
        (component) => {
            // Each of these is a single-column stack, a tree, a detail surface or a form — widening them
            // CAUSES the "title at one edge, date at the other" defect rather than fixing it.
            expect(innerClasses(render(component))).not.toContain('app-shell__inner--wide');
        },
    );
});

describe('AppLayout — the full-bleed builder still wins', () => {
    it('keeps the fluid modifiers and takes no width cap', () => {
        const classes = innerClasses(render('forms/Builder'));

        expect(classes).toContain('app-shell__inner--fluid');
        expect(classes).not.toContain('app-shell__inner--wide');
    });

    it('drops the content region tabindex for a fluid page and keeps it otherwise', () => {
        // Not incidental: a fluid page owns its own scroll, so the shell region is not scrollable and a
        // focus stop there would be one the user cannot see move (the note at the template's tabindex).
        expect(render('forms/Builder').find('.app-shell__content').attributes('tabindex')).toBeUndefined();
        expect(render('forms/Index').find('.app-shell__content').attributes('tabindex')).toBe('0');
    });
});


/**
 * The mobile drawer's shell-side state (J4b). It had no coverage at all before this increment — which is
 * how it kept a boolean that survives navigation with nothing to reset it.
 */
describe('AppLayout — the mobile drawer', () => {
    function shell(): VueWrapper {
        mocks.page.component = 'Dashboard';
        mocks.page.url = '/dashboard';
        mocks.page.props = {};
        return mount(AppLayout, { global: { stubs } });
    }

    it('starts closed and hands the state to both children', () => {
        const wrapper = shell();

        expect(wrapper.findComponent({ name: 'Sidebar' }).props('drawerOpen')).toBe(false);
        expect(wrapper.findComponent({ name: 'TopNav' }).props('drawerOpen')).toBe(false);
        wrapper.unmount();
    });

    it('toggles from the top nav and closes on the sidebar’s own request', async () => {
        const wrapper = shell();
        const nav = wrapper.findComponent({ name: 'TopNav' });
        const sidebar = wrapper.findComponent({ name: 'Sidebar' });

        nav.vm.$emit('toggle-drawer');
        await wrapper.vm.$nextTick();
        expect(sidebar.props('drawerOpen')).toBe(true);
        expect(nav.props('drawerOpen')).toBe(true);

        sidebar.vm.$emit('close');
        await wrapper.vm.$nextTick();
        expect(sidebar.props('drawerOpen')).toBe(false);
        wrapper.unmount();
    });

    it('closes itself on ANY navigation, not only on a nav-link click', async () => {
        // ⭐ THE CASE THE PERSISTENT LAYOUT MAKES NECESSARY. This layout instance survives Inertia visits,
        // so `drawerOpen` does too. Sidebar emits `close` when one of ITS links is clicked — but a
        // command-palette jump, the compact search link, the account menu and the browser's Back button all
        // navigate without one. Since J4b that is not merely untidy: the drawer would stay open over the new
        // page with that page's content marked inert behind a scrim nobody asked for.
        const wrapper = shell();
        wrapper.findComponent({ name: 'TopNav' }).vm.$emit('toggle-drawer');
        await wrapper.vm.$nextTick();
        expect(wrapper.findComponent({ name: 'Sidebar' }).props('drawerOpen')).toBe(true);

        mocks.live!.url = '/forms';
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent({ name: 'Sidebar' }).props('drawerOpen')).toBe(false);
        wrapper.unmount();
    });
});
