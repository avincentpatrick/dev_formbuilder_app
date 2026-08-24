import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The email-verification interstitial's three controls (Increment M10; the page is C1 + J3a).
 *
 * ── WHY THIS FILE EXISTS AT ALL, AND WHY IT IS THE FIRST TEST ANY `Pages/auth/` PAGE HAS EVER HAD ─────────
 * Every route into an authenticated surface bounces an unverified member HERE, so this page's controls are
 * the entire product for anyone who has just registered. M10 found its **Log out** control was a raw
 * `<form method="POST" action="/logout">`: a native submission sends no `_token` and no `X-XSRF-TOKEN`, and
 * `bootstrap/app.php` exempts only the SAML ACS, so it answered 419 — the only exit from an interstitial,
 * broken since PR #6, for every newly registered account and everyone who changed their email address.
 *
 * ⚠️ NO OTHER GATE IN THIS REPOSITORY COULD SEE IT. `tests/e2e/auth-axe.spec.ts` renders this exact page in
 * both themes and scans it — WITHOUT CLICKING — so a control that 419s scans identically to one that works.
 * Pest cannot assert the 419 either: Laravel disables `ValidateCsrfToken` under `runningUnitTests()`, so
 * every feature test in the suite submits tokenless by construction. The assertion has to be made on the
 * client, which is here.
 *
 * The companion `resources/js/__tests__/native-form-submission.test.ts` generalises it to the whole tree;
 * this file is the page-specific half, and it also pins the two SIBLING controls, because the interesting
 * regression is not "logout breaks again" — it is "logout gets fixed and takes resend with it".
 */

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    // `AuthLayout.vue:23` imports it; the mock replaces the whole module, so an omitted export is a hard
    // failure rather than a fallthrough. Same stub as every other Pages/*.test.ts in the tree.
    Head: { name: 'Head', render: () => null },
    // MUST be `reactive`: Inertia's real useForm returns a reactive object, and a frozen stand-in freezes
    // `processing` against its initial snapshot. Same reasoning as `shell/FeedbackButton.test.ts`.
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            post: mocks.post,
            put: mocks.put,
        }),
}));

// Imported AFTER the mock, so the page resolves it.
const VerifyEmail = (await import('./VerifyEmail.vue')).default;

function render(): VueWrapper {
    return mount(VerifyEmail, { props: { name: 'Dana Reyes', email: 'dana@example.test' } });
}

function buttonNamed(wrapper: VueWrapper, label: string) {
    return wrapper.findAll('button').find((button) => button.text().includes(label));
}

beforeEach(() => {
    mocks.post.mockClear();
    mocks.put.mockClear();
});

describe('auth/VerifyEmail — the only page a newly registered member can reach', () => {
    it('signs out through Inertia, which is the only way a token is sent', async () => {
        const wrapper = render();

        const signOut = buttonNamed(wrapper, 'Log out');
        expect(signOut, 'the page no longer offers a way out').toBeDefined();

        await signOut?.trigger('submit');

        expect(mocks.post).toHaveBeenCalledWith('/logout');
    });

    it('submits nothing natively, because a native submission carries no CSRF token', () => {
        const wrapper = render();
        const forms = wrapper.findAll('form');

        expect(forms.length, 'the page renders no forms at all — the mount is wrong, not the page').toBeGreaterThan(0);

        /*
        | ⚠️ THIS IS THE INVERSE OF THE DEFECT, AND IT IS ASSERTED ON THE RENDERED DOM RATHER THAN ON THE
        | SOURCE ON PURPOSE. A `<form>` reaches the browser as a native submitter if it carries an `action`
        | or a non-GET `method`, whatever the source looked like; reading the source would let a v-bind that
        | happens to produce one slip past. Both attributes are absent on all three forms here, because all
        | three are `@submit.prevent` and Inertia does the request.
        */
        for (const form of forms) {
            expect(form.attributes('action'), `${form.html().slice(0, 80)} submits natively`).toBeUndefined();
            expect(form.attributes('method'), `${form.html().slice(0, 80)} declares a native method`).toBeUndefined();
        }
    });

    it('still resends the verification link', async () => {
        const wrapper = render();

        await buttonNamed(wrapper, 'Resend verification email')?.trigger('submit');

        expect(mocks.post).toHaveBeenCalledWith('/email/verification-notification');
    });

    it('still offers the correction form, which is the other half of the lockout escape', async () => {
        const wrapper = render();

        // Progressive disclosure (DSR §5) — the correction form is one click behind a link.
        await wrapper.find('.auth-links a').trigger('click');
        await buttonNamed(wrapper, 'Update and resend')?.trigger('submit');

        expect(mocks.put).toHaveBeenCalledWith(
            '/user/profile-information',
            // The error bag is Fortify's own `validateWithBag()` name; getting it wrong silently drops every
            // validation message on the one form a locked-out member has left.
            expect.objectContaining({ errorBag: 'updateProfileInformation' }),
        );
    });
});
