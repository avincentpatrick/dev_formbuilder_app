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

const { MANUAL_PARTS, manual, manualFigures, quotedLabels } = await import('@/components/help/manual');
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
        // One per section, plus one per part in the contents list (M163).
        expect(wrapper.findAll('h3')).toHaveLength(manual.flatMap((c) => c.sections).length + MANUAL_PARTS.length);
        expect(wrapper.findAll('h4, h5, h6')).toHaveLength(0);
        wrapper.unmount();
    });

    it('gives no two elements the same id', () => {
        const wrapper = render();
        const ids = wrapper.findAll('[id]').map((n) => n.attributes('id'));

        expect(new Set(ids).size).toBe(ids.length);
        wrapper.unmount();
    });

    it('groups the contents by part, the walk-through first, and names the part of each chapter on its card', () => {
        const wrapper = render();
        const groups = wrapper.findAll('.manual__contents-group');

        expect(groups.map((g) => g.find('h3').text())).toEqual(MANUAL_PARTS.map((part) => part.title));
        for (const [i, part] of MANUAL_PARTS.entries()) {
            expect(groups[i].findAll('a').map((a) => a.attributes('href'))).toEqual(
                manual.filter((c) => c.part === part.key).map((c) => `#${c.id}`),
            );
        }
        for (const chapter of manual) {
            const title = MANUAL_PARTS.find((part) => part.key === chapter.part)?.title;
            expect(wrapper.find(`section#${chapter.id} .manual__chapter-part`).text(), chapter.id).toBe(title);
        }
        wrapper.unmount();
    });

    it('shows each picture with its description, at its own size', () => {
        const wrapper = render();
        const images = wrapper.findAll('figure img');

        expect(images.map((img) => img.attributes('src'))).toEqual(manualFigures().map((f) => f.src));
        for (const [i, figure] of manualFigures().entries()) {
            expect(images[i].attributes('alt')).toBe(figure.alt);
            expect(images[i].attributes('width')).toBe(String(figure.width));
            expect(images[i].attributes('height')).toBe(String(figure.height));
        }
        expect(wrapper.findAll('figure figcaption')).toHaveLength(manualFigures().length);
        wrapper.unmount();
    });

    it('draws what each type can do as a table, naming each type in bold (M187)', () => {
        const wrapper = render();
        const table = wrapper.find('section#types-readiness table');

        expect(table.exists()).toBe(true);
        expect(table.find('caption').text()).toBe('What each question type can do');
        expect(table.findAll('thead th').map((th) => th.text())).toContain('Web form and manual entry');
        const signatureRow = table.findAll('tbody tr').find((tr) => tr.find('td').text().startsWith('Signature'));
        expect(signatureRow, 'a row for the Signature type').toBeDefined();
        expect(signatureRow!.find('td strong.manual-ui').text()).toBe('Signature');
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

/**
 * M164 — the contents stay within reach (the user's comment on `M163`: *"is there another way that contents are always
 * seen? can we have the contents on the left side?"*). The sticky column itself is CSS and is proved in a real browser
 * by `tests/e2e/help-contents.spec.ts`; these cases pin what the markup owes it.
 */
describe('the Help page keeps its contents within reach', () => {
    it('ends every chapter with a way back to the contents, and offers one fixed button', () => {
        const wrapper = render();

        expect(wrapper.find('nav#manual-contents').exists()).toBe(true);
        for (const chapter of manual) {
            expect(wrapper.find(`section#${chapter.id} a.manual__back-link`).attributes('href'), chapter.id).toBe('#manual-contents');
        }
        expect(wrapper.findAll('a.manual__to-contents').map((a) => a.attributes('href'))).toEqual(['#manual-contents']);
        wrapper.unmount();
    });

    describe('marks the chapter being read', () => {
        const ids = manual.map((c) => c.id);
        let offset = 0;
        let lastHeight = 900;

        /** Chapter i sits 1000px below chapter i-1; `offset` is how far the page has scrolled. */
        function place(scrolled: number, lastChapterHeight = 900): void {
            offset = scrolled;
            lastHeight = lastChapterHeight;
        }

        function box(top: number, height: number): DOMRect {
            return { top, bottom: top + height, left: 0, right: 800, width: 800, height, x: 0, y: top, toJSON: () => ({}) } as DOMRect;
        }

        async function scroll(wrapper: VueWrapper): Promise<string[]> {
            document.dispatchEvent(new Event('scroll'));
            await new Promise((resolve) => requestAnimationFrame(() => resolve(null)));
            await wrapper.vm.$nextTick();
            return wrapper.findAll('nav#manual-contents [aria-current="location"]').map((a) => a.attributes('href') ?? '');
        }

        it('as the reader scrolls, and the last one at the foot of the page', async () => {
            vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
                const i = ids.indexOf(this.id);
                if (i < 0) return box(0, 0);
                return box(i * 1000 - offset, i === ids.length - 1 ? lastHeight : 900);
            });
            const line = window.innerHeight * 0.4;

            place(-2 * line); // the top of the page: no chapter has reached the line yet
            const wrapper = render();
            expect(await scroll(wrapper)).toEqual([]);

            place(0); // the first chapter at the top
            expect(await scroll(wrapper)).toEqual([`#${ids[0]}`]);

            place(2500); // the third chapter has passed the line, the fourth has not
            expect(await scroll(wrapper)).toEqual([`#${ids[2]}`]);

            // The foot of the page: a short last chapter wholly on screen, its top still below the line.
            const lastIndex = ids.length - 1;
            place(lastIndex * 1000 - (window.innerHeight - 200), 150);
            expect(await scroll(wrapper)).toEqual([`#${ids[lastIndex]}`]);

            wrapper.unmount();
            vi.restoreAllMocks();
        });

        it('draws the current chapter apart, never by colour alone', async () => {
            vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
                const i = ids.indexOf(this.id);
                return i < 0 ? box(0, 0) : box(i * 1000 - offset, 900);
            });
            place(0);
            const wrapper = render();
            await scroll(wrapper);

            const current = wrapper.findAll('nav#manual-contents a.manual__contents-link--current');
            expect(current.map((a) => a.attributes('aria-current'))).toEqual(['location']);
            wrapper.unmount();
            vi.restoreAllMocks();
        });
    });
});
