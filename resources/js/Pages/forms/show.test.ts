import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The form hub (Increment J2b) — `GET /forms/{form}`.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ THIS PAGE IS READ BY ALL FIVE ROLES, AND MOST OF WHAT IT SHOWS IS REFUSED TO SOME OF THEM.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * The route is `can:viewOverview,form`, which admits a Reviewer and a Viewer — precisely the roles every
 * other per-form page refuses. So the interesting contract here is not "does it render", it is "does each
 * affordance disappear for the reader who cannot use it". `FormHubGateTest` proves the SERVER omits the
 * right keys; nothing but this file proves the page reacts to their absence instead of rendering a button
 * that 403s. A `v-if="can.edit"` that someone changes to `v-if="true"` reddens here and nowhere else.
 *
 * ── The completeness case is the unusual one, and it is deliberate ─────────────────────────────────────
 * `every payload key has a reader` walks the whole presenter payload and requires each key to appear on
 * screen. It exists because the opposite defect is silent: a presenter key nothing renders survives every
 * gate, every type-check and every axe scan, and the codebase already carries that smell against
 * `feedback_reports` ("NOTHING reads it"). A prop that stops being rendered should cost someone a red test
 * rather than being discovered a year later.
 */

const mocks = vi.hoisted(() => ({ visit: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    // `formsCrumb()` reads `auth.can.manageForms` to decide whether the leading crumb is a LINK — /forms
    // 403s for a Reviewer and a Viewer. True here so the link branch is the one under test.
    usePage: () => ({ props: { auth: { can: { manageForms: true } } } }),
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: mocks.visit, post: mocks.post },
}));

// Both slots render: the hub is the `#breadcrumbs` slot's third consumer and puts four buttons in `#actions`.
vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        template: '<header><slot name="breadcrumbs" /><slot name="actions" /></header>',
    },
}));

/**
 * ShareModal is STUBBED, unlike the design-system components, and the boundary is deliberate: what the hub
 * owns is whether the modal is mounted at all and what payload it receives. Everything the modal then does
 * with that payload — the PATCH, the slug editor, the QR block — is `ShareModal.test.ts`'s, and mounting it
 * for real here would drag its whole `useForm` surface into this file's Inertia mock for no added coverage.
 */
vi.mock('@/components/forms/ShareModal.vue', () => ({
    default: { name: 'ShareModal', props: ['open', 'formId', 'formTitle', 'share'], template: '<div />' },
}));

const Show = (await import('./Show.vue')).default;

type Tab = { key: string; label: string; href: string; icon?: string };

type Props = {
    form: {
        id: string;
        title: string;
        description: string | null;
        status: string;
        scope_node_name: string | null;
        current_version: number | null;
        draft_version: number | null;
        updated_at: string | null;
    };
    stats: { responses: number; drafts: number; last_response_at: string | null };
    schedule: {
        opens_at: string | null;
        closes_at: string | null;
        timezone: string;
        max_responses: number | null;
        acceptance: 'open' | 'opens_soon' | 'closed' | 'capacity_reached';
        remaining: number | null;
    };
    versions: Array<{ id: string; version_number: number; status: string; published_at: string | null }>;
    recent: Array<{ id: string; status: string; source_label: string; submitted_at: string | null }>;
    tabs: Tab[];
    crumbs: { label: string; href?: string }[];
    can: { edit: boolean; publish: boolean; encode: boolean; template: boolean };
    share?: Record<string, unknown>;
};

const FORM_ID = '018f0000-0000-7000-8000-000000000001';

const OWNER_TABS: Tab[] = [
    { key: 'overview', label: 'Overview', href: `/forms/${FORM_ID}`, icon: 'forms' },
    { key: 'submissions', label: 'Responses', href: `/forms/${FORM_ID}/submissions`, icon: 'submissions' },
    { key: 'builder', label: 'Builder', href: `/forms/${FORM_ID}/builder`, icon: 'edit' },
    { key: 'analytics', label: 'Analytics', href: `/forms/${FORM_ID}/analytics`, icon: 'chart-bar' },
];

/** What a Viewer gets: the overview and the responses list, and nothing that writes. */
const VIEWER_TABS: Tab[] = OWNER_TABS.slice(0, 2);

const SHARE = {
    public_slug: 'clinic-intake',
    allow_guest_submissions: true,
    bot_challenge: 'off',
    guest_rate_limit_per_minute: null,
    suggested_slug: 'clinic-intake',
    is_published: true,
    public_host: 'acme.meridian.test',
    public_url: 'https://acme.meridian.test/f/clinic-intake',
};

function props(overrides: Partial<Props> = {}): Props {
    return {
        form: {
            id: FORM_ID,
            title: 'Clinic Intake',
            description: 'Front-desk intake for walk-in patients.',
            status: 'published',
            scope_node_name: 'Northern Region',
            current_version: 3,
            draft_version: 4,
            updated_at: '2026-08-01T09:00:00+00:00',
        },
        stats: { responses: 142, drafts: 7, last_response_at: '2026-08-10T14:30:00+00:00' },
        schedule: {
            opens_at: null,
            closes_at: null,
            timezone: 'Asia/Manila',
            max_responses: null,
            acceptance: 'open',
            remaining: null,
        },
        versions: [
            { id: 'v-3', version_number: 3, status: 'published', published_at: '2026-07-01T00:00:00+00:00' },
            { id: 'v-2', version_number: 2, status: 'superseded', published_at: '2026-06-01T00:00:00+00:00' },
            { id: 'v-1', version_number: 4, status: 'draft', published_at: null },
        ],
        recent: [
            { id: 'sub-1', status: 'submitted', source_label: 'Guest link', submitted_at: '2026-08-10T14:30:00+00:00' },
            { id: 'sub-2', status: 'approved', source_label: 'Manual entry', submitted_at: '2026-08-09T10:00:00+00:00' },
        ],
        tabs: OWNER_TABS,
        // J2d — server-resolved by `CrumbTrail`. The hub's trail is two crumbs: the root and this page.
        crumbs: [{ label: 'Forms', href: '/forms' }, { label: 'Clinic Intake' }],
        can: { edit: true, publish: true, encode: true, template: true },
        share: SHARE,
        ...overrides,
    };
}

function render(overrides: Partial<Props> = {}): VueWrapper {
    return mount(Show, { props: props(overrides), global: { stubs: { teleport: true } } });
}

/** A Reviewer or Viewer: no `share` key at all, and every write flag false. */
function readerProps(overrides: Partial<Props> = {}): Partial<Props> {
    return {
        tabs: VIEWER_TABS,
        // A Viewer holds no `forms.*` key, so `/forms` 403s for them and the root crumb loses its href.
        crumbs: [{ label: 'Forms' }, { label: 'Clinic Intake' }],
        can: { edit: false, publish: false, encode: false, template: false },
        share: undefined,
        ...overrides,
    };
}

describe('form hub — affordances follow the payload, never the layout', () => {
    it('offers the writer every action', () => {
        const wrapper = render();
        const text = wrapper.text();

        expect(text).toContain('New response');
        expect(text).toContain('Publish draft');
        expect(text).toContain('Share');
        expect(text).toContain('Edit form');

        wrapper.unmount();
    });

    it('offers a reader none of them, because a refused affordance is ABSENT and never disabled', () => {
        // ADR-0011 §D9. A disabled button is a claim that the thing exists and is being withheld; for a
        // Viewer on someone else's form there is nothing to withhold. The mutation this catches is any
        // `:disabled` rewrite of the four `v-if`s above.
        const wrapper = render(readerProps());
        const text = wrapper.text();

        expect(text).not.toContain('New response');
        expect(text).not.toContain('Publish draft');
        expect(text).not.toContain('Edit form');
        expect(wrapper.findComponent({ name: 'ShareModal' }).exists()).toBe(false);

        wrapper.unmount();
    });

    it('keys the Share affordance off the KEY BEING ABSENT, not off a falsy value', () => {
        // The presenter omits `share` entirely rather than sending nulls, so `v-if="share"` is the whole
        // contract. A page that instead read `share.allow_guest_submissions` would throw on this payload.
        const withShare = render();
        expect(withShare.findComponent({ name: 'ShareModal' }).exists()).toBe(true);
        withShare.unmount();

        const without = render({ share: undefined });
        expect(without.findComponent({ name: 'ShareModal' }).exists()).toBe(false);
        without.unmount();
    });

    it('hides Publish when there is no draft to publish, even for someone who may publish', () => {
        // `can.publish` alone is not enough: publishing turns a DRAFT into a version, so with none the
        // button is an invitation to a 422.
        const wrapper = render({
            form: { ...props().form, draft_version: null },
            can: { edit: true, publish: true, encode: true, template: true },
        });

        expect(wrapper.text()).not.toContain('Publish draft');

        wrapper.unmount();
    });

    it('renders exactly the tabs the server sent, and marks the current one', () => {
        const wrapper = render(readerProps());
        const nav = wrapper.findComponent({ name: 'TabNav' });

        expect(nav.props('items')).toHaveLength(2);
        expect(wrapper.text()).not.toContain('Analytics');

        // ⚠️ SCOPED TO THE STRIP, and the first draft of this case was not — it asserted on the page's first
        // `[aria-current="page"]` and matched the BREADCRUMB's last crumb instead. Two elements carrying it
        // is correct and intended here (the trail says which page, the strip says which tab), and ARIA puts
        // no uniqueness constraint on the attribute — so a page-wide locator is ambiguous by construction
        // rather than by accident, and would silently start asserting the wrong element again.
        expect(nav.get('[aria-current="page"]').text()).toContain('Overview');

        wrapper.unmount();
    });
});

describe('form hub — the version panel', () => {
    it('refuses Print blank and Restore on a DRAFT version', () => {
        // The print route 404s a draft (its `schema_snapshot` is literally empty) and restoring a draft into
        // itself is meaningless. Both buttons are therefore conditioned on the row, not only on the role.
        const wrapper = render({
            versions: [{ id: 'v-draft', version_number: 4, status: 'draft', published_at: null }],
        });

        expect(wrapper.text()).not.toContain('Print blank');
        expect(wrapper.text()).not.toContain('Restore');

        wrapper.unmount();
    });

    it('gates Print blank on can.template — the real view flag, not the edit flag', () => {
        // `forms/Index.vue` gates the same button on `can.edit` and its own comment admits that is a proxy
        // for a `can.view` flag the forms list does not have. This page HAS one, and the two differ for
        // exactly the roles the hub was widened for.
        const wrapper = render({ can: { edit: false, publish: false, encode: false, template: true } });

        expect(wrapper.text()).toContain('Print blank');
        expect(wrapper.text()).not.toContain('Restore');

        wrapper.unmount();
    });
});

describe('form hub — links, and the two places one would be a lie', () => {
    it('takes the Responses tile href from the tab set rather than rebuilding the URL', () => {
        // ⚠️ THIS ASSERTION CHANGED IN J2c AND THE PAGE DID NOT — which is the whole point of it. The strip
        // pointed at `/submissions?form_id=…` until the per-form route existed; swapping one line in
        // `FormTabSet` moved the tile too, because it reads the href back off the tab set. A page that
        // rebuilt the URL here would have kept shipping the old one, silently.
        const wrapper = render();
        const tiles = wrapper.findAllComponents({ name: 'StatTile' });
        const responses = tiles.find((t) => t.props('label') === 'Responses');

        expect(responses?.props('href')).toBe(`/forms/${FORM_ID}/submissions`);

        wrapper.unmount();
    });

    it('leaves the Responses tile unlinked when the reader has no Responses tab', () => {
        const wrapper = render({ tabs: [OWNER_TABS[0]] });
        const responses = wrapper
            .findAllComponents({ name: 'StatTile' })
            .find((t) => t.props('label') === 'Responses');

        expect(responses?.props('href')).toBeUndefined();

        wrapper.unmount();
    });

    it('never links the Last response tile, because its timestamp and recent[0] can disagree', () => {
        // `stats.last_response_at` is `max(submitted_at)`; `recent` is ordered by id, i.e. uuidv7 CREATION
        // order. A backdated `submitted_at` makes them name different rows, so a link here would send the
        // reader to a submission other than the one whose time is printed on the tile — with nothing on
        // screen to reveal it. This case exists because that link is the obvious thing to add later.
        const wrapper = render();
        const last = wrapper
            .findAllComponents({ name: 'StatTile' })
            .find((t) => t.props('label') === 'Last response');

        expect(last?.props('href')).toBeUndefined();

        wrapper.unmount();
    });

    it('links a recent row only when the Responses tab is present', () => {
        // The proof is a composition, written out on `canOpenResponse`: `/submissions/{id}` is gated
        // `can:view,submission` = `submissions.view` AND the same disjunction `scopeVisibleTo` applies, and
        // `recent` is already scoped, so the tab's `viewAny` supplies the only missing conjunct.
        //
        // ⚠️ ALL FIVE SHIPPED ROLES HOLD `submissions.view`, so this guard is unobservable in production
        // today and deleting it reddens nothing but this case. It is a fail-closed guard, not a live rule —
        // which is exactly why it is pinned with a synthetic payload rather than left to a role fixture.
        const linked = render();
        expect(linked.get('.hub__row-link').attributes('href')).toBe('/submissions/sub-1');
        linked.unmount();

        const unlinked = render({ tabs: [OWNER_TABS[0]] });
        expect(unlinked.find('.hub__row-link').exists()).toBe(false);
        expect(unlinked.text()).toContain('Manual entry');
        unlinked.unmount();
    });
});

describe('form hub — the empty panels, which are the common case for a new form', () => {
    it('keeps a heading above each empty state so heading-order cannot break', () => {
        // ⚠️ THIS IS THE FAILURE NO SEEDED axe SCAN WOULD FIND. `PageHeader` renders the h1 and
        // `MdsEmptyState` an h3, so dropping the panel h2 fails `heading-order` ONLY when the panel is
        // empty — and a brand-new form empties BOTH at once, which is the state a scan over seeded data
        // never reaches. J1e's Style-B rule, on a page that is not a list.
        const wrapper = render({ recent: [], versions: [] });

        const levels = wrapper.findAll('h1, h2, h3').map((h) => h.element.tagName);

        expect(wrapper.text()).toContain('No responses yet');
        expect(wrapper.text()).toContain('No versions yet');
        // No h3 may appear before an h2 has established the level above it.
        expect(levels.indexOf('H3')).toBeGreaterThan(levels.indexOf('H2'));

        wrapper.unmount();
    });

    it('offers "View all responses" only when there is both a list and somewhere to send them', () => {
        const empty = render({ recent: [] });
        expect(empty.text()).not.toContain('View all responses');
        empty.unmount();

        const noTab = render({ tabs: [OWNER_TABS[0]] });
        expect(noTab.text()).not.toContain('View all responses');
        noTab.unmount();
    });
});

describe('form hub — the schedule tile speaks the shared vocabulary', () => {
    it.each([
        ['open', 'Accepting'],
        ['opens_soon', 'Not open yet'],
        ['closed', 'Closed'],
        ['capacity_reached', 'Full'],
    ] as const)('renders %s as "%s"', (acceptance, label) => {
        // The four values come from `FormScheduleView`, shared with the guest runtime and manual encode, so
        // the hub cannot invent a fourth answer to "is this form still taking responses".
        const wrapper = render({
            schedule: { ...props().schedule, acceptance, max_responses: 100, remaining: 0 },
        });

        const tile = wrapper
            .findAllComponents({ name: 'StatTile' })
            .find((t) => t.props('label') === 'Accepting responses');

        expect(tile?.props('value')).toBe(label);

        wrapper.unmount();
    });

    it('names the remaining capacity while a capped form is still open', () => {
        const wrapper = render({
            schedule: { ...props().schedule, acceptance: 'open', max_responses: 500, remaining: 358 },
        });

        const tile = wrapper
            .findAllComponents({ name: 'StatTile' })
            .find((t) => t.props('label') === 'Accepting responses');

        expect(tile?.props('caption')).toBe('358 of 500 remaining');

        wrapper.unmount();
    });
});

describe('form hub — every payload key has a reader', () => {
    /**
     * ⚠️ THE POINT OF THIS CASE IS THE OPPOSITE OF WHAT IT LOOKS LIKE. It is not checking that the page
     * renders; the cases above do that. It is checking that no key of the presenter's payload has quietly
     * stopped being rendered, because that defect passes every other gate in the repo — Pest asserts the
     * server SENDS the key, vue-tsc asserts the type matches, axe scans whatever is on screen. Nothing else
     * notices that `scope_node_name` was dropped from the template three refactors ago.
     */
    it('renders something from every top-level key', () => {
        const wrapper = render();
        const text = wrapper.text();

        expect(text).toContain('Clinic Intake'); // form.title
        expect(text).toContain('Front-desk intake'); // form.description
        expect(text).toContain('Published'); // form.status, through statusVariant
        expect(text).toContain('Northern Region'); // form.scope_node_name
        expect(text).toContain('v3 live'); // form.current_version
        expect(text).toContain('142'); // stats.responses
        expect(text).toContain('Accepting'); // schedule.acceptance
        expect(text).toContain('Guest link'); // recent[].source_label
        expect(text).toContain('v2'); // versions[]
        expect(text).toContain('Overview'); // tabs
        expect(text).toContain('Edit form'); // can.edit
        expect(wrapper.findComponent({ name: 'ShareModal' }).exists()).toBe(true); // share

        // ⚠️ THE REMAINING THREE ARE ASSERTED ON THE TILE'S `value` PROP, NOT ON PAGE TEXT, and the reason
        // is that a text assertion here is worthless. `stats.drafts` is `7`, and `toContain('7')` passes
        // against any page that happens to render a seven anywhere — including one where the drafts tile has
        // been deleted outright, which is the single thing this case exists to notice. The two dates are the
        // same problem from the other side: the runner's locale decides their exact string, so the only
        // stable assertion is that a formatted value reached the tile rather than a raw ISO instant.
        const valueOf = (label: string) =>
            wrapper.findAllComponents({ name: 'StatTile' }).find((t) => t.props('label') === label)?.props('value');

        expect(valueOf('Drafts in progress')).toBe('7'); // stats.drafts
        expect(valueOf('Last response')).toEqual(expect.stringContaining('2026')); // stats.last_response_at
        expect(valueOf('Last response')).not.toContain('T14:30'); // formatted, never the raw ISO instant

        // `form.updated_at`, scoped to its own definition-list row rather than matched against page text.
        // A bare `toMatch(/2026/)` over the whole page would pass on the version dates alone, so it would
        // survive the very deletion this case is here to catch.
        const lastEdited = wrapper
            .findAll('.hub__meta-item')
            .find((item) => item.get('dt').text() === 'Last edited');

        expect(lastEdited?.get('dd').text()).not.toBe('—');
        expect(lastEdited?.get('dd').text()).toContain('2026');

        wrapper.unmount();
    });

    it('falls back to an em dash rather than printing nothing when the optional keys are null', () => {
        const wrapper = render({
            form: {
                ...props().form,
                description: null,
                scope_node_name: null,
                current_version: null,
                draft_version: null,
                updated_at: null,
            },
            stats: { responses: 0, drafts: 0, last_response_at: null },
        });

        expect(wrapper.text()).toContain('—');

        wrapper.unmount();
    });
});
