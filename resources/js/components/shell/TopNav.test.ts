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
        url: '/dashboard',
        props: { auth: { user: { name: 'Demo Owner', email: 'owner@demo.test' }, can: {} } },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), visit: vi.fn() },
    usePage: () => mocks.page,
}));

const TopNav = (await import('./TopNav.vue')).default;

const stubs = {
    NotificationBell: true,
    FeedbackButton: true,
    AccountMenu: true,
    ThemeQuickToggle: true,
};

function render(drawerOpen: boolean): VueWrapper {
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
