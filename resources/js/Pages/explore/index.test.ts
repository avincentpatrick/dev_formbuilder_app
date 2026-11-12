import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { Definition, SavedChart } from '@/components/explore/types';

/**
 * M235 — the /explore board: which charts it lays out, under which heading, and when it offers to build one.
 *
 * `ExploreTile` is stubbed: each real tile fetches its own result on mount, and that is its own spec's
 * concern (explore-components.test.ts). What this page decides is only WHERE a chart goes — and a chart
 * filed under the wrong heading tells a reader it is theirs to change when it is not.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

vi.mock('@/components/explore/ExploreTile.vue', () => ({
    default: {
        name: 'ExploreTile',
        props: ['chart'],
        template: '<article class="tile-stub" :data-id="chart.id">{{ chart.name }}</article>',
    },
}));

// Imported AFTER the mocks so the component resolves them.
const Board = (await import('./Index.vue')).default;

function definition(): Definition {
    return {
        v: 1,
        source: 'responses',
        form_id: null,
        chart: 'bar',
        dimensions: [{ field: 'source', bucket: null }],
        measures: [{ aggregate: 'count', field: null }],
        filters: [],
        from: '2026-03-01',
        to: '2026-03-31',
        timezone: 'Asia/Manila',
        sort: 'value_desc',
        limit: 10,
    };
}

function chart(id: string, name: string, overrides: Partial<SavedChart> = {}): SavedChart {
    return {
        id,
        name,
        definition: definition(),
        is_shared: false,
        is_mine: true,
        owner: null,
        source_label: 'Responses',
        can: { update: true, delete: true },
        updated_at: '2026-03-31T09:00:00+08:00',
        ...overrides,
    };
}

function theirs(id: string, name: string): SavedChart {
    return chart(id, name, { is_mine: false, is_shared: true, owner: 'Ana Reyes', can: { update: false, delete: false } });
}

function render(charts: SavedChart[], canCreate = true): VueWrapper {
    return mount(Board, { props: { charts, can: { create: canCreate }, limits: { max_charts: 50 } } });
}

function section(wrapper: VueWrapper, heading: string) {
    return wrapper.findAll('section').find((s) => s.find('h2').text() === heading);
}

function tileIds(wrapper: VueWrapper, heading: string): string[] {
    return (section(wrapper, heading)?.findAll('.tile-stub') ?? []).map((t) => t.attributes('data-id') ?? '');
}

describe('Explore board — the empty state', () => {
    it('says there are no charts yet and offers "Build a chart" to a member who may create one', () => {
        const wrapper = render([], true);

        expect(wrapper.text()).toContain('No charts yet');
        const build = wrapper.findAll('a').find((a) => a.text() === 'Build a chart');
        expect(build, 'no "Build a chart" link').toBeDefined();
        expect(build?.attributes('href')).toBe('/explore/new');
        expect(wrapper.findAll('section')).toHaveLength(0);
        wrapper.unmount();
    });

    it('offers no "Build a chart" link, and no "New chart" either, to a member who may not create one', () => {
        // The empty state still explains itself; only the action is withheld, because a button that leads
        // to a refusal is worse than none.
        const wrapper = render([], false);

        expect(wrapper.text()).toContain('No charts yet');
        expect(wrapper.text()).not.toContain('Build a chart');
        expect(wrapper.text()).not.toContain('New chart');
        expect(wrapper.find('a[href="/explore/new"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('drops the empty state once there is a chart', () => {
        const wrapper = render([chart('c1', 'Responses by channel')]);

        expect(wrapper.text()).not.toContain('No charts yet');
        expect(wrapper.text()).not.toContain('Build a chart');
        wrapper.unmount();
    });
});

describe('Explore board — the two sections', () => {
    it('files own charts under "Your charts" and colleagues’ under "Shared with the workspace"', () => {
        const wrapper = render([
            chart('c1', 'Responses by channel'),
            theirs('c2', 'Members by role'),
            // Shared BY the reader is still the reader's: it belongs under their own heading.
            chart('c3', 'Forms by status', { is_shared: true }),
            theirs('c4', 'Answers by district'),
        ]);

        expect(tileIds(wrapper, 'Your charts')).toEqual(['c1', 'c3']);
        expect(tileIds(wrapper, 'Shared with the workspace')).toEqual(['c2', 'c4']);
        expect(wrapper.findAll('.tile-stub')).toHaveLength(4);
        wrapper.unmount();
    });

    it('shows only "Your charts" when nothing is shared with the reader', () => {
        const wrapper = render([chart('c1', 'Responses by channel')]);

        expect(section(wrapper, 'Your charts')).toBeDefined();
        expect(wrapper.text()).not.toContain('Shared with the workspace');
        expect(wrapper.findAll('section')).toHaveLength(1);
        wrapper.unmount();
    });

    it('shows only "Shared with the workspace" when the reader has kept none of their own', () => {
        const wrapper = render([theirs('c2', 'Members by role')], false);

        expect(section(wrapper, 'Shared with the workspace')).toBeDefined();
        expect(wrapper.text()).not.toContain('Your charts');
        expect(tileIds(wrapper, 'Shared with the workspace')).toEqual(['c2']);
        // Not empty, so no empty state — even for a member who cannot build one.
        expect(wrapper.text()).not.toContain('No charts yet');
        wrapper.unmount();
    });

    it('passes each chart to its tile whole', () => {
        const mine = chart('c1', 'Responses by channel');
        const wrapper = render([mine]);

        expect(wrapper.findComponent({ name: 'ExploreTile' }).props('chart')).toEqual(mine);
        wrapper.unmount();
    });
});
