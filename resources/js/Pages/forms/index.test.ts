import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The forms list's empty state (Increment J1e).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ THIS PAGE'S `#empty` SLOT WAS AN UNCONDITIONAL "Create your first form", AND J1e IS WHAT MADE THAT
 * A LIE RATHER THAN A SIMPLIFICATION.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * Before this increment the list took no parameters, so zero rows and "you have never made a form" were
 * genuinely the same fact. The moment it takes a `?q`, they are not: a tenant with two hundred forms
 * searching a word none of them contain would have been told it had none — and offered a button to make
 * one. That is the single most visible defect J1e had to fix, and it is invisible to the server tests
 * (which assert the `empty_reason` prop) and to axe (which sees a perfectly accessible empty state saying
 * the wrong thing).
 *
 * The branch is driven by the SERVER's `empty_reason`, never by inspecting the local `q` ref. The client
 * cannot see what the server clamped or defaulted; `AuditLogPresenter` records the rule.
 */

const mocks = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.get, visit: mocks.visit },
    useForm: () => ({
        title: '',
        description: '',
        errors: {},
        processing: false,
        reset: vi.fn(),
        clearErrors: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    }),
    usePage: () => ({ props: { auth: { can: { manageScopes: false } } } }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

vi.mock('@/composables/useEntitlements', () => ({
    useEntitlements: () => ({ feature: () => true }),
}));

const FormsIndex = (await import('./Index.vue')).default;

type Props = {
    forms: unknown[];
    scopes: unknown[];
    filters: { applied: { q: string | null } };
    empty_reason: 'no_matches' | 'no_rows' | null;
};

function render(overrides: Partial<Props> = {}): VueWrapper {
    return mount(FormsIndex, {
        props: {
            forms: [],
            scopes: [],
            filters: { applied: { q: null } },
            empty_reason: 'no_rows',
            ...overrides,
        },
        global: { stubs: { teleport: true } },
    });
}

beforeEach(() => {
    mocks.get.mockClear();
});

describe('forms list — the empty state no longer lies', () => {
    it('still offers "Create your first form" to a genuinely empty workspace', () => {
        const wrapper = render({ empty_reason: 'no_rows' });

        expect(wrapper.text()).toContain('Create your first form');

        wrapper.unmount();
    });

    it('says the SEARCH matched nothing when it did, and never offers to create a first form', () => {
        // Mutation: drop the `v-if` and this reddens on the second assertion — which is the one that
        // matters, because the first would still pass against the old unconditional slot.
        const wrapper = render({ empty_reason: 'no_matches', filters: { applied: { q: 'clinic' } } });

        expect(wrapper.text()).toContain('No matching forms');
        expect(wrapper.text()).not.toContain('Create your first form');

        wrapper.unmount();
    });

    it('branches on the SERVER prop, not on the local query box', () => {
        // The two disagree here on purpose: a keyword is present and the server nonetheless says the list
        // is genuinely empty (a brand-new tenant that typed something). A client-side
        // `selected.q ? 'no_matches' : 'no_rows'` inference gets this backwards.
        const wrapper = render({ empty_reason: 'no_rows', filters: { applied: { q: 'clinic' } } });

        expect(wrapper.text()).toContain('Create your first form');
        expect(wrapper.text()).not.toContain('No matching forms');

        wrapper.unmount();
    });
});

describe('forms list — the row title reaches the form (J2b)', () => {
    /**
     * The title linked to `/forms/{id}/builder` and only `v-if="row.can.edit"`, because the builder was the
     * only per-form page that existed and it refuses everyone else. A reader who could see the row and not
     * edit it therefore got inert text and no way into the form at all.
     *
     * Both halves are asserted, and the second is the one worth having: an implementation that merely
     * repointed the href while keeping the `v-if` would satisfy the first case and still leave a Reviewer
     * staring at unclickable text.
     */
    const row = (canEdit: boolean) => ({
        id: 'form-1',
        title: 'Clinic Intake',
        status: 'published',
        current_version: 2,
        draft_version: null,
        updated_at: '2026-08-01T00:00:00+00:00',
        scope_node_id: null,
        versions: [],
        can: { edit: canEdit, publish: false, analytics: false, encode: false, archive: false },
    });

    it('links the title to the hub, not to the builder', () => {
        const wrapper = render({ forms: [row(true)], empty_reason: null });

        expect(wrapper.get('.forms__title-link').attributes('href')).toBe('/forms/form-1');

        wrapper.unmount();
    });

    it('links it for a reader who cannot edit, where it used to render inert text', () => {
        const wrapper = render({ forms: [row(false)], empty_reason: null });

        expect(wrapper.get('.forms__title-link').attributes('href')).toBe('/forms/form-1');

        wrapper.unmount();
    });
});

describe('forms list — the keyword filter', () => {
    it('renders a search field seeded from what the server applied', () => {
        const wrapper = render({ filters: { applied: { q: 'clinic' } } });

        const input = wrapper.get('input[type="search"]');
        expect((input.element as HTMLInputElement).value).toBe('clinic');
        // Never disabled — see MdsSearchField for why disabling a focused text input eats the caret.
        expect(input.attributes('disabled')).toBeUndefined();

        wrapper.unmount();
    });

    it('replaces the history entry when searching rather than pushing one per keystroke', async () => {
        const wrapper = render();

        await wrapper.get('input[type="search"]').setValue('clinic');
        await wrapper.get('input[type="search"]').trigger('keyup.enter');

        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(mocks.get.mock.calls[0][0]).toBe('/forms');
        expect(mocks.get.mock.calls[0][1]).toEqual({ q: 'clinic' });
        expect(mocks.get.mock.calls[0][2]).toMatchObject({ replace: true });

        wrapper.unmount();
    });

    it('sends no q at all when the box is cleared, rather than an empty one', async () => {
        // `?q=` would arrive as null after `ConvertEmptyStringsToNull` and mean the same thing, but it
        // would also leave a `?q=` on every URL the user copies out of the address bar.
        const wrapper = render({ filters: { applied: { q: 'clinic' } } });

        await wrapper.get('input[type="search"]').setValue('');
        await wrapper.get('input[type="search"]').trigger('keyup.enter');

        expect(mocks.get.mock.calls[0][1]).toEqual({});

        wrapper.unmount();
    });
});
