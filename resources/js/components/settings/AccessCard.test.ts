import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The Settings → Access card (Increment I5 + I8a).
 *
 * ⚠️ THE INVARIANT THIS FILE EXISTS FOR: **each switch patches ONLY its own key.**
 * `UpdateAccessSettingsRequest` marks both fields `sometimes` precisely so two independent decisions can
 * share one panel — invite-only and require-2FA are unrelated questions that happen to sit together.
 * Both switches autosave on change, so a partial write is the NORMAL case here, not an edge one. If the
 * card ever sends one form object carrying both keys, flipping either switch silently rewrites the other
 * to whatever the form was seeded with at mount, and the server-side `sometimes` becomes dead
 * defensiveness that looks tidy-away-able to the next reader.
 *
 * The second invariant: a rejected write must not leave a switch showing a state the server does not
 * hold — the same revert-on-error rule the Appearance and Notifications cards above it follow.
 */
const mocks = vi.hoisted(() => ({
    patch: vi.fn(),
    forms: [] as Record<string, unknown>[],
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        const form = reactive({
            ...initial,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            patch: (url: string, options: Record<string, unknown>) => {
                // Snapshot the payload as it stands at call time — the component mutates the form
                // object in place before patching, so a live reference would not prove anything.
                mocks.patch(url, { ...initial, ...form }, options);
            },
        });
        mocks.forms.push(form);

        return form;
    },
}));

const AccessCard = (await import('./AccessCard.vue')).default;

type Access = {
    invite_only: boolean;
    require_two_factor: boolean;
    signup_host: string;
    actor_enrolled: boolean;
};

const baseline: Access = {
    invite_only: true,
    require_two_factor: false,
    signup_host: 'acme.meridian.test',
    actor_enrolled: true,
};

function render(access: Partial<Access> = {}): VueWrapper {
    return mount(AccessCard, { props: { access: { ...baseline, ...access } } });
}

/** The switches render as role=switch inputs, in declaration order: invite-only, then require-2FA. */
function switches(card: VueWrapper) {
    return card.findAll('[role="switch"]');
}

beforeEach(() => {
    mocks.patch.mockClear();
    mocks.forms.length = 0;
});

describe('partial writes', () => {
    it('sends only invite_only when the invitation switch is flipped', async () => {
        const card = render();

        await switches(card)[0].setValue(false);

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        const [url, payload] = mocks.patch.mock.calls[0];
        expect(url).toBe('/settings/access');
        expect(payload).toHaveProperty('invite_only', false);
        // The decisive assertion: the OTHER key must be absent, not merely equal to its current value.
        expect(payload).not.toHaveProperty('require_two_factor');
    });

    it('sends only require_two_factor when the two-factor switch is flipped', async () => {
        const card = render();

        await switches(card)[1].setValue(true);

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        const [url, payload] = mocks.patch.mock.calls[0];
        expect(url).toBe('/settings/access');
        expect(payload).toHaveProperty('require_two_factor', true);
        expect(payload).not.toHaveProperty('invite_only');
    });

    it('uses two separate forms, which is what makes the above structural', () => {
        render();

        // One useForm per axis. Sharing one would make the two assertions above depend on the component
        // remembering to strip a key, rather than on it never having had it.
        expect(mocks.forms).toHaveLength(2);
        expect(Object.keys(mocks.forms[0])).toContain('invite_only');
        expect(Object.keys(mocks.forms[1])).toContain('require_two_factor');
    });
});

describe('the enforcement warning', () => {
    it('warns an unenrolled admin that the policy applies to them too', () => {
        const card = render({ require_two_factor: true, actor_enrolled: false });

        expect(card.text()).toContain("You haven't enrolled yet");
        // role=status, not alert: they are looking at the switch they just flipped, not being interrupted.
        expect(card.find('[role="status"]').exists()).toBe(true);
    });

    it('stays quiet for an admin who is already enrolled', () => {
        const card = render({ require_two_factor: true, actor_enrolled: true });

        expect(card.text()).not.toContain("You haven't enrolled yet");
    });

    it('stays quiet while enforcement is off, however unenrolled they are', () => {
        const card = render({ require_two_factor: false, actor_enrolled: false });

        expect(card.text()).not.toContain("You haven't enrolled yet");
    });
});

describe('rendering', () => {
    it('names the actual signup host rather than describing it', () => {
        // "People can sign up" is not a complete sentence for an admin deciding whether to allow it.
        expect(render().text()).toContain('acme.meridian.test');
    });

    it('reflects the server state on both switches', () => {
        const card = render({ invite_only: false, require_two_factor: true });
        const [inviteOnly, requireTwoFactor] = switches(card);

        expect((inviteOnly.element as HTMLInputElement).checked).toBe(false);
        expect((requireTwoFactor.element as HTMLInputElement).checked).toBe(true);
    });
});
