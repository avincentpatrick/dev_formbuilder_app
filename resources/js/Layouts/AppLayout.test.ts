import { mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';

/**
 * The shell's content column (Increment JR4 wrote the repo's first AppLayout test; M173 rewrote its first half).
 *
 * JR4 capped every page at 1200px and kept a `WIDE_PAGES` set at 1600px. `D120` (the user, 2026-10-14) asked for every
 * page to fill the width, so M173 removed both caps: no page carries a width modifier, and the column itself declares no
 * `max-width`. A width is invisible at every viewport Playwright runs (its widest project is 1440×900, where the old cap
 * never bound), so the stylesheet is pinned here as text and the column is measured at 1920 and 2560 in
 * `tests/e2e/list-layout.spec.ts`.
 *
 * The builder is the other half: `forms/Builder` stays full-bleed (`--fluid`), padding and all, and owns its own scroll.
 */

const source = readFileSync(join(process.cwd(), 'resources/js/Layouts/AppLayout.vue'), 'utf8');
const style = source.slice(source.indexOf('<style'));

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

describe('AppLayout — no page is capped (M173, D120)', () => {
    // Every page JR4's wide set named, every page it deliberately left at 1200px, and the settings page that was its
    // worked example of a page to keep narrow: all of them now get the one plain column.
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
        'domains/Index',
        'search/Index',
        'scopes/Index',
        'forms/Show',
        'submissions/Encode',
        'Settings/Index',
    ])('gives %s the plain column, with no width modifier', (component) => {
        expect(innerClasses(render(component))).toEqual(['app-shell__inner']);
    });

    it('declares no max-width on the column, and one more step of padding from 1920px', () => {
        const rule = style.match(/^\.app-shell__inner \{([^}]*)\}/m);

        expect(rule, 'the .app-shell__inner rule').not.toBeNull();
        expect(rule![1]).not.toMatch(/max-width/);
        expect(rule![1]).not.toMatch(/margin:\s*0 auto/);
        // Declarations only (indented), so a comment or a media query naming a width cannot satisfy or trip this: the one
        // max-width left in the shell is the builder's `none`.
        expect([...style.matchAll(/^\s+max-width:\s*([^;]+);/gm)].map((m) => m[1])).toEqual(['none']);
        expect(style).toMatch(
            /^@media \(min-width: 1920px\) \{\s*\.app-shell__inner:not\(\.app-shell__inner--fluid\) \{\s*padding-inline: var\(--mds-space-12\);/m,
        );
    });
});

describe('AppLayout — the full-bleed builder still wins', () => {
    it('keeps the fluid modifier and nothing else', () => {
        expect(innerClasses(render('forms/Builder'))).toEqual(['app-shell__inner', 'app-shell__inner--fluid']);
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
