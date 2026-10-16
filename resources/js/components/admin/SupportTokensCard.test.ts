import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SupportTokenRow } from './types';

/**
 * The console's Support tokens card (M175, `R-78f43f26`, `D139`, `D141`).
 *
 * A super-admin creates the token the dev session's feedback pull uses. The weight sits in three places:
 *  · THE SECRET IS SHOWN ONCE, from the one-shot flash, and never from the page's props — the list carries
 *    names and dates only, because the server keeps a hash and nothing else.
 *  · AN EXPIRY IS NOT OPTIONAL. The token carries no session, so its lifetime is the only clock on it: the
 *    choice is 30 or 90 days, and there is no "never".
 *  · REVOKING ASKS FIRST. A revoked token stops the feedback pull until a new one is saved on the dev machine.
 */

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
    flash: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post: mocks.post, delete: mocks.delete },
    usePage: () => ({ props: { flash: mocks.flash, errors: {} } }),
}));

const SupportTokensCard = (await import('./SupportTokensCard.vue')).default;

function token(overrides: Partial<SupportTokenRow> = {}): SupportTokenRow {
    return {
        id: '0192e2e0-0000-7000-8000-0000000000t1',
        name: 'Dev laptop — feedback pull',
        owner: { name: 'Platform Admin', email: 'ops@meridian.test' },
        created_at: '2026-10-16T09:00:00+00:00',
        expires_at: '2027-01-14T09:00:00+00:00',
        last_used_at: null,
        revoked_at: null,
        state: 'active',
        ...overrides,
    };
}

function render(tokens: SupportTokenRow[] = [token()]): VueWrapper {
    // `teleport: true` or MdsModal's panel renders outside the wrapper and is invisible to find().
    return mount(SupportTokensCard, { props: { tokens }, global: { stubs: { teleport: true } } });
}

beforeEach(() => {
    mocks.post.mockClear();
    mocks.delete.mockClear();
    mocks.flash = {};
});

describe('support tokens — the list', () => {
    it('names each token, its owner, when it expires and when it was last used, and which no longer work', () => {
        const wrapper = render([
            token(),
            token({ id: 'revoked-1', name: 'Old laptop', revoked_at: '2026-10-01T00:00:00+00:00', state: 'revoked' }),
            token({ id: 'expired-1', name: 'Pilot', expires_at: '2026-09-01T00:00:00+00:00', state: 'expired' }),
        ]);

        expect(wrapper.text()).toContain('Dev laptop — feedback pull');
        expect(wrapper.text()).toContain('ops@meridian.test');
        expect(wrapper.text()).toContain('Never used');
        expect(wrapper.text()).toContain('Revoked');
        expect(wrapper.text()).toContain('Expired');
        // Only a working token can be revoked.
        expect(wrapper.findAll('button').filter((b) => b.text() === 'Revoke')).toHaveLength(1);

        wrapper.unmount();
    });

    it('never renders a secret from the props', () => {
        const wrapper = render([{ ...token(), token: 'msp_should_never_render' } as SupportTokenRow]);

        expect(wrapper.html()).not.toContain('msp_should_never_render');

        wrapper.unmount();
    });
});

describe('support tokens — creating one', () => {
    it('asks for a name and an expiry of 30 or 90 days, with no "never"', async () => {
        const wrapper = render([]);

        const expiry = wrapper.find('select#support-token-expiry');
        expect(expiry.findAll('option').map((o) => o.attributes('value'))).toEqual(['30', '90']);

        await wrapper.find('input#support-token-name').setValue('Dev laptop');
        await expiry.setValue('90');
        await wrapper.findAll('button').find((b) => b.text() === 'Create token')!.trigger('click');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0][0]).toBe('/admin/support-tokens');
        expect(mocks.post.mock.calls[0][1]).toEqual({ name: 'Dev laptop', expires_in_days: 90 });

        wrapper.unmount();
    });

    it('shows a new token once, from the flash, with a way to copy it and a warning it will not come back', () => {
        mocks.flash = { newSupportToken: { token: 'msp_abc123', name: 'Dev laptop' } };
        const wrapper = render();

        const field = wrapper.find('input[readonly]');
        expect((field.element as HTMLInputElement).value).toBe('msp_abc123');
        expect(wrapper.text()).toMatch(/won.t be shown again/i);
        expect(wrapper.findAll('button').some((b) => b.text().startsWith('Copy'))).toBe(true);

        wrapper.unmount();
    });
});

describe('support tokens — revoking one', () => {
    it('asks before revoking, then sends the revoke', async () => {
        const wrapper = render();

        await wrapper.findAll('button').find((b) => b.text() === 'Revoke')!.trigger('click');
        expect(mocks.delete).not.toHaveBeenCalled();
        expect(wrapper.text()).toMatch(/stops the feedback pull/i);

        await wrapper.findAll('button').find((b) => b.text() === 'Revoke token')!.trigger('click');

        expect(mocks.delete).toHaveBeenCalledTimes(1);
        expect(mocks.delete.mock.calls[0][0]).toBe('/admin/support-tokens/0192e2e0-0000-7000-8000-0000000000t1');

        wrapper.unmount();
    });
});
