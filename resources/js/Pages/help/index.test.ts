import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The user manual page (M162, `R-f9211765`, `D114`).
 *
 * `manual.test.ts` checks the TEXT against the screens it describes; this file checks that the page shows all of it,
 * and shows it in the shape a reader and a screen reader rely on:
 *
 *  1. EVERY CHAPTER, ADDRESSABLE. Each chapter and section carries its id, because the contents list, the manual's
 *     own cross-references and any link sent to a tester (`/help#publish`) jump to them.
 *  2. THE CONTENTS LIST MATCHES THE CHAPTERS, in order — a contents entry with no chapter behind it is a dead link.
 *  3. A LABEL LOOKS LIKE A LABEL. The manual promises every button it names is in bold; a `ui` span drawn as plain
 *     text breaks that promise silently.
 *  4. THE HEADING LADDER. h1 → h2 → h3 with no level skipped, so a screen reader's heading list IS the contents.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
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

const { manual, quotedLabels } = await import('@/components/help/manual');
const Help = (await import('./Index.vue')).default;

function render(): VueWrapper {
    return mount(Help, { attachTo: document.body });
}

describe('the Help page', () => {
    it('renders every chapter and every section with its id', () => {
        const wrapper = render();

        for (const chapter of manual) {
            const node = wrapper.find(`section#${chapter.id}`);
            expect(node.exists(), chapter.id).toBe(true);
            expect(node.find('h2').text()).toBe(chapter.title);
            for (const section of chapter.sections) {
                expect(node.find(`#${section.id} h3`).text(), section.id).toBe(section.title);
            }
        }
        wrapper.unmount();
    });

    it('lists the chapters in the contents, in order, each jumping to its chapter', () => {
        const wrapper = render();
        const links = wrapper.findAll('nav[aria-labelledby="manual-contents-heading"] a');

        expect(links.map((a) => a.attributes('href'))).toEqual(manual.map((c) => `#${c.id}`));
        expect(links.map((a) => a.text())).toEqual(manual.map((c) => c.title));
        wrapper.unmount();
    });

    it('draws every label the manual quotes in bold', () => {
        const wrapper = render();
        const bold = wrapper.findAll('strong.manual-ui').map((n) => n.text());

        expect(bold).toEqual(quotedLabels().map((l) => l.label));
        wrapper.unmount();
    });

    it('keeps the heading ladder unbroken: one h1, then h2 and h3 only', () => {
        const wrapper = render();

        expect(wrapper.findAll('h1')).toHaveLength(1);
        // The contents heading plus one per chapter.
        expect(wrapper.findAll('h2')).toHaveLength(manual.length + 1);
        expect(wrapper.findAll('h3')).toHaveLength(manual.flatMap((c) => c.sections).length);
        expect(wrapper.findAll('h4, h5, h6')).toHaveLength(0);
        wrapper.unmount();
    });

    it('gives no two elements the same id', () => {
        const wrapper = render();
        const ids = wrapper.findAll('[id]').map((n) => n.attributes('id'));

        expect(new Set(ids).size).toBe(ids.length);
        wrapper.unmount();
    });

    it('shows each tip as a static note, never a live region', () => {
        const wrapper = render();
        const tips = manual.flatMap((c) => c.sections.flatMap((s) => s.blocks)).filter((b) => b.kind === 'tip');

        expect(tips.length).toBeGreaterThan(0);
        expect(wrapper.findAll('[role="note"]')).toHaveLength(tips.length);
        expect(wrapper.findAll('[role="status"], [role="alert"], [aria-live]')).toHaveLength(0);
        wrapper.unmount();
    });
});
