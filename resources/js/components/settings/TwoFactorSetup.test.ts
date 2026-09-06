import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The 2FA enrolment panel (Increment I8a, PRD Feature #14).
 *
 * ⚠️ THIS SUITE EXISTS FOR ONE DEFECT, AND IT IS THE ONE PEST CANNOT REACH. Fortify guards its 2FA
 * endpoints with `password.confirm`, and Illuminate's RequirePassword forks on `expectsJson()`: a
 * navigation is REDIRECTED, but this component's two `fetch` sidecars — sent with
 * `Accept: application/json` — get a bare **423 with a JSON body**. The first draft called `res.json()`
 * unconditionally, so a lapsed confirmation window produced `qrSvg = undefined` and a non-array
 * `recoveryCodes`: a BLANK QR under copy saying "scan this", with no error in any log. On the super-admin
 * enrolment page that is a lockout, because `superadmin.mfa` allows no other console route.
 *
 * Two guards, and the tests below pin both independently because they fail in different situations:
 *  · `needsPasswordConfirmation` — server-computed, so the fetch is never made in the first place.
 *  · the `res.ok` check — the window can lapse between the page rendering and the user clicking.
 * Deleting either one leaves the other looking sufficient, which is exactly why both are asserted.
 */
const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    del: vi.fn(),
    formPost: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: mocks.post, delete: mocks.del },
    useForm: (initial: Record<string, unknown>) => ({
        ...initial,
        errors: {},
        processing: false,
        post: mocks.formPost,
        reset: vi.fn(),
    }),
}));

const TwoFactorSetup = (await import('./TwoFactorSetup.vue')).default;

type Props = { enabled: boolean; confirmed: boolean; needsPasswordConfirmation: boolean };

function render(props: Partial<Props> = {}): VueWrapper {
    return mount(TwoFactorSetup, {
        props: { enabled: false, confirmed: false, needsPasswordConfirmation: false, ...props },
    });
}

/** Stub both sidecars with one status; `ok` is derived exactly as the browser derives it. */
function stubSidecars(status: number, body: unknown = {}): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: status >= 200 && status < 300,
            status,
            json: async () => body,
        })),
    );
}

beforeEach(() => {
    mocks.post.mockClear();
    mocks.del.mockClear();
    vi.unstubAllGlobals();
});

describe('the stale-password-confirmation panel', () => {
    it('never fetches the QR when the server already said the confirmation is stale', async () => {
        stubSidecars(200, { svg: '<svg />' });

        const panel = render({ enabled: true, confirmed: false, needsPasswordConfirmation: true });
        await flushPromises();

        // The whole point of the server-computed prop: no request, so no 423 to recover from.
        expect(fetch).not.toHaveBeenCalled();
        expect(panel.text()).toContain('confirm your password');
        expect(panel.find('a[href="/user/confirm-password"]').exists()).toBe(true);
        // And emphatically NOT the setup panel it would otherwise render empty.
        expect(panel.text()).not.toContain('Scan this QR code');
    });

    it('recovers when the window lapses between render and fetch (the 423 path)', async () => {
        stubSidecars(423, { message: 'Password confirmation required.' });

        const panel = render({ enabled: true, confirmed: false, needsPasswordConfirmation: false });
        await flushPromises();

        // The prop said "fresh", so the fetch WAS made — and the response is what has to save us.
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(panel.find('a[href="/user/confirm-password"]').exists()).toBe(true);
        expect(panel.text()).not.toContain('Scan this QR code');
    });

    it('renders the QR and the recovery codes when the sidecars succeed', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) => ({
                ok: true,
                status: 200,
                json: async () =>
                    url.includes('qr-code') ? { svg: '<svg id="qr" />' } : ['code-aaa', 'code-bbb'],
            })),
        );

        const panel = render({ enabled: true, confirmed: false, needsPasswordConfirmation: false });
        await flushPromises();

        expect(panel.text()).toContain('Scan this QR code');
        expect(panel.html()).toContain('<svg id="qr"');
        expect(panel.text()).toContain('code-aaa');
        expect(panel.find('a[href="/user/confirm-password"]').exists()).toBe(false);
    });
});

describe('the other two states', () => {
    it('offers enrolment when two-factor is off, and does not fetch anything', async () => {
        stubSidecars(200);

        const panel = render({ enabled: false, confirmed: false });
        await flushPromises();

        expect(fetch).not.toHaveBeenCalled();
        expect(panel.text()).toContain('Enable two-factor authentication');

        await panel.find('button').trigger('click');
        expect(mocks.post).toHaveBeenCalledWith(
            '/user/two-factor-authentication',
            {},
            expect.anything(),
        );
    });

    it('reports two-factor as on once confirmed, without fetching the setup sidecars', async () => {
        stubSidecars(200);

        const panel = render({ enabled: true, confirmed: true });
        await flushPromises();

        expect(fetch).not.toHaveBeenCalled();
        expect(panel.text()).toContain('Two-factor authentication is on.');
    });
});

describe('the confirmation error bag (M78)', () => {
    it('⛔ posts the enrolment code under the bag Fortify validates into', async () => {
        // ⛔ WITHOUT THIS OPTION A WRONG SIX-DIGIT CODE RENDERS NOTHING, ON THREE PAGES.
        // The bag is not set by any `validateWithBag()` in `app/` — it is thrown ON THE EXCEPTION inside
        // `vendor/laravel/fortify/src/Actions/ConfirmTwoFactorAuthentication.php`, which is why a grep of
        // first-party code missed it and the backlog row that found the two `/settings` forms did not name
        // this one. Inertia then delivers `{confirmTwoFactorAuthentication: {code: …}}` and the template's
        // `confirmForm.errors.code` reads undefined forever.
        //
        // ⚠️ TWO OF THIS COMPONENT'S THREE MOUNT POINTS ARE LOCKOUT GATES — `Pages/admin/TwoFactorSetup.vue`
        // (super-admin MFA, where `superadmin.mfa` allows no other console route) and
        // `Pages/auth/TwoFactorRequired.vue` (tenant enforcement, where the only other affordance is sign
        // out). On those, silence leaves no way to tell a mistyped code from a broken page.
        //
        // ⚠️ THIS ARM PINS A STRING LITERAL AND THAT IS ONLY HALF A CONTRACT — it stays green if the SERVER
        // renames its bag. The other half is `tests/Feature/Settings/FortifyErrorBagTest.php`, which drives
        // the real route, reads the bag off the session and compares it to this same literal, so the two
        // ends cannot drift apart silently.
        stubSidecars(200);

        const panel = render({ enabled: true, confirmed: false, needsPasswordConfirmation: false });
        await flushPromises();

        const form = panel.find('form');
        expect(form.exists(), 'the confirmation form must render before its submit can be asserted').toBe(true);

        await form.trigger('submit');

        expect(mocks.formPost).toHaveBeenCalledWith(
            '/user/confirmed-two-factor-authentication',
            expect.objectContaining({ errorBag: 'confirmTwoFactorAuthentication' }),
        );
    });
});
