import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The achievements surface (Increment K1e).
 *
 * ⚠️ WHAT THIS FILE IS FOR, given `AchievementsPageTest.php` already pins every prop the server sends:
 * the props are not the risk — the RENDERING DECISIONS are, and every one of them is a decision this page
 * makes alone and no PHP test can see.
 *
 *  1. THE GATED SECTION. `scoreboard` arrives null and the whole workspace block must vanish — heading,
 *     tiles, ladder and the §D11(c) note together. A page that rendered the heading and hid only the
 *     ladder would print a section title over nothing for every Form Editor in the product.
 *  2. THE §D11(c) NOTE ITSELF. ADR-0020 says a surface putting the workspace totals beside the ladder they
 *     do not add up to must say WHICH IS WHICH, in copy. This is the only gate that can see whether it is
 *     actually on the page, and it must travel with the numbers it explains.
 *  3. THE EMPTY STATE BEING REACHABLE. The first draft guarded it on "both halves of the shelf are empty",
 *     which `BadgeShelf::assemble()` makes impossible on-tenant — dead code carrying copy for a state it
 *     could not reach. The reachable state is "nothing EARNED, ten in progress".
 *  4. COMPETITION RANKING SURVIVING THE MARKUP. Ranks skip, so the ladder is a `<ul>` with the rank as
 *     text; an `<ol>` would have assistive technology announce positional order that contradicts it.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    // Kept even though this page does not navigate: removing an Inertia mock key is how a fresh,
    // unrelated failure appears in a suite (Sidebar.test.ts learned this the expensive way in K1e).
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: () => () => {} },
    usePage: () => ({ props: { auth: { can: {} } } }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        props: ['title', 'icon'],
        template: '<header><h1>{{ title }}</h1></header>',
    },
}));

const Achievements = (await import('./Index.vue')).default;

function badge(over: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        key: 'collector',
        label: 'Collector',
        description: 'Collected twenty-five responses.',
        earned_on: null,
        progress: 12,
        threshold: 25,
        ...over,
    };
}

function render(over: Record<string, unknown> = {}): VueWrapper {
    return mount(Achievements, {
        props: {
            progress: {
                points: 140,
                badges: 3,
                standing: { rank: 4, of: 12 },
                streak: { current: 7, longest: 21, last_active_on: '2026-08-18T00:00:00+00:00' },
            },
            shelf: { earned: [], in_progress: [badge()] },
            scoreboard: null,
            ...over,
        },
    });
}

const SCOREBOARD = {
    entries: [
        { rank: 1, user_id: 'u1', name: 'Ada Lovelace', points: 300, badges: 5 },
        { rank: 2, user_id: 'u2', name: 'Grace Hopper', points: 200, badges: 4 },
        // A tie for 2nd, so the next place is 4th — never 3rd.
        { rank: 2, user_id: 'u3', name: 'Alan Turing', points: 200, badges: 4 },
        { rank: 4, user_id: 'u4', name: 'Katherine Johnson', points: 10, badges: 1 },
    ],
    member_count: 4,
    team: {
        points: 900,
        responses: 4200,
        published_forms: 7,
        active_members: 4,
        badges: 14,
        contributors: 6,
    },
};

describe('Achievements — your own progress, which needs no permission', () => {
    it('shows current and longest as two separately labelled figures', () => {
        const text = render().text();

        // ⚠️ BOTH NUMBERS, BOTH LABELLED. MemberStreak's docblock: current decays to zero after a missed
        // day, longest only ever rises. Rendering one and labelling it the other tells a member they lost
        // an achievement they still hold — and the two are indistinguishable in a screenshot.
        expect(text).toContain('Current streak');
        expect(text).toContain('7 days');
        expect(text).toContain('Longest streak');
        expect(text).toContain('21 days');
    });

    it('ordinalises a rank correctly rather than suffixing "th"', () => {
        expect(render().text()).toContain('4th of 12');
        expect(render({ progress: { ...render().props('progress'), standing: { rank: 1, of: 3 } } }).text())
            .toContain('1st of 3');
    });

    it('drops the denominator but keeps the rank when the headcount is withheld', () => {
        // ADR-0020 §D13. `of` is the WORKSPACE HEADCOUNT rather than this member's own number, so the server
        // nulls it for a reader without `dashboard.org.view` — the same key `/dashboard`'s Members tile,
        // `/members` and the member search arm already withhold it behind. §D7's grant is the member's own
        // POSITION, which is untouched: the tile stays and says "4th".
        const wrapper = render({
            progress: {
                points: 140,
                badges: 3,
                standing: { rank: 4, of: null },
                streak: { current: 7, longest: 21, last_active_on: '2026-08-18T00:00:00+00:00' },
            },
        });

        expect(wrapper.text()).toContain('Your place');
        expect(wrapper.text()).toContain('4th');
        // ⚠️ THE LOAD-BEARING HALF. `toContain('4th')` alone passes on "4th of 12" too, so it would go green
        // against the very defect this asserts. The denominator's absence is what is actually being tested.
        expect(wrapper.text()).not.toContain('4th of');

        // The caption explains the denominator, so a fixed string would describe a number no longer on screen.
        expect(wrapper.text()).not.toContain('Counts everyone on the team');
    });

    it('says nothing about a place when the reader holds no active membership', () => {
        // `rank: null` is the deliberate empty value — never 0, which renders as a position. The tile is
        // absent rather than showing "—", because "you have no place" is not a fact worth a tile.
        const wrapper = render({
            progress: {
                points: 0,
                badges: 0,
                standing: { rank: null, of: 0 },
                streak: { current: 0, longest: 0, last_active_on: null },
            },
        });

        expect(wrapper.text()).not.toContain('Your place');
        expect(wrapper.text()).not.toContain('0th');
    });

    it('pluralises a one-day streak', () => {
        const wrapper = render({
            progress: {
                points: 5,
                badges: 1,
                standing: { rank: 1, of: 1 },
                streak: { current: 1, longest: 1, last_active_on: '2026-08-18T00:00:00+00:00' },
            },
        });

        expect(wrapper.text()).toContain('1 day');
        expect(wrapper.text()).not.toContain('1 days');
    });
});

describe('Achievements — the badge shelf', () => {
    it('shows the empty state in the state that is actually reachable: nothing earned, some in progress', () => {
        // ⚠️ THE REGRESSION THIS CASE EXISTS FOR. `BadgeShelf::assemble()` walks the whole catalog, so the
        // two halves always sum to ten and "both empty" cannot happen on-tenant. Guarding the empty state
        // on that — which the first draft did — makes it dead code and leaves a member holding nothing
        // with a bare "In progress" heading and no explanation above it.
        const wrapper = render({ shelf: { earned: [], in_progress: [badge()] } });

        expect(wrapper.text()).toContain('No badges yet');
        // ...and the in-progress list is still rendered beneath it, rather than replaced by it.
        expect(wrapper.text()).toContain('Collector');
        expect(wrapper.text()).toContain('In progress');
    });

    it('drops the empty state as soon as one badge is held', () => {
        const wrapper = render({
            shelf: {
                earned: [badge({ key: 'welcome', label: 'Welcome', earned_on: '2026-07-04T10:00:00+00:00' })],
                in_progress: [badge()],
            },
        });

        expect(wrapper.text()).not.toContain('No badges yet');
        expect(wrapper.text()).toContain('Earned');
    });

    it('renders an earned badge with a machine-readable date and no meter', () => {
        const wrapper = render({
            shelf: {
                earned: [badge({ key: 'welcome', label: 'Welcome', earned_on: '2026-07-04T10:00:00+00:00' })],
                in_progress: [],
            },
        });

        // The date is the one fact a badge row holds that nothing else in the schema can reproduce
        // (ADR-0020 §D9), so it travels as <time datetime> rather than as a formatted string alone.
        expect(wrapper.find('time').attributes('datetime')).toBe('2026-07-04T10:00:00+00:00');
        expect(wrapper.findAll('.ach__badge-meter')).toHaveLength(0);
    });

    it('meters an unearned badge as "N of M" and never as a percentage', () => {
        const wrapper = render({ shelf: { earned: [], in_progress: [badge()] } });

        // The J5 rule for MdsProgress, which TeamProgress's docblock names K1e as the consumer of. A
        // percentage rounds 24-of-25 to 96% and tells somebody one response away that they are "nearly
        // there" without saying how nearly.
        expect(wrapper.text()).toContain('12 of 25');
        expect(wrapper.text()).not.toContain('%');
    });
});

describe('Achievements — the workspace section is gated whole', () => {
    it('renders nothing of it at all when scoreboard is null', () => {
        const text = render({ scoreboard: null }).text();

        // ⚠️ THE HEADING TOO, not just the ladder. A section title over nothing is what a partial gate
        // produces, and every Form Editor in the product is in this branch.
        expect(text).not.toContain('This workspace');
        expect(text).not.toContain('Leaderboard');
        expect(text).not.toContain('Team points');
        // And the §D11(c) note must not be stranded on a page with no numbers to explain.
        expect(text).not.toContain('count the whole workspace');
    });

    it('carries the §D11(c) note in COPY beside the totals it explains', () => {
        const text = render({ scoreboard: SCOREBOARD }).text();

        // ⚠️ ADR-0020 §D11(c) requires this, and requires it as text rather than as a tooltip: the reader
        // who needs it is the one who has just noticed the numbers disagree, and they will not hover.
        // All three gaps are named — guest responses, and departed members in two forms.
        expect(text).toContain('larger than the leaderboard adds up to');
        expect(text).toContain('public links');
        expect(text).toContain('teammates who have since left');
    });

    it('names both figures in a ladder row, so no number is unlabelled', () => {
        const wrapper = render({ scoreboard: SCOREBOARD });

        expect(wrapper.text()).toContain('300 pts');
        // An award glyph beside a bare "5" is an unlabelled number to anybody not looking at it, and there
        // is no shared `.sr-only` in this repository to caption it with.
        expect(wrapper.text()).toContain('5 badges');
    });

    it('renders the ladder as a ul, because competition ranks skip and an ol would contradict them', () => {
        const wrapper = render({ scoreboard: SCOREBOARD });
        const ladder = wrapper.find('.ach__ladder');

        // ⚠️ AN `<ol>` ANNOUNCES POSITIONAL ORDER. With a tie for 2nd the fourth row is DOM position 4 but
        // rank 4 — and the third row is position 3 while reading "2nd". The rank therefore travels as text
        // exactly once, where the rendered and the announced answer cannot diverge.
        expect(ladder.element.tagName).toBe('UL');
        expect(ladder.attributes('role')).toBe('list');

        const ranks = wrapper.findAll('.ach__ladder-rank').map((n) => n.text());
        expect(ranks).toEqual(['1st', '2nd', '2nd', '4th']);
    });
});
