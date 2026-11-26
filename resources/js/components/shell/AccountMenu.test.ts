import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * M207 (`D179`) — the account menu's link to BHW Connect.
 *
 * Another server answers /bhw-connect, so that one item must be a plain anchor: Inertia's Link would make a
 * client visit and get back a page that is no Inertia response. The other items must stay Inertia's Link —
 * the mock below renders Link as an <a> too, which is why these cases ask for the COMPONENT, not the tag.
 */

// The page props, replaced per case: M252 (`D236`) takes the link's address from `app.analytics_url`.
const mocks = vi.hoisted(() => ({
    props: { auth: { user: { name: 'Demo Owner', email: 'owner@demo.test' } } } as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn() },
    usePage: () => ({ props: mocks.props }),
}));

const AccountMenu = (await import('./AccountMenu.vue')).default;

async function openMenu() {
    const wrapper = mount(AccountMenu, { attachTo: document.body });
    await wrapper.get('.mds-menu__trigger').trigger('click');
    return wrapper;
}

describe('AccountMenu — iConnect Analytics, formerly BHW Connect (M207, D179; M252, D236)', () => {
    it('lists iConnect Analytics as a plain link to /bhw-connect/, a menu item like the rest', async () => {
        const wrapper = await openMenu();
        const item = wrapper.findAll('[role="menuitem"]').find((el) => el.text() === 'iConnect Analytics');

        expect(item, 'no iConnect Analytics item in the account menu').toBeDefined();
        expect(item!.element.tagName).toBe('A');
        expect(item!.attributes('href')).toBe('/bhw-connect/');
        expect(item!.classes()).toContain('mds-menu__item');
        wrapper.unmount();
    });

    it('never hands /bhw-connect/ to Inertia, and still hands it every other link', async () => {
        const wrapper = await openMenu();
        const viaInertia = wrapper.findAllComponents({ name: 'Link' }).map((link) => link.props('href'));

        expect(viaInertia).toEqual(['/settings', '/feedback/mine']);
        wrapper.unmount();
    });

    it("takes the link's address from the page props once the app has its own (M253)", async () => {
        const before = mocks.props;
        mocks.props = { ...before, app: { version: '0.9.0', analytics_url: 'https://analytics.example.test/' } };
        const wrapper = await openMenu();
        const item = wrapper.findAll('[role="menuitem"]').find((el) => el.text() === 'iConnect Analytics');

        expect(item!.element.tagName).toBe('A');
        expect(item!.attributes('href')).toBe('https://analytics.example.test/');
        expect(wrapper.findAllComponents({ name: 'Link' }).map((link) => link.props('href'))).toEqual(['/settings', '/feedback/mine']);
        wrapper.unmount();
        mocks.props = before;
    });
});
