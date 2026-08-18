import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The support-session banner — Increment I11b, RBAC §9 resolved decision 1.
 *
 * Two things are worth pinning here and they pull in opposite directions: the banner must be IMPOSSIBLE to
 * miss when a platform operator is driving, and it must be COMPLETELY absent otherwise — it renders on
 * every authenticated page in the application, so a false positive is a permanent scare banner for every
 * ordinary user.
 */

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    pageProps: {
        auth: {
            user: { id: 'u-1', name: 'Ada Lovelace', email: 'ada@acme.test' },
            can: {},
            impersonating: null as { exit_url: string } | null,
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post: mocks.post },
    usePage: () => ({ props: mocks.pageProps }),
}));

const ImpersonationBanner = (await import('./ImpersonationBanner.vue')).default;

beforeEach(() => {
    vi.clearAllMocks();
    mocks.pageProps.auth.impersonating = null;
});

describe('ImpersonationBanner', () => {
    it('renders nothing on an ordinary session', () => {
        // ⭐ THE CASE THAT MATTERS MOST, because it is every request the deployment ever serves. A banner
        // that leaked onto normal pages would tell every customer their account was being accessed by
        // support.
        expect(mount(ImpersonationBanner).find('[data-testid="impersonation-banner"]').exists()).toBe(false);
    });

    it('names the member whose account is being used', () => {
        mocks.pageProps.auth.impersonating = { exit_url: '/impersonate/exit' };

        const text = mount(ImpersonationBanner).text();

        expect(text).toContain('Ada Lovelace');
        expect(text).toContain('audit log');
    });

    it('never renders an operator identity, because it is never sent one', () => {
        // ⚠️ I11a's S2 finding, on the widest possible surface. `actingAsLabel()` returns the fixed string
        // "Platform operator" as a POLICY after the review found a real operator name reaching a tenant
        // page. This asserts the prop shape the server promises: an exit URL and nothing else.
        mocks.pageProps.auth.impersonating = { exit_url: '/impersonate/exit' };

        expect(Object.keys(mocks.pageProps.auth.impersonating!)).toEqual(['exit_url']);
        expect(mount(ImpersonationBanner).text()).not.toContain('operator@');
    });

    it('posts to the exit url the server supplied', async () => {
        mocks.pageProps.auth.impersonating = { exit_url: 'https://acme.localhost/impersonate/exit' };

        const wrapper = mount(ImpersonationBanner);
        await wrapper.find('button').trigger('click');

        // The URL comes from the server rather than being rebuilt here: it is host-absolute, and the client
        // has no reliable way to know which host it is on when the shell is what is being torn down.
        expect(mocks.post).toHaveBeenCalledWith('https://acme.localhost/impersonate/exit');
    });
});
