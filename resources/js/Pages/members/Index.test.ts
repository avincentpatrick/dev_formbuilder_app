import { shallowMount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * `/members` — the per-row two-factor reset and the four conditions that hide it (M107, `D37`).
 *
 * ⛔ WHY THE PREDICATE IS WORTH A TEST AND THE SERVER REFUSALS ARE NOT ENOUGH. Every refusal is enforced in
 * `TwoFactorResetService` and covered by `tests/Feature/Auth/TwoFactorResetTest.php`; this file covers the
 * half that lives only in the browser — whether the control is OFFERED. Those are different failures. A
 * missing server check is a security hole; a wrong client predicate is a button that promises a
 * locked-out colleague a rescue and then refuses it, which is the failure mode `members/Index.vue`'s own
 * ⚠️ block already records for the Owner row rendering an empty action strip.
 *
 * ⚠️ ONE CONDITION IS DELIBERATELY NOT MIRRORED, and the absence is the point: the server also refuses a
 * SUPER-ADMIN target, and the roster has no `is_super_admin` field to check. It must not grow one —
 * whether an account is platform staff is not a fact a workspace page may state. That refusal stays
 * server-side where it can be made without disclosing anything, and this file asserts the four that can
 * be mirrored without leaking.
 *
 * `shallowMount` deliberately: the subject is which buttons exist, not how the design system draws them.
 */
const mocks = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn(), del: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: mocks.post, patch: mocks.patch, delete: mocks.del, get: vi.fn() },
    usePage: () => ({
        props: {
            auth: {
                user: { id: 'owner-id', name: 'Owner' },
                can: {
                    manageMembers: true,
                    assignRoles: true,
                    transferOwnership: true,
                    resetMemberTwoFactor: true,
                },
            },
        },
    }),
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            post: mocks.post,
            patch: mocks.patch,
            reset: vi.fn(),
            clearErrors: vi.fn(),
        }),
}));

import MembersIndex from './Index.vue';

type Member = {
    user_id: string;
    name: string;
    email: string;
    status: string;
    role: string;
    is_owner: boolean;
    two_factor_enrolled: boolean;
    joined_at: string | null;
    invited_at: string | null;
};

function member(overrides: Partial<Member> = {}): Member {
    return {
        user_id: 'member-id',
        name: 'Casey Cruz',
        email: 'casey@example.test',
        status: 'active',
        role: 'Form Editor',
        is_owner: false,
        two_factor_enrolled: true,
        joined_at: null,
        invited_at: null,
        ...overrides,
    };
}

function render(members: Member[]): VueWrapper {
    return shallowMount(MembersIndex, {
        props: {
            members,
            assignableRoles: [{ value: 'reviewer', label: 'Reviewer' }],
            filters: { applied: { q: null } },
            empty_reason: null,
        },
    });
}

/** The predicate is not exported, so it is exercised the way a person meets it — through the row. */
function offersReset(wrapper: VueWrapper, row: Member): boolean {
    const vm = wrapper.vm as unknown as { canResetTwoFactor: (row: Member) => boolean };

    return vm.canResetTwoFactor(row);
}

describe('the two-factor reset row action (M107)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('offers the reset for an enrolled, active member who is not you', () => {
        const row = member();
        expect(offersReset(render([row]), row)).toBe(true);
    });

    it('hides it for a member who is not enrolled, so it never promises a reset with nothing to reset', () => {
        // ⚠️ THE SERVER REFUSES THIS CASE TOO, and the refusal is the reason the flag exists: a success
        // toast over an account that was never enrolled reads as "their access is restored" to the one
        // person who most needs that sentence to be true.
        const row = member({ two_factor_enrolled: false });
        expect(offersReset(render([row]), row)).toBe(false);
    });

    it('hides it for a pending invitation, which has no enrolment to clear', () => {
        const row = member({ status: 'invited' });
        expect(offersReset(render([row]), row)).toBe(false);
    });

    it('hides it on your own row — self-service is the door for that, behind password.confirm', () => {
        const row = member({ user_id: 'owner-id' });
        expect(offersReset(render([row]), row)).toBe(false);
    });

    it('posts to the reset route, with no payload, when confirmed', async () => {
        const row = member();
        const wrapper = render([row]);
        const vm = wrapper.vm as unknown as {
            twoFactorTarget: Member | null;
            submitTwoFactorReset: () => void;
        };

        vm.twoFactorTarget = row;
        vm.submitTwoFactorReset();

        // The target is in the PATH, not the body — the tenant host binds `{user}` through RLS, unlike the
        // console route, which takes a raw uuid because it has no tenant context to bind against.
        expect(mocks.post).toHaveBeenCalledWith(
            '/members/member-id/two-factor-reset',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
