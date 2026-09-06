import { shallowMount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * `/settings` — the two Fortify forms and the error bags they were shipped without (M78).
 *
 * ⛔ WHY THIS FILE EXISTS, AND WHY THE PAGE HAD NO TEST AT ALL UNTIL NOW. Both forms on this page post to
 * Fortify actions that end in `validateWithBag(...)`. With no `default` bag on the session, Inertia's
 * `Middleware::resolveValidationErrors()` hands back the whole BAG MAP — `{updatePassword: {…}}` — while
 * the template binds the flat `password.errors.current_password`. Every such lookup was `undefined`, so a
 * duplicate email, an invalid address, a wrong current password or a breached password rendered
 * **nothing at all**. Proved by rendering it: submitting the real form in a browser left the DOM
 * byte-identical, with all eight `.mds-field__error` nodes empty.
 *
 * ⚠️ NOTHING IN THE REPOSITORY COULD SEE IT. `grep -rn errorBag tests/` was zero. None of the 73
 * `assertSessionHasErrors`/`assertInvalid` call sites touches a bagged route. Every Pest test that hits
 * these endpoints asserts the happy path — `FortifyRouteContextTest` ends three cases in
 * `assertSessionHasNoErrors()`. And the e2e gate loads `/settings` and scans it WITHOUT CLICKING, which
 * `native-form-submission.test.ts` already records as the reason this whole class is invisible to it.
 *
 * ⚠️ THE FIX IS CLIENT-SIDE, WHICH IS NOT OBVIOUS AND WAS MEASURED. Sending `X-Inertia-Error-Bag` changes
 * the response BYTE FOR BYTE not at all: that branch is guarded by `$bags->has('default') && header`, and
 * `has('default')` is false in exactly the case a bag is in use. `@inertiajs/core`'s `getScopedErrors()`
 * does the unwrapping, off the visit option asserted below.
 *
 * ⚠️ AND THIS FILE IS ONLY HALF THE CONTRACT. It pins the string literal the page sends; it stays green if
 * the SERVER renames its bag. `tests/Feature/Settings/FortifyErrorBagTest.php` closes that loop by driving
 * the real routes, reading the bag off the session and comparing it to this same literal.
 *
 * `shallowMount` deliberately: this page mounts nine cards and the whole design system, none of which is
 * the subject. `<form>` is a native element and survives stubbing, so the submits below still fire.
 */
const mocks = vi.hoisted(() => ({
    profilePut: vi.fn(),
    passwordPut: vi.fn(),
    patch: vi.fn(),
    routerPatch: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { patch: mocks.routerPatch, post: vi.fn(), delete: vi.fn() },
    // The page reads exactly two things off `usePage()` — `auth.user` for the profile form's initial
    // values and `ui.brand` for whether the accent control offers a "Brand" option. Both are supplied so
    // the component reaches its template; neither is the subject of this file.
    usePage: () => ({
        props: {
            auth: { user: { name: 'Dana Reyes', email: 'dana@example.test' } },
            ui: { brand: null },
        },
    }),
    // MUST be `reactive` — Inertia's real useForm returns a reactive object, and a frozen stand-in freezes
    // `processing` against its initial snapshot. Same reasoning as `auth/VerifyEmail.test.ts`.
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            // Which mock a form gets is decided by what it holds: the profile form has `name`, the
            // password form has `current_password`. Keyed on the shape rather than on call order, because
            // call order is exactly the thing a future edit to this page is entitled to change.
            put: 'current_password' in initial ? mocks.passwordPut : mocks.profilePut,
            patch: mocks.patch,
            reset: vi.fn(),
        }),
}));

// ⚠️ THE MODULE IS `useTheme`, NOT `useAppearancePreference` — the composable is named for what it
// returns and the file for what it owns. Mocking the function's own name resolves nothing, the real
// composable runs, and it dies on `page.props.ui.theme`. Measured on the first run of this file.
vi.mock('@/composables/useTheme', () => ({
    useAppearancePreference: () => ({
        mode: { value: 'system' },
        setMode: vi.fn(),
        accent: { value: 'blueprint' },
        setAccent: vi.fn(),
        fontSize: { value: 'base' },
        setFontSize: vi.fn(),
        dyslexiaFont: { value: false },
        setDyslexiaFont: vi.fn(),
    }),
}));

// Imported AFTER the mocks, so the page resolves them. Static, at file scope — a dynamic `import()`
// inside a hook is the load-dependent timeout M77 lost an hour to.
const SettingsIndex = (await import('./Index.vue')).default;

function render(): VueWrapper {
    return shallowMount(SettingsIndex, {
        props: {
            twoFactor: { enabled: false, confirmed: false, needs_password_confirmation: false },
            passwordPolicy: [],
            draftSettings: { draft_ttl_days: 30, is_default: true, can_manage: true },
            customDomains: { count: 0 },
            branding: {},
            notificationPreferences: [],
            appSettings: {
                can_manage: false,
                access: {},
                maintenance: {},
                modules: {},
                about: {},
            },
            sso: { visible: false },
        } as never,
        global: {
            // ⚠️ WITHOUT THIS FLAG THE FILE IS VACUOUS, and the failure is silent rather than loud:
            // `shallowMount` stubs every child, and a stubbed component renders NO slot content — so both
            // `<form>` elements, which sit inside an `MdsCard` default slot, vanish and `findAll('form')`
            // returns zero. Naming the card in `stubs` does not fix it either; the flag is the supported
            // seam. The floor assertion in the first case is what caught this on the first run, which is
            // the whole argument for writing floors on a test that locates its subject by query.
            renderStubDefaultSlot: true,
            stubs: { PageHeader: true, teleport: true },
        },
    });
}

beforeEach(() => {
    mocks.profilePut.mockClear();
    mocks.passwordPut.mockClear();
});

describe('the Fortify error bags on /settings', () => {
    it('⛔ posts the profile form under the bag Fortify validates into', () => {
        const wrapper = render();
        const forms = wrapper.findAll('form');
        expect(forms.length, 'the page must render its forms, or these assertions are vacuous').toBeGreaterThan(0);

        forms[0].trigger('submit');

        expect(mocks.profilePut).toHaveBeenCalledWith(
            '/user/profile-information',
            expect.objectContaining({ errorBag: 'updateProfileInformation' }),
        );
    });

    it('⛔ posts the password form under the bag Fortify validates into', () => {
        const wrapper = render();

        // Located by the mock it was given rather than by index, so re-ordering the cards on this page
        // does not silently re-point this assertion at the profile form.
        for (const form of wrapper.findAll('form')) {
            form.trigger('submit');
        }

        expect(mocks.passwordPut).toHaveBeenCalledWith(
            '/user/password',
            expect.objectContaining({ errorBag: 'updatePassword' }),
        );
    });

    it('⚠️ keeps the password form clearing its fields on error, which is why silence was so bad', () => {
        // Not decoration. `onError` FIRES today — the errors object is non-empty, it is merely shaped
        // wrongly — so the reset ran and wiped what the person typed while nothing explained why. The
        // handler is pinned here so a future edit cannot drop the bag and re-create that pairing.
        const wrapper = render();
        for (const form of wrapper.findAll('form')) {
            form.trigger('submit');
        }

        const options = mocks.passwordPut.mock.calls[0]?.[1] as Record<string, unknown>;
        expect(options.errorBag).toBe('updatePassword');
        expect(typeof options.onError).toBe('function');
    });
});
