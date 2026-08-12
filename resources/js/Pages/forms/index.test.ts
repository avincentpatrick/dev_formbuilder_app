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
    filters: { applied: { q: string | null; state: string | null }; facets: unknown[] };
    empty_reason: 'no_matches' | 'no_rows' | null;
    view: string;
};

const FACETS = [
    { value: null, label: 'All', count: 0 },
    { value: 'live', label: 'Live', count: 0 },
    { value: 'draft', label: 'Draft', count: 0 },
    { value: 'closing_soon', label: 'Closing soon', count: 0 },
];

function render(overrides: Partial<Props> = {}): VueWrapper {
    return mount(FormsIndex, {
        props: {
            forms: [],
            scopes: [],
            filters: { applied: { q: null, state: null }, facets: FACETS },
            empty_reason: 'no_rows',
            view: 'grid',
            ...overrides,
        },
        global: { stubs: { teleport: true } },
    });
}

/**
 * One row in the shape `FormPresenter::present()` actually emits.
 *
 * ⚠️ TWO FIXTURE DEFECTS WERE FIXED HERE RATHER THAN CARRIED FORWARD (JR3). It spelled `can.archive`
 * where the component reads `can.delete` — so the archive action was never mounted in any Vitest run and
 * the fixture quietly disagreed with the server — and it omitted `description` entirely, which was
 * harmless while nothing rendered it and is not any more.
 */
const row = (canEdit: boolean) => ({
    id: 'form-1',
    title: 'Clinic Intake',
    description: 'All-scalar manual-encoding demo.',
    status: 'published',
    current_version: 2,
    draft_version: null,
    updated_at: '2026-08-01T00:00:00+00:00',
    scope_node_id: null,
    identity: 3,
    stats: { responses: 42, drafts: 7, last_response_at: '2026-08-01T00:00:00+00:00' },
    schedule: {
        opens_at: null,
        closes_at: null,
        timezone: 'UTC',
        max_responses: 100,
        acceptance: 'open',
        remaining: 42,
    },
    versions: [],
    can: { edit: canEdit, publish: false, delete: false, analytics: false, encode: false, template: false },
});

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

    it('keeps the link in the TABLE view too, where JR3 left it in this file', () => {
        // The two cases above now exercise the CARD, because grid is the default — so without this one
        // the table's own title cell would have no coverage at all after the split.
        const wrapper = render({ forms: [row(true)], empty_reason: null, view: 'table' });

        expect(wrapper.get('.forms__title-link').attributes('href')).toBe('/forms/form-1');

        wrapper.unmount();
    });
});

/**
 * JR3 — the card grid, the view toggle, and the data the page was already loading.
 */
describe('forms list — the card grid (JR3)', () => {
    it('renders cards by default and the table only when the server says so', () => {
        const cards = render({ forms: [row(true)], empty_reason: null });
        expect(cards.find('[data-form-entry]').exists()).toBe(true);
        expect(cards.find('table').exists()).toBe(false);
        cards.unmount();

        const table = render({ forms: [row(true)], empty_reason: null, view: 'table' });
        expect(table.find('table').exists()).toBe(true);
        expect(table.find('[data-form-entry]').exists()).toBe(false);
        table.unmount();
    });

    it('renders the description, which this page has shipped and hidden since D3', () => {
        // The single largest free win in the row: `description` was already on the wire and rendered by
        // nothing, so the list made a user open a form to remember what it was for.
        const wrapper = render({ forms: [row(true)], empty_reason: null });

        expect(wrapper.text()).toContain('All-scalar manual-encoding demo.');

        wrapper.unmount();
    });

    it('renders the counts and the capacity meter from the server blocks', () => {
        const wrapper = render({ forms: [row(true)], empty_reason: null });

        expect(wrapper.text()).toContain('42'); // responses
        expect(wrapper.text()).toContain('7'); // drafts
        expect(wrapper.text()).toContain('58 / 100'); // capacity: cap - remaining
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('58');

        wrapper.unmount();
    });

    it('shows the shared schedule label instead of a meter when the form is uncapped', () => {
        // Reads `acceptance` rather than re-deriving from the timestamps, so the list cannot disagree
        // with the guest runtime or the hub about whether a form is open.
        const uncapped = {
            ...row(true),
            schedule: { ...row(true).schedule, max_responses: null, remaining: null, closes_at: null },
        };
        const wrapper = render({ forms: [uncapped], empty_reason: null });

        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Accepting responses');

        wrapper.unmount();
    });

    it('keeps every one of the nine row actions in the card, not behind a menu', () => {
        // `templates-axe.spec.ts` clicks "Save as template" unscoped, and "Rename form" opens the only
        // `PATCH /forms/{form}` call site in the client. Both must be in the tree without a hover.
        const wrapper = render({
            forms: [{ ...row(true), can: { edit: true, publish: true, delete: true, encode: true, template: true, analytics: true } }],
            empty_reason: null,
        });

        for (const label of [
            'Open builder', 'Response statistics', 'New submission', 'Version history',
            'Save as template', 'Rename form', 'Publish form', 'Archive form',
        ]) {
            expect(wrapper.find(`[aria-label="${label}"]`).exists(), label).toBe(true);
        }

        wrapper.unmount();
    });

    it('renders the same actions in both views, because both render one component', () => {
        const names = (wrapper: VueWrapper) =>
            wrapper.findAll('[aria-label]').map((el) => el.attributes('aria-label')).sort();

        const full = { ...row(true), can: { edit: true, publish: true, delete: true, encode: true, template: true, analytics: true } };
        const cards = render({ forms: [full], empty_reason: null });
        const table = render({ forms: [full], empty_reason: null, view: 'table' });

        // Intersection rather than equality: the two views legitimately differ elsewhere (the table adds
        // sortable column buttons, the card a progressbar). What must not differ is the action set.
        const actions = ['Open builder', 'Response statistics', 'New submission', 'Version history',
            'Save as template', 'Rename form', 'Publish form', 'Archive form'];
        for (const label of actions) {
            expect(names(cards), `cards: ${label}`).toContain(label);
            expect(names(table), `table: ${label}`).toContain(label);
        }

        cards.unmount();
        table.unmount();
    });

    it('renders a counted chip per facet and marks the active one pressed', () => {
        const wrapper = render({
            forms: [row(true)],
            empty_reason: null,
            filters: {
                applied: { q: null, state: 'live' },
                facets: [
                    { value: null, label: 'All', count: 6 },
                    { value: 'live', label: 'Live', count: 4 },
                ],
            },
        });

        const chips = wrapper.findAll('.forms__facet');
        expect(chips).toHaveLength(2);
        expect(chips[0].text()).toContain('6');
        expect(chips[0].attributes('aria-pressed')).toBe('false');
        expect(chips[1].attributes('aria-pressed')).toBe('true');

        wrapper.unmount();
    });

    it('clears the facet when the active chip is clicked again', async () => {
        const wrapper = render({
            forms: [row(true)],
            empty_reason: null,
            filters: { applied: { q: null, state: 'live' }, facets: [{ value: 'live', label: 'Live', count: 4 }] },
        });

        await wrapper.get('.forms__facet').trigger('click');

        // No `state` key at all — "All" is the ABSENCE of the filter, not a value meaning everything.
        expect(mocks.get.mock.calls[0][1]).toEqual({});

        wrapper.unmount();
    });

    it('carries the view into the URL only when it is not the default', async () => {
        const wrapper = render({ forms: [row(true)], empty_reason: null, filters: { applied: { q: 'clinic', state: null }, facets: FACETS } });

        // Switching to the table adds the key…
        await wrapper.findAll('input[type="radio"]')[1].setValue(true);
        expect(mocks.get.mock.calls[0][1]).toEqual({ q: 'clinic', view: 'table' });

        wrapper.unmount();
    });

    it('drops the view key again when returning to cards, keeping /forms clean', async () => {
        const wrapper = render({ forms: [row(true)], empty_reason: null, view: 'table' });

        await wrapper.findAll('input[type="radio"]')[0].setValue(true);
        expect(mocks.get.mock.calls[0][1]).toEqual({});

        wrapper.unmount();
    });

    it('shows ONE empty state, outside both views, so they cannot disagree', () => {
        for (const view of ['grid', 'table']) {
            const wrapper = render({ forms: [], empty_reason: 'no_matches', view });

            expect(wrapper.text(), view).toContain('No matching forms');
            // Exactly one, not merely "at least one": the failure this guards against is the page and
            // the table each rendering their own, which reads fine in text and shows two on screen.
            expect(wrapper.findAll('.mds-empty').length, view).toBe(1);

            wrapper.unmount();
        }
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
