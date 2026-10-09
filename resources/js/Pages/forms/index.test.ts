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

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    visit: vi.fn(),
    // Mutable so a test can mount the ninth row action. It is gated on the PAGE-level `manageScopes`
    // rather than a per-row flag, so a fixed `false` here makes that button unmountable in every case.
    manageScopes: { value: false },
}));

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
    usePage: () => ({ props: { auth: { can: { manageScopes: mocks.manageScopes.value } } } }),
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
    filters: { applied: { q: string | null; state: string | null; folder?: string | null }; facets: unknown[] };
    folders: { options: { id: string; name: string; count: number }[]; unfiled_count: number; can: { create: boolean; manage: boolean } };
    empty_reason: 'no_matches' | 'no_rows' | null;
    view: string;
};

/** No folders, and the rights a Form Editor holds (`D79`): may create, may not manage. */
const NO_FOLDERS = { options: [], unfiled_count: 0, can: { create: true, manage: false } };

const FACETS = [
    { value: null, label: 'All', count: 0 },
    { value: 'live', label: 'Live', count: 0 },
    { value: 'draft', label: 'Draft', count: 0 },
    { value: 'closing_soon', label: 'Closing soon', count: 0 },
];

/** Mounts with the page-level `scopes.manage` permission, which mounts the ninth row action. */
function renderWithScopes(overrides: Partial<Props> = {}): VueWrapper {
    mocks.manageScopes.value = true;

    return render(overrides);
}

function render(overrides: Partial<Props> = {}): VueWrapper {
    return mount(FormsIndex, {
        props: {
            forms: [],
            scopes: [],
            filters: { applied: { q: null, state: null, folder: null }, facets: FACETS },
            folders: NO_FOLDERS,
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
    folder_id: null,
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

/** The ten row actions, by accessible name — the single list both view-parity tests read. M131 added the tenth. */
const ACTION_LABELS = [
    'Open builder', 'Response statistics', 'New submission', 'Version history',
    'Save as template', 'Rename form', 'Move to folder', 'Set form scope', 'Publish form', 'Archive form',
];

beforeEach(() => {
    mocks.get.mockClear();
    mocks.manageScopes.value = false;
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
        const wrapper = render({ empty_reason: 'no_matches', filters: { applied: { q: 'clinic', state: null }, facets: FACETS } });

        expect(wrapper.text()).toContain('No matching forms');
        expect(wrapper.text()).not.toContain('Create your first form');

        wrapper.unmount();
    });

    it('branches on the SERVER prop, not on the local query box', () => {
        // The two disagree here on purpose: a keyword is present and the server nonetheless says the list
        // is genuinely empty (a brand-new tenant that typed something). A client-side
        // `selected.q ? 'no_matches' : 'no_rows'` inference gets this backwards.
        const wrapper = render({ empty_reason: 'no_rows', filters: { applied: { q: 'clinic', state: null }, facets: FACETS } });

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
        //
        // ⚠️ NINE MEANS NINE, AND THE FIRST DRAFT OF THIS TEST LISTED EIGHT. The missing one was
        // "Set form scope" — the only mount of `AssignScopeModal` in the entire client, which the
        // component's own docblock names as load-bearing. It is gated on the page-level `manageScopes`
        // rather than a `row.can.*`, and the module-level `usePage` mock returns `false`, so it was
        // absent from every render and silently omitted from the list. A test titled "nine" that asserts
        // eight is worse than no test: it reports coverage of the one affordance nobody would notice
        // losing. `withScopes` re-mocks the page so the ninth is actually mounted.
        const wrapper = renderWithScopes({
            forms: [{ ...row(true), can: { edit: true, publish: true, delete: true, encode: true, template: true, analytics: true } }],
            empty_reason: null,
        });

        for (const label of ACTION_LABELS) {
            expect(wrapper.find(`[aria-label="${label}"]`).exists(), label).toBe(true);
        }
        expect(ACTION_LABELS).toHaveLength(10);

        wrapper.unmount();
    });

    it('renders the same actions in both views, because both render one component', () => {
        const names = (wrapper: VueWrapper) =>
            wrapper.findAll('[aria-label]').map((el) => el.attributes('aria-label')).sort();

        const full = { ...row(true), can: { edit: true, publish: true, delete: true, encode: true, template: true, analytics: true } };
        const cards = renderWithScopes({ forms: [full], empty_reason: null });
        const table = renderWithScopes({ forms: [full], empty_reason: null, view: 'table' });

        // Intersection rather than equality: the two views legitimately differ elsewhere (the table adds
        // sortable column buttons, the card a progressbar). What must not differ is the action set.
        for (const label of ACTION_LABELS) {
            expect(names(cards), `cards: ${label}`).toContain(label);
            expect(names(table), `table: ${label}`).toContain(label);
        }

        cards.unmount();
        table.unmount();
    });

    it('tells the author that archiving closes the public link and cannot be undone (M146, D99 A)', async () => {
        // The dialog used to mention only the draft and the kept versions, while the link went on collecting
        // responses into a form the author could no longer find. Asserted on the plain sentences, not on the
        // title line: Vue drops the whitespace around an inline element, so that one runs together in text().
        const wrapper = render({
            forms: [{ ...row(true), can: { ...row(true).can, delete: true } }],
            empty_reason: null,
        });

        await wrapper.get('[aria-label="Archive form"]').trigger('click');

        expect(wrapper.text()).toContain('public link');
        expect(wrapper.text()).toContain('cannot be undone');

        wrapper.unmount();
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
        const wrapper = render({ filters: { applied: { q: 'clinic', state: null }, facets: FACETS } });

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
        const wrapper = render({ filters: { applied: { q: 'clinic', state: null }, facets: FACETS } });

        await wrapper.get('input[type="search"]').setValue('');
        await wrapper.get('input[type="search"]').trigger('keyup.enter');

        expect(mocks.get.mock.calls[0][1]).toEqual({});

        wrapper.unmount();
    });
});

/**
 * M131 (`R-9e634897`, `D78`/`D79`) — the folder filter, the two folder dialogs and the card caption.
 */
describe('forms list — folders (M131)', () => {
    const FOLDERS = {
        options: [
            { id: 'folder-a', name: 'Clinics', count: 3 },
            { id: 'folder-b', name: 'Archive', count: 0 },
        ],
        unfiled_count: 2,
        can: { create: true, manage: false },
    };

    it('offers All folders, Unfiled and each folder with the server counts', () => {
        const wrapper = render({ forms: [row(true)], empty_reason: null, folders: FOLDERS });

        const select = wrapper.get('select');
        const labels = select.findAll('option').map((o) => o.text());
        expect(labels).toEqual(['All folders', 'Unfiled (2)', 'Clinics (3)', 'Archive (0)']);
        expect((select.element as HTMLSelectElement).value).toBe('');

        wrapper.unmount();
    });

    it('navigates with the chosen folder and drops the key again for All folders', async () => {
        const wrapper = render({ forms: [row(true)], empty_reason: null, folders: FOLDERS });

        await wrapper.get('select').setValue('folder-a');
        expect(mocks.get.mock.calls[0][1]).toEqual({ folder: 'folder-a' });
        wrapper.unmount();

        const filtered = render({
            forms: [row(true)],
            empty_reason: null,
            folders: FOLDERS,
            filters: { applied: { q: null, state: null, folder: 'folder-a' }, facets: FACETS },
        });
        await filtered.get('select').setValue('');
        expect(mocks.get.mock.calls[1][1]).toEqual({});

        filtered.unmount();
    });

    it('clears every filter from the "no matches" state, the folder included', async () => {
        const wrapper = render({
            empty_reason: 'no_matches',
            folders: FOLDERS,
            filters: { applied: { q: 'clinic', state: 'draft', folder: 'none' }, facets: FACETS },
        });

        const clear = wrapper.findAll('button').find((b) => b.text() === 'Clear filters');
        expect(clear).toBeDefined();
        await clear!.trigger('click');

        expect(mocks.get.mock.calls[0][1]).toEqual({});

        wrapper.unmount();
    });

    it('shows Manage folders to anyone who may create one, and hides it from everyone else', () => {
        const author = render({ folders: FOLDERS });
        expect(author.findAll('button').some((b) => b.text() === 'Manage folders')).toBe(true);
        author.unmount();

        const reader = render({ folders: { ...FOLDERS, can: { create: false, manage: false } } });
        expect(reader.findAll('button').some((b) => b.text() === 'Manage folders')).toBe(false);
        reader.unmount();
    });

    it('offers Move to folder only to a row its viewer may edit', () => {
        const editable = render({ forms: [row(true)], empty_reason: null, folders: FOLDERS });
        expect(editable.find('[aria-label="Move to folder"]').exists()).toBe(true);
        editable.unmount();

        const readOnly = render({ forms: [row(false)], empty_reason: null, folders: FOLDERS });
        expect(readOnly.find('[aria-label="Move to folder"]').exists()).toBe(false);
        readOnly.unmount();
    });

    it('captions a filed card with its folder, and an unfiled card with nothing', () => {
        const filed = render({ forms: [{ ...row(true), folder_id: 'folder-a' }], empty_reason: null, folders: FOLDERS });
        expect(filed.get('.form-card__folder').text()).toBe('Folder: Clinics');
        filed.unmount();

        const loose = render({ forms: [row(true)], empty_reason: null, folders: FOLDERS });
        expect(loose.find('.form-card__folder').exists()).toBe(false);
        loose.unmount();
    });
});

/**
 * M154 (`R-44b17445`, `D103` A) — an archived form under the Archived chip, read-only. The server masks the
 * write flags (`FormPresenter`); the page adds what no row flag carries: the scope action, gated page-wide, the
 * card's schedule line and the table's version column.
 */
describe('forms list — an archived form is shown read-only (M154)', () => {
    const archived = () => ({
        ...row(false),
        status: 'archived',
        can: { edit: false, publish: false, delete: false, encode: false, template: true, analytics: true },
    });

    it('offers its responses and history, and nothing that would change it', () => {
        const wrapper = renderWithScopes({ forms: [archived()], empty_reason: null });

        for (const label of ['Response statistics', 'Version history', 'Save as template']) {
            expect(wrapper.find(`[aria-label="${label}"]`).exists(), label).toBe(true);
        }
        // "Set form scope" is gated on the PAGE's `manageScopes`, which this render grants — so its absence is
        // the archived check, not a missing permission.
        for (const label of ['Open builder', 'New submission', 'Rename form', 'Move to folder', 'Set form scope', 'Publish form', 'Archive form']) {
            expect(wrapper.find(`[aria-label="${label}"]`).exists(), label).toBe(false);
        }
        wrapper.unmount();

        // The non-vacuity partner: the same render grants the scope action on a live form.
        const live = renderWithScopes({ forms: [row(true)], empty_reason: null });
        expect(live.find('[aria-label="Set form scope"]').exists()).toBe(true);
        live.unmount();
    });

    it('says it no longer takes responses, rather than that it was never published', () => {
        const wrapper = render({ forms: [archived()], empty_reason: null });

        expect(wrapper.text()).toContain('No longer accepting responses');
        expect(wrapper.text()).not.toContain('Not published');

        wrapper.unmount();
    });

    it('drops "live" from its version in the table', () => {
        const wrapper = render({ forms: [archived()], empty_reason: null, view: 'table' });
        expect(wrapper.text()).toContain('v2');
        expect(wrapper.text()).not.toContain('v2 live');
        wrapper.unmount();

        const live = render({ forms: [row(true)], empty_reason: null, view: 'table' });
        expect(live.text()).toContain('v2 live');
        live.unmount();
    });

    it('says a form archived as a draft has no versions, instead of opening an empty history', async () => {
        const wrapper = render({ forms: [{ ...archived(), current_version: null, versions: [] }], empty_reason: null });

        await wrapper.get('[aria-label="Version history"]').trigger('click');

        expect(wrapper.text()).toContain('This form has no versions to show.');

        wrapper.unmount();
    });
});