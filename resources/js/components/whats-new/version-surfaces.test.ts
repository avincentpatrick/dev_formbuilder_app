import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { versionLabel } from './version-label';

/**
 * M181 (`R-c7247dbf`, `D124`) — the version shows where a person will see it: *"lets put the current version in the app,
 * footer and login so users are aware"* (the user, 2026-10-14). The app shell's footer and sidebar foot are pinned in
 * `AppLayout.test.ts` and `Sidebar.test.ts`; this file covers the three surfaces outside the app shell — the sign-in
 * card, the console bar and the landing page — which show the version as text only, since `/whats-new` is behind
 * sign-in and on a workspace host.
 */

const mocks = vi.hoisted(() => ({
    page: {
        component: 'auth/Login',
        url: '/login',
        props: { app: { version: '0.9.0' }, auth: { user: { email: 'ops@example.test' } }, flash: {} } as Record<string, unknown>,
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', props: ['title'], template: '<div />' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => mocks.page,
    router: { post: vi.fn() },
}));

const AuthLayout = (await import('@/Layouts/AuthLayout.vue')).default;
const AdminLayout = (await import('@/Layouts/AdminLayout.vue')).default;
const Welcome = (await import('@/Pages/Welcome.vue')).default;

describe('versionLabel', () => {
    it('writes a release as v and its three numbers', () => {
        expect(versionLabel('0.9.0')).toBe('v0.9.0');
        expect(versionLabel('1.10.2')).toBe('v1.10.2');
    });

    it('says a development build is one, rather than inventing a number', () => {
        expect(versionLabel('dev')).toBe('development build');
        expect(versionLabel(undefined)).toBe('development build');
        expect(versionLabel('')).toBe('development build');
    });

    it('shows an override that is not three numbers as it was set', () => {
        expect(versionLabel('0.9.1-hotfix')).toBe('0.9.1-hotfix');
    });
});

describe('the version outside the app shell', () => {
    it('sits at the foot of the sign-in card, as text', () => {
        const wrapper = mount(AuthLayout, { props: { title: 'Sign in', variant: 'split' }, slots: { default: '<form />' } });
        const foot = wrapper.get('.auth__card .auth__version');

        expect(foot.text()).toBe('Meridian v0.9.0');
        expect(foot.find('a, button').exists()).toBe(false);
        expect(wrapper.get('aside').text()).not.toContain('v0.9.0');
    });

    it('sits in the console bar beside the operator', () => {
        const wrapper = mount(AdminLayout, { props: { title: 'Tenants' }, global: { stubs: { MdsToastHost: true } } });

        expect(wrapper.get('.admin__right .admin__version').text()).toBe('v0.9.0');
    });

    it('sits in the landing page footer', () => {
        const wrapper = mount(Welcome, { props: { appName: 'Meridian', registrationOpen: false, centralHost: 'meridian.test' } });

        expect(wrapper.get('.landing__footer .landing__version').text()).toBe('Meridian v0.9.0');
    });
});
