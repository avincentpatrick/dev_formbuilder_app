import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * Increment H24b1 — the dashboard's reading of the `trends` prop H24a serves.
 *
 * Everything asserted here is a place where rendering the shape carelessly makes the page state something
 * the data does not support. Three of them are named in ADR-0011; the fourth was found by looking at the
 * rendered screen rather than by any gate, which is the reason this file exists at all rather than the
 * page being left to the axe spec.
 *
 *  · `total.change === null` means the prior period held NO rows. Undefined, not zero, and certainly not
 *    "+100%".
 *  · `drafts` has THREE states, and the two draft tiles reach the unavailable one for DIFFERENT reasons —
 *    `median_seconds` is additionally null when the denominator is positive but nothing converted, because
 *    `percentile_cont` over an empty set returns NULL. Sharing one sentence between them put "0% of 6 saved
 *    drafts" beside "No drafts were explicitly saved in this period" on one screen.
 *  · `top_forms.other === null` means nothing overflowed the top-N; `unassigned` is always present, even at
 *    0, and is NOT inside `rows`.
 *  · A bucket is a `YYYY-MM-DD` string in the QUERY's timezone. `new Date('2026-08-03')` is UTC midnight,
 *    so formatting it without an explicit `timeZone` renders the previous day for every viewer west of
 *    Greenwich.
 */

const mocks = vi.hoisted(() => ({
    pageProps: {
        auth: {
            user: { name: 'Demo Owner' },
            // J2d — one ability per tile DESTINATION. `/dashboard` is ungated while `/forms` and
            // `/members` are not, so a tile that links unconditionally is a 403 for the roles that can
            // reach this page and not that one.
            can: { manageForms: true, viewAnalytics: false, manageMembers: true, viewSubmissions: true },
        },
        entitlements: { features: { advanced_analytics: false } },
    },
    visit: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    // J5c gave this an href, because the first-run template card renders through it and the assertion that
    // matters is where it points. The previous stub swallowed every attribute.
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: mocks.visit, post: mocks.post },
    usePage: () => ({ props: mocks.pageProps }),
    // J5c — `CreateFormModal` is a real child of this page now, and it calls `useForm` on mount.
    useForm: () => ({
        title: '',
        description: '',
        errors: {},
        processing: false,
        reset: vi.fn(),
        clearErrors: vi.fn(),
        post: vi.fn(),
    }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

// Imported AFTER the mocks so the component resolves them.
const Dashboard = (await import('./Dashboard.vue')).default;

type Drafts = {
    suppressed: boolean;
    reason: string | null;
    denominator: number;
    converted: number | null;
    conversion_rate: number | null;
    median_seconds: number | null;
};

function trends(overrides: Record<string, unknown> = {}) {
    return {
        range: { from: '2026-07-05', to: '2026-08-03', timezone: 'UTC' },
        total: { current: 9, prior: 4, change: 125.0 },
        series: Array.from({ length: 30 }, (_, i) => ({
            bucket: `2026-07-${String(5 + i).padStart(2, '0')}`.replace(/-(3[2-9]|4\d)$/, '-30'),
            count: 0,
        })),
        top_forms: {
            rows: [{ key: 'f1', label: 'Clinic Intake', count: 9, url: '/forms/f1' }],
            other: null,
            unassigned: 0,
        },
        channels: { rows: [{ key: 'guest', label: 'Guest link', count: 9 }], other: null, unassigned: 0 },
        forms_accepting: 5,
        drafts: {
            suppressed: false,
            reason: null,
            denominator: 6,
            converted: 3,
            conversion_rate: 50,
            median_seconds: 372,
        } satisfies Drafts,
        ...overrides,
    };
}

/**
 * `overrides` reaches the TRENDS bag, which is what every pre-J5 case in this file varies. Page-level props
 * (kpis, the checklist, the first-run choices) go in the second argument — kept separate rather than merged
 * so the twenty existing calls stay byte-unchanged.
 */
function render(overrides: Record<string, unknown> = {}, page: Record<string, unknown> = {}): VueWrapper {
    return mount(Dashboard, {
        props: {
            kpis: { forms: 14, submissions: 9, members: 2 },
            trends: trends(overrides),
            // J5b — null is the ordinary state: the server returns rows only while the card should show.
            checklist: null,
            // J5c — the server's mirror of what `/forms/templates` and `POST /forms` would admit.
            start: { can_create: true, can_use_templates: true },
            ...page,
        },
    });
}

/** The tile whose visible label matches, so assertions do not depend on tile ORDER. */
function tile(wrapper: VueWrapper, label: string) {
    const found = wrapper
        .findAll('.mds-stat-tile')
        .find((t) => t.find('.mds-stat-tile__label').text() === label);
    expect(found, `no tile labelled "${label}"`).toBeDefined();

    return found!;
}

describe('Dashboard — the delta', () => {
    it('renders a null change as an em dash, never as a percentage', () => {
        const wrapper = render({ total: { current: 9, prior: 0, change: null } });

        const delta = tile(wrapper, 'Responses').find('.mds-stat-tile__delta');
        expect(delta.text()).toContain('—');
        expect(delta.text()).not.toContain('%');
        wrapper.unmount();
    });

    it('signs a real change and names the comparison period', () => {
        const wrapper = render();

        expect(tile(wrapper, 'Responses').find('.mds-stat-tile__delta').text()).toContain('+125%');
        expect(tile(wrapper, 'Responses').text()).toContain('vs. previous 30 days');
        wrapper.unmount();
    });

    it('puts no delta on the all-time Submissions tile, which the 30-day change does not describe', () => {
        // `kpis.submissions` is every countable submission ever; `trends.total.change` covers 30 days.
        // Pairing them would attach a period delta to a lifetime number.
        const wrapper = render();

        expect(tile(wrapper, 'Submissions').find('.mds-stat-tile__delta').exists()).toBe(false);
        expect(tile(wrapper, 'Submissions').text()).toContain('All time');
        wrapper.unmount();
    });
});

describe('Dashboard — the three draft states (ADR-0011 §D5)', () => {
    it('shows both rates with their denominator when the data supports them', () => {
        const wrapper = render();

        expect(tile(wrapper, 'Draft conversion').find('.mds-stat-tile__value').text()).toBe('50%');
        expect(tile(wrapper, 'Draft conversion').text()).toContain('of 6 saved drafts');
        expect(tile(wrapper, 'Median time to submit').find('.mds-stat-tile__value').text()).toBe('6m');
        wrapper.unmount();
    });

    it('suppresses BOTH tiles past the retention window, and never as a zero', () => {
        const wrapper = render({
            drafts: {
                suppressed: true,
                reason: 'beyond_draft_retention',
                denominator: 0,
                converted: null,
                conversion_rate: null,
                median_seconds: null,
            } satisfies Drafts,
        });

        for (const label of ['Draft conversion', 'Median time to submit']) {
            expect(tile(wrapper, label).find('.mds-stat-tile__value').text()).toBe('—');
            expect(tile(wrapper, label).text()).toContain('draft retention window');
            expect(tile(wrapper, label).text()).not.toContain('0%');
        }
        wrapper.unmount();
    });

    it('says "no drafts saved" on both tiles when the denominator is genuinely zero', () => {
        const wrapper = render({
            drafts: {
                suppressed: false,
                reason: 'no_saved_drafts',
                denominator: 0,
                converted: null,
                conversion_rate: null,
                median_seconds: null,
            } satisfies Drafts,
        });

        for (const label of ['Draft conversion', 'Median time to submit']) {
            expect(tile(wrapper, label).text()).toContain('No drafts were explicitly saved');
        }
        wrapper.unmount();
    });

    it('does not claim "no drafts were saved" when six were saved and none converted', () => {
        // The defect this file was written for. `percentile_cont` over an empty set returns NULL, so the
        // median is unavailable while the conversion rate is a real 0% over a real denominator of 6.
        // One shared sentence made the two tiles contradict each other on the same screen.
        const wrapper = render({
            drafts: {
                suppressed: false,
                reason: null,
                denominator: 6,
                converted: 0,
                conversion_rate: 0,
                median_seconds: null,
            } satisfies Drafts,
        });

        const conversion = tile(wrapper, 'Draft conversion');
        expect(conversion.find('.mds-stat-tile__value').text()).toBe('0%');
        expect(conversion.text()).toContain('of 6 saved drafts');

        const median = tile(wrapper, 'Median time to submit');
        expect(median.find('.mds-stat-tile__value').text()).toBe('—');
        expect(median.text()).toContain('have been submitted yet');
        expect(median.text()).not.toContain('No drafts were explicitly saved');
        wrapper.unmount();
    });
});

/**
 * The card whose `<h3>` matches.
 *
 * ⚠️ EVERY SELECTOR BELOW USED TO BE DOCUMENT-WIDE, AND I10c BROKE THAT by putting a SECOND bar chart on
 * this page. `.mds-bar__label` then matched both charts' labels, and four assertions here silently started
 * measuring something other than what they name. The fix is to scope, never to broaden the expectation to
 * accommodate the other chart — an assertion that accepts both cards' contents is an assertion about
 * neither.
 */
function card(wrapper: VueWrapper, heading: string) {
    const found = wrapper
        .findAll('.mds-card')
        .find((c) => c.find('.dash__card-title').exists() && c.find('.dash__card-title').text() === heading);

    if (found === undefined) {
        throw new Error(`no card headed "${heading}"`);
    }

    return found;
}

describe('Dashboard — the breakdown', () => {
    it('plots the labelled rows and omits an Other bar when nothing overflowed', () => {
        const wrapper = render();
        const forms = card(wrapper, 'Top forms');

        expect(forms.findAll('.mds-bar__label').map((s) => s.text())).toEqual(['Clinic Intake']);
        expect(forms.find('.mds-bar__fill--other').exists()).toBe(false);
        wrapper.unmount();
    });

    it('adds the aggregated bucket as a NEUTRAL bar naming how many forms it holds', () => {
        const wrapper = render({
            top_forms: {
                rows: [
                    { key: 'f1', label: 'Clinic Intake', count: 9, url: '/forms/f1' },
                    // ⚠️ NO URL: a soft-deleted form still earns a NAMED bar (the presenter resolves its
                    // title with `withTrashed()` on purpose) and must not be a link, because
                    // `/forms/{form}` binds through the default scope and 404s on it.
                    { key: 'f2', label: 'Household Roster', count: 4, url: null },
                ],
                other: { count: 7, categories: 3 },
                unassigned: 2,
            },
        });

        const forms = card(wrapper, 'Top forms');
        expect(forms.findAll('.mds-bar__label').map((s) => s.text())).toEqual([
            'Clinic Intake',
            'Household Roster',
            'Unassigned',
            'Other (3 forms)',
        ]);
        // Exactly one neutral bar, and it is the aggregate — Unassigned is a real set of forms, not a
        // remainder, so it keeps the ordinary fill.
        expect(forms.findAll('.mds-bar__fill--other')).toHaveLength(1);
        wrapper.unmount();
    });

    it('drops the zero Unassigned bucket, which is always present in the prop', () => {
        const wrapper = render();

        expect(card(wrapper, 'Top forms').text()).not.toContain('Unassigned');
        wrapper.unmount();
    });

    it('shows an empty state rather than an empty plot when nothing was submitted', () => {
        const wrapper = render({ top_forms: { rows: [], other: null, unassigned: 0 } });
        const forms = card(wrapper, 'Top forms');

        expect(forms.find('.mds-bar__plot').exists()).toBe(false);
        expect(forms.text()).toContain('No responses in this period');
        wrapper.unmount();
    });

    it('links a top-forms bar to its hub, and leaves a deleted form’s bar inert', () => {
        /*
         * ⚠️ THE MIXED FIXTURE IS THE TEST. A one-row fixture cannot tell "every bar is linked" from "the
         * right bars are linked", and an always-link mutation (`href: '/forms/' + key`) would pass it. The
         * second row carries `url: null` — the soft-deleted case — so exactly one link is correct.
         *
         * The aggregate buckets are inert by construction rather than by a special case: neither
         * `unassigned` nor `other` is built from a row, so neither can carry a url.
         */
        const wrapper = render({
            top_forms: {
                rows: [
                    { key: 'f1', label: 'Clinic Intake', count: 9, url: '/forms/f1' },
                    { key: 'f2', label: 'Household Roster', count: 4, url: null },
                ],
                other: { count: 7, categories: 3 },
                unassigned: 2,
            },
        });

        const forms = card(wrapper, 'Top forms');
        const links = forms.findAll('a');

        expect(links).toHaveLength(1);
        expect(links[0]?.attributes('href')).toBe('/forms/f1');
        // Every bar still NAMES its form, linked or not — the plot must not go quiet for a deleted one.
        expect(forms.findAll('.mds-bar__label').map((n) => n.text())).toEqual([
            'Clinic Intake',
            'Household Roster',
            'Unassigned',
            'Other (3 forms)',
        ]);

        wrapper.unmount();
    });
});

describe('Dashboard — the KPI tiles link, but only where the reader may go (J2d)', () => {
    it('links the three list tiles for a reader holding every ability', () => {
        const wrapper = render();
        const hrefs = wrapper
            .findAll('.dash__stats a')
            .map((a) => a.attributes('href'));

        expect(hrefs).toEqual(['/forms', '/submissions', '/members']);
        wrapper.unmount();
    });

    it('leaves the Forms and Members tiles inert for a reader the destinations refuse', () => {
        /*
         * ⚠️ THIS IS J2c's DEFECT IN ITS OTHER TWO HOMES. `/dashboard` carries no gate, so every role
         * reaches this page; `/forms` is `can:viewAny,Form` (a Reviewer and a Viewer hold none of its three
         * keys) and `/members` is `can:tenant.members.invite` (Owner/Admin only) — while the Members TILE
         * renders for anyone with `dashboard.org.view`, which a Viewer has. Unconditional hrefs would hand
         * both roles a bare 403.
         */
        // ⚠️ `try/finally`, BECAUSE `mocks.pageProps` IS HOISTED AND SHARED. Restoring after the assertion
        // means a FAILING assertion leaks `manageForms: false` into every later test in this file — one
        // failure becomes a cascade whose messages point at the wrong cases.
        mocks.pageProps.auth.can.manageForms = false;
        mocks.pageProps.auth.can.manageMembers = false;

        try {
            const wrapper = render();
            const hrefs = wrapper
                .findAll('.dash__stats a')
                .map((a) => a.attributes('href'));

            expect(hrefs).toEqual(['/submissions']);
            wrapper.unmount();
        } finally {
            mocks.pageProps.auth.can.manageForms = true;
            mocks.pageProps.auth.can.manageMembers = true;
        }
    });

    it('never links the Accepting-responses tile, whose number no list can reproduce', () => {
        // It counts published AND in-window AND under-cap forms; `/forms` has no filter for that set, so a
        // link would land the reader on a list whose length disagrees with the number they clicked.
        const wrapper = render();
        const hrefs = wrapper
            .findAll('.dash__stats a')
            .map((a) => a.attributes('href'));

        expect(hrefs).not.toContain('/forms?accepting=1');
        expect(wrapper.findAll('.dash__stats a')).toHaveLength(3);
        wrapper.unmount();
    });
});

/*
 * Increment I10c — the submission-channel breakdown (docs/PRD.md:198's last unbuilt Phase-1 clause).
 *
 * ADR-0011 §D12 is why the paired-table assertions are here and not left to the axe gate: axe CANNOT detect
 * a missing text alternative for an SVG that carries a plausible `aria-label`, so the merge-blocking job
 * would happily pass a chart that is a beautiful, unreadable picture. The contract is pinned by Vitest or it
 * is not pinned at all.
 */
describe('Dashboard — the channel breakdown (I10c)', () => {
    it('pairs the channel chart with a table naming every channel', () => {
        const wrapper = render({
            channels: {
                rows: [
                    { key: 'guest', label: 'Guest link', count: 6 },
                    { key: 'manual', label: 'Manual entry', count: 3 },
                ],
                other: null,
                unassigned: 0,
            },
        });
        const channels = card(wrapper, 'Responses by channel');

        // §D12's non-visual equivalent, asserted on BOTH tables, because they are different components and
        // only one of them survives each mutation: `.mds-bar__table` is MdsBarChart's OWN sr-only table (so
        // it is what disappears if the chart is swapped for a bare <svg aria-label> — the substitution axe
        // cannot see), and `.mds-table` is the paired MdsDataTable this page adds beside it. An assertion on
        // only the latter would have stayed green through exactly the mutation the comment claimed it caught.
        expect(channels.findAll('.mds-bar__table tbody tr').length).toBeGreaterThanOrEqual(2);

        const cells = channels.findAll('.mds-table tbody tr').map((row) => row.text());
        expect(cells).toHaveLength(2);
        expect(cells.join(' ')).toContain('Guest link');
        expect(cells.join(' ')).toContain('Manual entry');
        wrapper.unmount();
    });

    it('labels the axis "Channel", never "Source"', () => {
        const wrapper = render();
        const channels = card(wrapper, 'Responses by channel');

        // The same word AnalyticsChartsCard uses for this axis — two surfaces, one name.
        expect(channels.text()).toContain('Channel');
        expect(channels.text()).not.toContain('Source');
        wrapper.unmount();
    });

    it('names the single channel in prose when only one has responses', () => {
        // The ordinary state of a real tenant: three of six sources are ever written, so one full-width bar
        // saying "100% of something" is the common case. The sentence is what makes it mean anything.
        const wrapper = render();

        expect(card(wrapper, 'Responses by channel').text()).toContain(
            'Every response in this period arrived by guest link.',
        );
        wrapper.unmount();
    });

    it('says nothing extra once two channels have responses', () => {
        const wrapper = render({
            channels: {
                rows: [
                    { key: 'guest', label: 'Guest link', count: 6 },
                    { key: 'manual', label: 'Manual entry', count: 3 },
                ],
                other: null,
                unassigned: 0,
            },
        });

        expect(card(wrapper, 'Responses by channel').text()).not.toContain('Every response in this period');
        wrapper.unmount();
    });

    it('renders no bar for a channel with no responses', () => {
        // A GROUP BY returns only what occurred; zero-filling the six cases client-side would invent
        // categories and advertise unbuilt OCR / API import as available and unused.
        const wrapper = render();

        expect(card(wrapper, 'Responses by channel').findAll('.mds-bar__label')).toHaveLength(1);
        wrapper.unmount();
    });

    it('shows a channel-specific empty state, not a copy of the top-forms one', () => {
        const wrapper = render({
            top_forms: { rows: [], other: null, unassigned: 0 },
            channels: { rows: [], other: null, unassigned: 0 },
        });

        const channels = card(wrapper, 'Responses by channel');
        expect(channels.text()).toContain('manual entry, guest link');
        // Two adjacent cards rendering the identical sentence teaches nothing. Compare the DESCRIPTIONS, not
        // the whole cards — the card text includes the header, so a whole-card comparison is unequal by
        // construction and would pass however identical the copy underneath became.
        expect(channels.find('.mds-empty__desc').text()).not.toBe(
            card(wrapper, 'Top forms').find('.mds-empty__desc').text(),
        );
        wrapper.unmount();
    });

    it('tabulates the Other bucket the plot folds away on the channel axis', () => {
        const wrapper = render({
            channels: {
                rows: [
                    { key: 'guest', label: 'Guest link', count: 6 },
                    { key: 'manual', label: 'Manual entry', count: 3 },
                ],
                other: { count: 2, categories: 1 },
                unassigned: 0,
            },
        });

        // §D11: "nothing is hidden, only un-plotted" — singular "channel", from breakdown-bars' pluraliser.
        expect(card(wrapper, 'Responses by channel').text()).toContain('Other (1 channel)');
        wrapper.unmount();
    });
});

describe('Dashboard — the analytics view-switcher (H24b2)', () => {
    /**
     * ADR-0011 §D9: the surface is HIDDEN for an unentitled tenant, never rendered locked with an upgrade
     * call-to-action. Business is seeded `is_active: false` — held from sale until the production host is
     * stood up — so an upgrade CTA would point at a plan that cannot be bought: a dead end presented as an
     * offer. There must be no "Upgrade" branch anywhere on this control, and these three cases are what
     * stop one being added later "to drive conversion".
     */
    function withGates(canView: boolean, entitled: boolean): VueWrapper {
        mocks.pageProps.auth.can.viewAnalytics = canView;
        mocks.pageProps.entitlements.features.advanced_analytics = entitled;

        return render();
    }

    it('shows the switcher when the plan carries advanced_analytics and the user may read it', () => {
        const wrapper = withGates(true, true);

        expect(wrapper.find('.mds-segmented').exists()).toBe(true);
        expect(wrapper.text()).toContain('Analytics');
        wrapper.unmount();
    });

    it('HIDES it when unentitled — never a locked control, never an upgrade prompt', () => {
        const wrapper = withGates(true, false);

        expect(wrapper.find('.mds-segmented').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Upgrade');
        wrapper.unmount();
    });

    it('hides it when the user lacks viewAnalytics even on an entitled plan', () => {
        // The two gates are ANDed, exactly as Sidebar.vue ANDs a NavItem's `gate` and `feature`, so the
        // switcher and the nav item can never disagree about whether the destination exists.
        const wrapper = withGates(false, true);

        expect(wrapper.find('.mds-segmented').exists()).toBe(false);
        wrapper.unmount();
    });
});

describe('Dashboard — the range', () => {
    it('states the window and its timezone, so no tile reads as an all-time total', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Last 30 days');
        expect(wrapper.text()).toContain('(UTC)');
        wrapper.unmount();
    });

    it('formats bucket labels in the QUERY timezone, not the viewer’s', () => {
        // `new Date('2026-07-05')` is UTC midnight; rendered in a negative-offset zone without an explicit
        // timeZone it reads "Jul 4". The x-axis keeps the first bucket's own day.
        const wrapper = render();

        const labels = wrapper.findAll('.mds-tsc__x span').map((s) => s.text());
        expect(labels[0]).toBe('Jul 5');
        wrapper.unmount();
    });

    it('plots one point per served bucket and tabulates every one of them', () => {
        const wrapper = render();

        // 30 zero-filled buckets, not just the populated ones.
        expect(wrapper.findAll('.mds-tsc__table tbody tr')).toHaveLength(30);
        wrapper.unmount();
    });
});

/**
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * THE FIRST-RUN MOMENT (Increment J5c — onboarding plan §2)
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * §2 asks for TWO EQUALLY-WEIGHTED CHOICES — start from a template, start from blank — and until J5c this
 * page offered one. The interesting half is not the second card: it is that the template arm is a PAID
 * destination (`forms.templates.index` carries `feature:form_templates`), so "equally weighted" has to
 * survive a free tenant, and the server is the only thing that can say which arm a reader may be offered.
 */
describe('Dashboard — the first run', () => {
    const firstRun = { kpis: { forms: 0, submissions: 0, members: 1 } };

    it('offers both choices as a grid of cards, not as an empty state with two buttons', () => {
        // ⭐ DSR §3.10's governing rule is EXACTLY ONE primary CTA on an empty state — which is why the
        // forms list renders one primary and one tertiary and is right to. §2 names the pattern it wants
        // in the same sentence it asks for equal weight: "presented as the standard card-grid pattern".
        const wrapper = render({}, firstRun);
        const cards = wrapper.findAll('.dash__start-card');

        expect(cards).toHaveLength(2);
        expect(cards[0].text()).toContain('Start from a template');
        expect(cards[1].text()).toContain('Start from blank');
        // Equal weight is the GRID's job, so neither card may be a button variant that outranks the other.
        expect(wrapper.find('.dash__start-grid').exists()).toBe(true);
        wrapper.unmount();
    });

    it('drops the template card when the plan does not admit it, and never locks it', () => {
        // ⭐ ADR-0011 §D9: absent, never a disabled control with an upgrade CTA — Business is held from
        // sale, so an upsell would point at a plan nobody can buy. The moment still works with one card.
        const wrapper = render(
            {},
            { ...firstRun, start: { can_create: true, can_use_templates: false } },
        );

        const cards = wrapper.findAll('.dash__start-card');
        expect(cards).toHaveLength(1);
        expect(cards[0].text()).toContain('Start from blank');
        expect(wrapper.text()).not.toContain('Start from a template');
        expect(wrapper.text().toLowerCase()).not.toContain('upgrade');
        wrapper.unmount();
    });

    it('says "two ways in" only when there ARE two, which is where the degradation lands', () => {
        // ⭐ THE ADVERSARIAL PASS FOUND THIS IN THIS INCREMENT'S OWN NEW CODE. The lede was unconditional,
        // so every Free tenant — the exact readers who get one card — was told there were two. A sentence
        // that is wrong precisely where the degradation happens is worse than no sentence, because the
        // degraded path is the one nobody looks at.
        const both = render({}, firstRun);
        expect(both.text()).toContain('Two ways in');
        both.unmount();

        const one = render({}, { ...firstRun, start: { can_create: true, can_use_templates: false } });
        expect(one.findAll('.dash__start-card')).toHaveLength(1);
        expect(one.text()).not.toContain('Two ways in');
        one.unmount();
    });

    it('explains itself with no CTA at all for a reader who cannot author', () => {
        // ⭐ §3.10's extended rule: a surface empty because of a PERMISSION restriction says WHY, rather
        // than offering a button that would 403. A Reviewer lands on this page too.
        const wrapper = render(
            {},
            { ...firstRun, start: { can_create: false, can_use_templates: false } },
        );

        expect(wrapper.findAll('.dash__start-card')).toHaveLength(0);
        // ⭐ AND THE WHOLE PREAMBLE GOES WITH THE GRID — the second half of the same finding. This reader
        // was previously shown "Create your first form" and "Two ways in" directly above a card saying they
        // could not make one.
        expect(wrapper.text()).not.toContain('Create your first form');
        expect(wrapper.text()).not.toContain('Two ways in');
        // ⭐ AND THE COPY MUST NOT CLAIM THE WORKSPACE IS EMPTY. `kpis.forms` is THIS reader's count, not
        // the organisation's, so "nobody has built a form here" is a claim about rows this page cannot see.
        expect(wrapper.text()).toContain('No form has been shared with you');
        wrapper.unmount();
    });

    it('suppresses the four zero tiles and the trend section entirely', () => {
        // ⭐ §2's own words: "rather than dropping a brand-new tenant onto a literal empty dashboard".
        // Four zeroes and an empty chart above the moment ARE that literal empty dashboard. This is the
        // assertion that fails if a later edit "helpfully" restores the tiles for consistency.
        const wrapper = render({}, firstRun);

        expect(wrapper.findAll('.mds-stat-tile')).toHaveLength(0);
        expect(wrapper.find('.dash__trends').exists()).toBe(false);
        wrapper.unmount();
    });

    it('opens the shared dialog in place rather than sending an empty workspace to an empty list', async () => {
        const wrapper = render({}, firstRun);
        const modal = wrapper.findComponent({ name: 'CreateFormModal' });

        expect(modal.props('open')).toBe(false);
        await wrapper.findAll('.dash__start-card')[1].trigger('click');
        expect(modal.props('open')).toBe(true);
        // ⭐ And it must NOT navigate: a trip to `/forms` here is the duplicated moment J5c removes.
        expect(mocks.visit).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('withholds the header’s own Create button, which would lead back to this same choice', () => {
        // ⭐ FOUND BY LOOKING AT THE RENDERED PAGE, NOT BY A GATE — the J4c2 lesson paying off again. The
        // header action put a THIRD create affordance on a screen whose whole point is one lightweight
        // choice, and it is the worst of the three: it goes to `/forms`, which for a workspace with no
        // forms renders its own empty state offering the same two choices again. A button that leads to a
        // second copy of the screen you are on is PRD §3.7's non-duplicative principle failing exactly
        // where onboarding §2 asks for a single choice point.
        const first = render({}, firstRun);
        expect(first.findAll('button').filter((b) => b.text() === 'Create form')).toHaveLength(0);
        first.unmount();
    });

    it('keeps the tiles, the trends and the header action the moment a form exists', () => {
        const wrapper = render();

        expect(wrapper.find('.dash__start-grid').exists()).toBe(false);
        expect(wrapper.find('.dash__trends').exists()).toBe(true);
        // ⭐ The other half of the assertion above: the header action is ordinary once there is a list to
        // go to, so this is a suppression scoped to one state rather than a deletion.
        expect(wrapper.findAll('button').filter((b) => b.text() === 'Create form').length).toBeGreaterThan(0);
        wrapper.unmount();
    });
});

/**
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * THE GETTING-STARTED CHECKLIST (Increment J5b)
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * A PASSIVE card, never a gate: onboarding §2 argues against a scripted tour in its own words, and §6 lists
 * a multi-step product tour as deliberately out of scope. Everything about WHICH rows appear is decided in
 * `GettingStartedChecklist` and pinned by Pest; what is pinned here is that this page renders what it is
 * given, says nothing twice, and does not hide the card by itself.
 */
describe('Dashboard — the getting-started checklist', () => {
    const items = [
        { key: 'create_form', label: 'Create your first form', done: true },
        { key: 'publish_form', label: 'Publish it', done: false, href: '/forms' },
    ];

    it('renders nothing when the server sends null', () => {
        const wrapper = render();

        expect(wrapper.find('.mds-checklist').exists()).toBe(false);
        wrapper.unmount();
    });

    it('renders the server’s rows, with its own progress reading', () => {
        const wrapper = render({}, { checklist: items });

        expect(wrapper.findAll('.mds-checklist__row')).toHaveLength(2);
        expect(wrapper.find('[role="progressbar"]').attributes('aria-valuetext')).toBe('1 of 2');
        wrapper.unmount();
    });

    it('posts the dismissal instead of hiding the card locally', async () => {
        // ⭐ An optimistic local hide would make the card vanish even when the write failed, and the user
        // would find it back tomorrow with no idea why. `MdsChecklist` never hides itself (the MdsAlert
        // contract), so the disappearance is the server's answer on the next render.
        const wrapper = render({}, { checklist: items });

        await wrapper.find('.mds-checklist__dismiss').trigger('click');

        expect(mocks.post).toHaveBeenCalledWith('/onboarding/dismiss', {}, { preserveScroll: true });
        expect(wrapper.find('.mds-checklist').exists()).toBe(true);
        wrapper.unmount();
    });

    it('never appears beside the first-run moment, which already says the same sentence', () => {
        // ⭐ Both surfaces open with "create your first form". PRD §3.7's non-duplicative principle, and
        // the reason `GettingStartedChecklist`'s first row is always a tick rather than a task.
        const wrapper = render({}, { kpis: { forms: 0, submissions: 0, members: 1 }, checklist: items });

        expect(wrapper.find('.dash__start-grid').exists()).toBe(true);
        expect(wrapper.find('.mds-checklist').exists()).toBe(false);
        wrapper.unmount();
    });
});
