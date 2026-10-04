import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import NoteContent from './NoteContent.vue';
import { ContentHeadingBaseKey, ContentImageUrlKey, renderableBlocks, type ContentBlock } from './note-content';

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
});
