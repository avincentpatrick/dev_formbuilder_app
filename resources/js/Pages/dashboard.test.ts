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
        auth: { user: { name: 'Demo Owner' }, can: { manageForms: true, viewAnalytics: false } },
        entitlements: { features: { advanced_analytics: false } },
    },
    visit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { visit: mocks.visit },
    usePage: () => ({ props: mocks.pageProps }),
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
        top_forms: { rows: [{ key: 'f1', label: 'Clinic Intake', count: 9 }], other: null, unassigned: 0 },
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

function render(overrides: Record<string, unknown> = {}): VueWrapper {
    return mount(Dashboard, {
        props: { kpis: { forms: 14, submissions: 9, members: 2 }, trends: trends(overrides) },
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
                    { key: 'f1', label: 'Clinic Intake', count: 9 },
                    { key: 'f2', label: 'Household Roster', count: 4 },
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

        // The §D12 non-visual equivalent. Swapping MdsBarChart for a bare <svg aria-label> is the exact
        // substitution axe cannot see, and this is what reddens on it.
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
        // Two adjacent cards rendering the identical sentence teaches nothing.
        expect(channels.text()).not.toBe(card(wrapper, 'Top forms').text());
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
