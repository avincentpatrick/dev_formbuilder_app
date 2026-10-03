/**
 * The note's Content tab (M129 — `R-6dedc3a9`'s editor half, `R-f0c5b682`'s images).
 *
 * Mounted CONTROLLED, the way `ConfigPanel` holds it: every emitted list is fed straight back in as the next
 * `blocks`, so a case sees what an author sees after a round trip rather than one emit in isolation. That is the
 * only way the per-block text cache can be tested at all — its whole job is what survives the feedback.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ContentBlocksEditor from './ContentBlocksEditor.vue';
import type { ContentBlock } from './content-blocks';

const FORM_ID = '0192e2e0-0000-7000-8000-00000000f001';
const IMAGE_ID = '0192e2e0-0000-7000-8000-00000000c0de';

function mountControlled(blocks: ContentBlock[] = []): VueWrapper {
    const wrapper: VueWrapper = mount(ContentBlocksEditor, {
        props: {
            blocks,
            formId: FORM_ID,
            'onUpdate:blocks': (next: ContentBlock[]) => wrapper.setProps({ blocks: next }),
        },
        attachTo: document.body,
    });
    return wrapper;
}

function blocksOf(wrapper: VueWrapper): ContentBlock[] {
    return wrapper.props('blocks') as ContentBlock[];
}

function button(wrapper: VueWrapper, name: string) {
    const found = wrapper.findAll('button').find((b) => b.text() === name || b.attributes('aria-label') === name);
    if (found === undefined) throw new Error(`no button named «${name}»`);
    return found;
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('what the tab says before anything is composed', () => {
    it('says that respondents do not see the content yet, and that it is shown in one language', () => {
        const wrapper = mountControlled();

        expect(wrapper.find('[data-content-notice="not-shown-yet"]').text()).toBe(
            "Respondents do not see this content yet — until they do, they see the note's label.",
        );
        expect(wrapper.text()).toContain("Shown in the form's default language only.");
        expect(wrapper.find('[data-content-empty]').exists()).toBe(true);
    });

    it('renders no author text as markup, and adds no tab to the one tablist the panel may hold', () => {
        const source = readFileSync(join(process.cwd(), 'resources/js/components/builder/ContentBlocksEditor.vue'), 'utf-8');
        const wrapper = mountControlled([{ type: 'paragraph', spans: [{ text: '<img src=x onerror=alert(1)>' }] }]);

        // The directive, not the word: the component's own docblock says it has none.
        expect(source).not.toMatch(/v-html\s*=/);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('[role="tab"], [role="tablist"], [role="tabpanel"]').exists()).toBe(false);
    });
});

describe('composing', () => {
    it('adds each kind of block in its empty shape, at the end', async () => {
        const wrapper = mountControlled();

        for (const name of ['Heading', 'Paragraph', 'Callout', 'Divider']) {
            await button(wrapper, name).trigger('click');
        }

        expect(blocksOf(wrapper)).toEqual([
            { type: 'heading', level: 1, text: null },
            { type: 'paragraph', spans: [] },
            { type: 'callout', tone: 'info', spans: [] },
            { type: 'divider' },
        ]);
    });

    it('saves what is typed as spans, and leaves the typed text in the box', async () => {
        const wrapper = mountControlled([{ type: 'paragraph', spans: [] }]);
        const textarea = wrapper.find('textarea');

        await textarea.setValue('Read **this** first, 5 * 3');

        expect(blocksOf(wrapper)).toEqual([
            { type: 'paragraph', spans: [{ text: 'Read ' }, { text: 'this', bold: true }, { text: ' first, 5 * 3' }] },
        ]);
        expect((textarea.element as HTMLTextAreaElement).value).toBe('Read **this** first, 5 * 3');
    });

    it('keeps a link the server would refuse as plain text, and says why on the box', async () => {
        const wrapper = mountControlled([{ type: 'paragraph', spans: [] }]);
        const textarea = wrapper.find('textarea');

        await textarea.setValue('[click here](javascript:alert) or [ours](https://example.org)');

        const block = blocksOf(wrapper)[0] as Extract<ContentBlock, { type: 'paragraph' }>;
        expect(block.spans).toEqual([{ text: 'click here or ' }, { text: 'ours', link: 'https://example.org' }]);
        expect(Object.keys(block.spans[0])).toEqual(['text']);
        expect(wrapper.text()).toContain('A link must start with https://, http://, mailto: or tel:');
        expect(textarea.attributes('aria-invalid')).toBe('true');

        await textarea.setValue('[ours](https://example.org)');
        expect(wrapper.text()).not.toContain('A link must start with');
    });

    it('wraps the selected words in bold, and selects them again', async () => {
        const wrapper = mountControlled([{ type: 'paragraph', spans: [{ text: 'make this bold' }] }]);
        const textarea = wrapper.find('textarea').element as HTMLTextAreaElement;
        textarea.setSelectionRange(5, 9);

        await button(wrapper, 'Bold').trigger('click');
        await flushPromises();

        expect(textarea.value).toBe('make **this** bold');
        expect(blocksOf(wrapper)).toEqual([
            { type: 'paragraph', spans: [{ text: 'make ' }, { text: 'this', bold: true }, { text: ' bold' }] },
        ]);
        expect([textarea.selectionStart, textarea.selectionEnd]).toEqual([7, 11]);
    });

    it('re-reads every box from the blocks after a move, so no text follows the wrong block', async () => {
        const wrapper = mountControlled([
            { type: 'paragraph', spans: [{ text: 'first' }] },
            { type: 'callout', tone: 'warning', spans: [{ text: 'second', italic: true }] },
        ]);

        await button(wrapper, 'Move block 1 down').trigger('click');

        expect(blocksOf(wrapper).map((b) => b.type)).toEqual(['callout', 'paragraph']);
        expect(wrapper.findAll('textarea').map((t) => (t.element as HTMLTextAreaElement).value)).toEqual(['__second__', 'first']);
        expect(button(wrapper, 'Move block 1 up').attributes('disabled')).toBeDefined();
        expect(button(wrapper, 'Move block 2 down').attributes('disabled')).toBeDefined();
    });

    it('removes a block', async () => {
        const wrapper = mountControlled([{ type: 'divider' }, { type: 'heading', level: 2, text: 'Keep me' }]);

        await button(wrapper, 'Remove block 1').trigger('click');

        expect(blocksOf(wrapper)).toEqual([{ type: 'heading', level: 2, text: 'Keep me' }]);
    });

    it('saves a cleared heading or description as null rather than an empty string', async () => {
        const wrapper = mountControlled([
            { type: 'heading', level: 1, text: 'Hi' },
            { type: 'image', attachment_id: IMAGE_ID, alt: 'A map' },
        ]);
        const [heading, alt] = wrapper.findAll('input[type="text"]');

        await heading.setValue('');
        await alt.setValue('');

        expect(blocksOf(wrapper)).toEqual([
            { type: 'heading', level: 1, text: null },
            { type: 'image', attachment_id: IMAGE_ID, alt: null },
        ]);
    });

    it('offers no more blocks once a note holds fifty', () => {
        const wrapper = mountControlled(Array.from({ length: 50 }, (): ContentBlock => ({ type: 'divider' })));

        expect(button(wrapper, 'Heading').attributes('disabled')).toBeDefined();
        expect(wrapper.find('input[type="file"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('A note can hold at most 50 blocks.');
    });
});

describe('images', () => {
    function choose(wrapper: VueWrapper, file: File) {
        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
        return input.trigger('change');
    }

    it('uploads a chosen image against the form and adds it as a block, saying so while it is checked', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(new Response(JSON.stringify({ data: { id: IMAGE_ID, url: `/attachments/${IMAGE_ID}`, servable: false } }), { status: 201 })),
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountControlled();

        await choose(wrapper, new File([new Uint8Array(4)], 'map.png', { type: 'image/png' }));
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe(`/forms/${FORM_ID}/content-images`);
        expect(init.method).toBe('POST');
        expect((init.body as FormData).get('file')).toBeInstanceOf(File);
        expect(blocksOf(wrapper)).toEqual([{ type: 'image', attachment_id: IMAGE_ID, alt: null }]);
        expect(wrapper.find('[role="status"]').text()).toContain('Checking this file');
        expect(wrapper.find('img').exists()).toBe(false);
    });

    it("shows the server's reason when an upload is refused, and adds nothing", async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    new Response(JSON.stringify({ message: 'Invalid.', errors: { file: ['An image must be a PNG, JPEG or WebP file. SVG and GIF are not accepted.'] } }), {
                        status: 422,
                    }),
                ),
            ),
        );
        const wrapper = mountControlled();

        await choose(wrapper, new File(['<svg/>'], 'logo.png', { type: 'image/png' }));
        await flushPromises();

        expect(blocksOf(wrapper)).toEqual([]);
        expect(wrapper.text()).toContain('An image must be a PNG, JPEG or WebP file. SVG and GIF are not accepted.');
        expect(wrapper.find('input[type="file"]').attributes('aria-invalid')).toBe('true');
    });

    it('asks again for an image that is still being checked, instead of showing it broken', async () => {
        vi.useFakeTimers();
        const wrapper = mountControlled([{ type: 'image', attachment_id: IMAGE_ID, alt: 'A map' }]);
        expect(wrapper.find('img').attributes('src')).toBe(`/attachments/${IMAGE_ID}`);

        await wrapper.find('img').trigger('error');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('Checking this file');

        vi.advanceTimersByTime(3000);
        await flushPromises();
        expect(wrapper.find('img').attributes('src')).toBe(`/attachments/${IMAGE_ID}?attempt=1`);
        expect(wrapper.find('img').attributes('alt')).toBe('A map');
    });
});
