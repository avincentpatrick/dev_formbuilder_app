import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The global search results page (Increment J2e).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ THIS FILE DID NOT EXIST BEFORE J2e, AND THAT WAS THE REAL RISK IN MIGRATING THIS PAGE.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * `/search` had no component spec at all. Its only automated coverage was `tests/e2e/search-nav.spec.ts`
 * — which cannot run locally — and `tests/Feature/Search/SearchPageTest.php`, which asserts PROPS and is
 * blind to markup. So the swap onto `MdsFilterBar` + `MdsSearchField` was, until this file, a change to
 * an axe-scanned page with no local gate of any kind.
 *
 * What it pins is what the migration could plausibly break: the unconditional level-2 heading (which fails
 * `heading-order` only in the EMPTY state, i.e. the state a query creates most often), the commit path now
 * that the explicit Search button is gone, and the empty-state branching that is driven by the server's
 * `empty_reason` rather than by inspecting the local refs.
 */

const mocks = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.get, visit: mocks.visit },
    // Kept even though the page does not read it: removing an Inertia mock key is how a fresh, unrelated
    // failure appears in this suite.
    usePage: () => ({ props: { auth: { can: {} } } }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        props: ['title'],
        template: '<header><h1>{{ title }}</h1></header>',
    },
}));

const SearchIndex = (await import('./Index.vue')).default;

type Props = {
    // ⚠️ `items`, NOT `rows` — the key the page actually reads. The first draft of this fixture guessed
    // `rows`, every case that renders a populated group threw on `group.items.length`, and only CI could
    // see it (local Vitest was hanging at teardown on this host). Read the SFC's own SearchGroup type.
    data: { entity: string; label: string; items: { id: string; title: string; subtitle: string; url: string }[]; has_more: boolean }[];
    counts: Record<string, number>;
    filters: { entities: { value: string; label: string }[]; applied: { q: string | null; entity: string | null } };
    limits: { per_entity: number; single_entity: number };
    empty_reason: 'no_query' | 'no_matches' | 'no_permitted_scopes' | null;
};

function render(overrides: Partial<Props> = {}): VueWrapper {
    return mount(SearchIndex, {
        props: {
            data: [
                {
                    entity: 'forms',
                    label: 'Forms',
                    items: [{ id: 'f1', title: 'Clinic Intake', subtitle: 'Published', url: '/forms/f1' }],
                    has_more: false,
                },
            ],
            counts: { forms: 1 },
            filters: {
                entities: [{ value: 'forms', label: 'Forms' }],
                applied: { q: 'clinic', entity: null },
            },
            limits: { per_entity: 5, single_entity: 25 },
            empty_reason: null,
            ...overrides,
        } as Props,
        global: { stubs: { teleport: true } },
    });
}

describe('search results — the filter bar', () => {
    it('renders the level-2 Filters heading in both the populated and the empty state', () => {
        // ⭐ The contract `MdsFilterBar` exists to carry. PageHeader renders the h1 and MdsEmptyState an h3,
        // so a missing h2 fails axe heading-order ONLY when the list is empty — which for a search page is
        // the single most common state. The e2e suite scans exactly that, and cannot run locally.
        const populated = render();
        expect(populated.find('.mds-filterbar h2').text()).toBe('Filters');
        populated.unmount();

        const empty = render({ data: [], counts: {}, empty_reason: 'no_matches' });
        expect(empty.find('.mds-filterbar h2').text()).toBe('Filters');
        empty.unmount();
    });

    it('seeds the search box from what the SERVER applied, not from the URL', () => {
        // `filters.applied.q` is the CLAMPED query (SearchTerms::raw()), so the box shows what actually ran
        // rather than what was typed — the J1e "echo with no reader" defect, one page over.
        const wrapper = render();

        expect((wrapper.get('input[type="search"]').element as HTMLInputElement).value).toBe('clinic');

        wrapper.unmount();
    });

    it('never disables the keyword input, even mid-round-trip', () => {
        // MdsSearchField has no `disabled` prop at all, and this asserts the page cannot reintroduce one by
        // copying a neighbour: disabling a focused text input blurs it and eats the rest of the word.
        const wrapper = render();

        expect(wrapper.get('input[type="search"]').attributes('disabled')).toBeUndefined();

        wrapper.unmount();
    });

    it('commits on Enter now that the explicit Search button is gone', async () => {
        // ⭐ THE CASE THE MIGRATION MOST NEEDED. J2e deleted the page's "Search" MdsButton, which was the
        // only unconditional re-submit; if the field's commit path were also broken the page would simply
        // stop searching, and no server test could see it.
        //
        // ⚠️ `await` ON setValue IS LOAD-BEARING, NOT STYLE. MdsSearchField's commit() returns early when
        // `modelValue` still equals what the server last ran — and `modelValue` only reaches it after Vue
        // flushes. Firing keyup synchronously means the latch sees the OLD value, refuses, and the case
        // fails as "called 0 times" while the component is behaving perfectly.
        mocks.get.mockClear();
        const wrapper = render();

        const input = wrapper.get('input[type="search"]');
        await input.setValue('measles');
        await input.trigger('keyup.enter');

        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(mocks.get).toHaveBeenCalledWith(
            '/search',
            { q: 'measles' },
            expect.objectContaining({ replace: true }),
        );

        wrapper.unmount();
    });

    it('sends no q at all when the box is cleared', async () => {
        // An empty string must be ABSENT rather than `q=`, or the server reads a present-but-empty key and
        // the "no query" branch stops being reachable from the UI.
        mocks.get.mockClear();
        const wrapper = render();

        const input = wrapper.get('input[type="search"]');
        await input.setValue('');
        await input.trigger('keyup.enter');

        expect(mocks.get).toHaveBeenCalledWith('/search', {}, expect.objectContaining({ replace: true }));

        wrapper.unmount();
    });
});

describe('search results — the empty states', () => {
    it('distinguishes no-query from no-matches, driven by the server', () => {
        // The branch reads `empty_reason`, never the local refs: the client cannot see what the server
        // clamped or defaulted.
        const cold = render({ data: [], counts: {}, filters: { entities: [], applied: { q: null, entity: null } }, empty_reason: 'no_query' });
        expect(cold.text()).toContain('Search your workspace');
        cold.unmount();

        const matched = render({ data: [], counts: {}, empty_reason: 'no_matches' });
        expect(matched.text()).toContain('No results for');
        matched.unmount();
    });

    it('renders a refused scope IDENTICALLY to a query that matched nothing', () => {
        // ⚠️ ONE STATE, ONE STRING — deliberate. Telling a user "there are results you may not see" is a
        // disclosure; `no_permitted_scopes` and `no_matches` therefore share one block.
        //
        // ⚠️ ASSERTED AS AN EQUALITY, NOT AS THE ABSENCE OF A WORD. The first draft checked that the output
        // did not contain "permission" and failed — because the page carries an UNCONDITIONAL scope note
        // ("Only results you have permission to see are shown", Index.vue:133) on every state, which is a
        // standing disclosure rather than a per-state hint. Probing for a word tested the copy; comparing
        // the two renders tests the CONTRACT, and cannot be broken by unrelated wording.
        const refused = render({ data: [], counts: {}, empty_reason: 'no_permitted_scopes' });
        const matched = render({ data: [], counts: {}, empty_reason: 'no_matches' });

        expect(refused.text()).toBe(matched.text());
        expect(refused.text()).toContain('No results for');

        refused.unmount();
        matched.unmount();
    });
});
