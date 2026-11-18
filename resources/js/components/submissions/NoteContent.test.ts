import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import NoteContent from './NoteContent.vue';
import { computed } from 'vue';
import {
    ContentGlossaryKey,
    ContentHeadingBaseKey,
    ContentImageRetryKey,
    ContentImageUrlKey,
    renderableBlocks,
    withAttempt,
    type ContentBlock,
} from './note-content';

/**
 * M130 (`R-c9f50df2`) — a note's content blocks as a respondent sees them, with no raw-HTML sink anywhere.
 */
function render(blocks: unknown, provide: Record<symbol, unknown> = {}) {
    return mount(NoteContent, { props: { blocks: renderableBlocks(blocks) }, global: { provide } });
}

describe('NoteContent', () => {
    it('renders every block type in order', () => {
        const wrapper = render([
            { type: 'heading', level: 1, text: 'Before you begin' },
            { type: 'paragraph', spans: [{ text: 'Plain, ' }, { text: 'bold', bold: true }, { text: ', ' }, { text: 'italic', italic: true }, { text: ' and ' }, { text: 'code', code: true }] },
            { type: 'callout', tone: 'warning', spans: [{ text: 'Fasting is required.' }] },
            { type: 'divider' },
            { type: 'image', attachment_id: 'att-1', alt: 'A map of the entrance' },
        ]);

        expect(wrapper.find('h3').text()).toBe('Before you begin');
        expect(wrapper.find('strong').text()).toBe('bold');
        expect(wrapper.find('em').text()).toBe('italic');
        expect(wrapper.find('code').text()).toBe('code');
        const callout = wrapper.find('[role="note"]');
        expect(callout.text()).toContain('Fasting is required.');
        expect(callout.classes()).toContain('mds-callout--warning');
        expect(wrapper.find('hr').exists()).toBe(true);
        const image = wrapper.find('img');
        expect(image.attributes('src')).toBe('/attachments/att-1');
        expect(image.attributes('alt')).toBe('A map of the entrance');
        expect(image.attributes('loading')).toBe('lazy');
    });

    it('places headings under the heading the page gives it', () => {
        const wrapper = render([{ type: 'heading', level: 2, text: 'Details' }], { [ContentHeadingBaseKey]: 3 });

        // The builder preview titles its sections with an h3, so a minor content heading is an h5 there.
        expect(wrapper.find('h5').text()).toBe('Details');
    });

    it('renders markup-shaped text as text, adding no element', () => {
        const wrapper = render([{ type: 'paragraph', spans: [{ text: '<img src=x onerror=alert(1)><script>alert(1)</script>' }] }]);

        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.findAll('img')).toHaveLength(0);
        expect(wrapper.text()).toContain('<script>alert(1)</script>');
        expect(wrapper.html()).toContain('&lt;script&gt;');
    });

    it('links a safe address in a new tab, and renders an unsafe one as plain text', () => {
        const wrapper = render([
            { type: 'paragraph', spans: [{ text: 'our help page', link: 'https://example.org/help' }, { text: ' or ' }, { text: 'this', link: 'javascript:alert(1)' }] },
        ]);
        const links = wrapper.findAll('a');

        expect(links).toHaveLength(1);
        expect(links[0].attributes('href')).toBe('https://example.org/help');
        expect(links[0].attributes('target')).toBe('_blank');
        expect(links[0].attributes('rel')).toBe('noopener noreferrer');
        expect(links[0].text()).toContain('(opens in a new tab)');
        expect(wrapper.text()).toContain('this');
    });

    it('draws a lenient draft without inventing anything', () => {
        // What the builder preview can hold mid-edit: a heading with no text, an emptied span, an image with no
        // description. Each draws as nothing rather than as "null".
        const wrapper = render([
            { type: 'heading', level: 1, text: null },
            { type: 'paragraph', spans: [{ text: null }] },
            { type: 'image', attachment_id: 'att-2', alt: null },
        ]);

        expect(wrapper.text()).not.toContain('null');
        expect(wrapper.find('img').attributes('alt')).toBe('');
        // An empty heading element is an axe failure on a page the E2E gate scans, so a textless heading draws nothing.
        expect(wrapper.findAll('h1, h2, h3, h4, h5, h6')).toHaveLength(0);
    });

    it('replaces an image the server will not serve with its description', async () => {
        const wrapper = render([{ type: 'image', attachment_id: 'att-3', alt: 'The clinic entrance' }]);

        await wrapper.find('img').trigger('error');

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('The clinic entrance');
    });

    it('asks again for an image that is still being checked, where the page asks it to (M137, the builder preview)', async () => {
        vi.useFakeTimers();
        try {
            const wrapper = render([{ type: 'image', attachment_id: 'att-5', alt: null }], {
                [ContentImageRetryKey]: { delayMs: 3000, maxAttempts: 2 },
            });

            // The first load fails (409 while the virus check runs): the image waits, and says so.
            await wrapper.find('img').trigger('error');
            expect(wrapper.find('img').exists()).toBe(false);
            expect(wrapper.find('[data-image-checking]').text()).toContain('still being checked');

            // After the delay it is asked for again under a fresh address — the browser keeps a failure per URL.
            await vi.advanceTimersByTimeAsync(3000);
            expect(wrapper.find('img').attributes('src')).toBe('/attachments/att-5?attempt=1');

            // Once it loads, nothing else happens; had it failed for good, the tries run out and it says what to do.
            await wrapper.find('img').trigger('error');
            await vi.advanceTimersByTimeAsync(3000);
            expect(wrapper.find('img').attributes('src')).toBe('/attachments/att-5?attempt=2');
            await wrapper.find('img').trigger('error');
            expect(wrapper.find('img').exists()).toBe(false);
            expect(wrapper.find('[data-image-checking]').exists()).toBe(false);
            expect(wrapper.find('[data-image-gave-up]').text()).toContain('Reload the page');
        } finally {
            vi.useRealTimers();
        }
    });

    it('never asks again where the page does not ask it to — the guest and encode pages', async () => {
        vi.useFakeTimers();
        try {
            const wrapper = render([{ type: 'image', attachment_id: 'att-6', alt: null }]);

            await wrapper.find('img').trigger('error');
            await vi.advanceTimersByTimeAsync(60_000);

            expect(wrapper.find('img').exists()).toBe(false);
            expect(wrapper.find('[data-image-checking]').exists()).toBe(false);
            expect(wrapper.find('[data-image-gave-up]').exists()).toBe(false);
        } finally {
            vi.useRealTimers();
        }
    });

    it('adds the attempt to an address that already carries a query', () => {
        expect(withAttempt('/api/v1/public/content-images/t/att-7', 0)).toBe('/api/v1/public/content-images/t/att-7');
        expect(withAttempt('/attachments/att-7?size=small', 3)).toBe('/attachments/att-7?size=small&attempt=3');
    });

    it('reads images from the address the page provides', () => {
        const wrapper = render([{ type: 'image', attachment_id: 'att-4', alt: 'x' }], {
            [ContentImageUrlKey]: (id: string) => `/api/v1/public/content-images/token-1/${id}`,
        });

        expect(wrapper.find('img').attributes('src')).toBe('/api/v1/public/content-images/token-1/att-4');
    });

    it('draws nothing for an empty list', () => {
        const blocks: ContentBlock[] = [];

        expect(mount(NoteContent, { props: { blocks } }).find('[data-note-content]').text()).toBe('');
    });

    // ── M245: lists, details blocks and glossary words ─────────────────────────────────────────────────────

    it('draws a bulleted list and a numbered one, each item formatted', () => {
        const wrapper = render([
            { type: 'list', style: 'bulleted', items: [{ spans: [{ text: 'Bring your ' }, { text: 'card', bold: true }] }, { spans: [{ text: 'Wear a mask' }] }] },
            { type: 'list', style: 'numbered', items: [{ spans: [{ text: 'First' }] }] },
        ]);

        expect(wrapper.findAll('ul > li').map((li) => li.text())).toEqual(['Bring your card', 'Wear a mask']);
        expect(wrapper.find('ul strong').text()).toBe('card');
        expect(wrapper.findAll('ol > li').map((li) => li.text())).toEqual(['First']);
    });

    it('draws a details block collapsed, with its own blocks inside, and opens it on a click', async () => {
        const wrapper = mount(NoteContent, {
            props: {
                blocks: renderableBlocks([
                    { type: 'details', summary: 'More about this indicator', blocks: [{ type: 'paragraph', spans: [{ text: 'The formula.' }] }] },
                ]),
            },
            attachTo: document.body,
        });
        const toggle = wrapper.get('[data-block-details] button');

        expect(toggle.text()).toBe('More about this indicator');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.findAll('[data-note-content]')).toHaveLength(1);

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.get(`#${toggle.attributes('aria-controls')}`).text()).toContain('The formula.');
        wrapper.unmount();
    });

    it('names a details block with no summary yet, so its button is never empty', () => {
        const wrapper = render([{ type: 'details', summary: '  ', blocks: [] }]);

        expect(wrapper.get('[data-block-details] button').text()).toBe('Details');
    });

    it('opens a marked word’s meaning in a dialog, and draws a word the glossary lacks as text', async () => {
        const glossary = computed(() => [{ term: 'TWG', definition: 'The technical working group.' }]);
        const wrapper = mount(NoteContent, {
            props: {
                blocks: renderableBlocks([
                    { type: 'paragraph', spans: [{ text: 'Ask the ' }, { text: 'twg', term: 'twg', bold: true }, { text: ' or ' }, { text: 'BHW', term: 'BHW' }, { text: '.' }] },
                ]),
            },
            global: { provide: { [ContentGlossaryKey as symbol]: glossary } },
            attachTo: document.body,
        });

        const term = wrapper.get('[data-glossary-term]');
        expect(term.element.tagName).toBe('BUTTON');
        expect(term.attributes('type')).toBe('button');
        expect(term.attributes('aria-haspopup')).toBe('dialog');
        expect(term.text()).toBe('twg (glossary)');
        expect(term.find('strong').text()).toBe('twg');
        expect(wrapper.findAll('[data-glossary-term]')).toHaveLength(1);
        expect(wrapper.text()).toContain('BHW');

        await term.trigger('click');
        const dialog = document.body.querySelector('[role="dialog"]');
        expect(dialog?.textContent).toContain('TWG');
        expect(dialog?.textContent).toContain('The technical working group.');
        wrapper.unmount();
    });

    it('draws every marked word as text where nothing provides a glossary', () => {
        const wrapper = render([{ type: 'paragraph', spans: [{ text: 'TWG', term: 'TWG' }] }]);

        expect(wrapper.find('[data-glossary-term]').exists()).toBe(false);
        expect(wrapper.text()).toBe('TWG');
    });
});
