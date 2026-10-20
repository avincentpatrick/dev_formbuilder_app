import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { WhatsNewRelease } from '@/types/inertia';

/**
 * M181 (`R-c7247dbf`, `D125` C) — the What's new page: every release from the `releases` table, newest first (the
 * server orders them by version), each with its date and its changes marked New, Improved or Fixed. Plain text only:
 * a release note is never rendered as HTML (`raw-html-sink-census.test.ts`).
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', props: ['title'], template: '<div />' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: {} }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', props: ['title', 'icon'], template: '<h1>{{ title }}</h1>' },
}));

const Index = (await import('./Index.vue')).default;

const releases: WhatsNewRelease[] = [
    {
        version: '0.10.0',
        title: 'Grids',
        released_at: '2026-11-02T03:00:00+00:00',
        items: [
            { kind: 'new', text: 'A grid question.' },
            { kind: 'improved', text: 'Help explains grids.', href: '/help' },
        ],
    },
    {
        version: '0.9.0',
        title: 'Version numbers',
        released_at: '2026-10-21T03:00:00+00:00',
        items: [{ kind: 'fixed', text: 'A <b>tag</b> stays text.' }],
    },
];

describe("What's new page", () => {
    it('lists every release in the order the server sent, with its version', () => {
        const wrapper = mount(Index, { props: { releases } });

        expect(wrapper.get('h1').text()).toBe("What's new");
        expect(wrapper.findAll('.release__title').map((h) => h.text())).toEqual(['Grids', 'Version numbers']);
        expect(wrapper.findAll('.release__meta').map((m) => m.text())).toEqual([
            expect.stringContaining('Version 0.10.0'),
            expect.stringContaining('Version 0.9.0'),
        ]);
    });

    it('marks each change by its kind, in words', () => {
        const wrapper = mount(Index, { props: { releases } });

        expect(wrapper.findAll('.release__item').map((li) => li.find('.mds-badge').text())).toEqual(['New', 'Improved', 'Fixed']);
    });

    it('links a change to its page when it names one', () => {
        const wrapper = mount(Index, { props: { releases } });

        expect(wrapper.get('.release__item a').attributes('href')).toBe('/help');
    });

    it('shows a note as text, never as markup', () => {
        const wrapper = mount(Index, { props: { releases } });

        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('A <b>tag</b> stays text.');
    });

    it('dates each release by when it went live', () => {
        const wrapper = mount(Index, { props: { releases } });

        expect(wrapper.findAll('time').map((t) => t.attributes('datetime'))).toEqual([
            '2026-11-02T03:00:00+00:00',
            '2026-10-21T03:00:00+00:00',
        ]);
    });

    it('says so when there are no release notes yet', () => {
        const wrapper = mount(Index, { props: { releases: [] } });

        expect(wrapper.text()).toContain('No release notes yet');
    });
});
