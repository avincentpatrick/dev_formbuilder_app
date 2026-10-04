/**
 * A form's reference files on the guest page (M132, `R-bf49e4c1`, `D84`): the URL and cache key the page and the
 * service worker share, and the list the respondent opens them from.
 *
 * ⚠️ THE LIST FETCHES; IT NEVER NAVIGATES. The worker's scope is `/f/`, so a file opened by navigating to its `/api`
 * URL is one the worker never sees and could never serve offline — every open here is asserted as a `fetch` (a PDF)
 * or an `<img>` (an image), which the worker does see.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import ReferenceFileList from '../components/ReferenceFileList.vue';
import { AnnouncerKey } from '../composables/context';
import { createAnnouncer } from '../composables/useAnnouncer';
import { referenceFileCacheKey, referenceFileDownloadName, referenceFileUrl } from '../lib/reference-files';
import type { GuestReferenceFile } from '../lib/types';

const PDF: GuestReferenceFile = { id: 'att-1', label: 'Visit guide', mime_type: 'application/pdf', size_bytes: 120_000 };
const MAP: GuestReferenceFile = { id: 'att-2', label: 'Map', mime_type: 'image/png', size_bytes: 40_000 };

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('where a reference file is read from', () => {
    it('reads through the token-scoped route, off the schema prefix', () => {
        expect(referenceFileUrl('tok/en', 'att-1')).toBe('/api/v1/public/reference-files/tok%2Fen/att-1');
        expect(referenceFileUrl('t', 'a').startsWith('/api/v1/public/f/')).toBe(false);
    });

    it('caches under a key without the share token, and leaves any other URL alone', () => {
        expect(referenceFileCacheKey('https://acme.test/api/v1/public/reference-files/token-a/att-1')).toBe(
            'https://acme.test/api/v1/public/reference-files/att-1',
        );
        expect(referenceFileCacheKey('https://acme.test/api/v1/public/reference-files/token-b/att-1')).toBe(
            referenceFileCacheKey('https://acme.test/api/v1/public/reference-files/token-a/att-1'),
        );
        for (const other of [
            'https://acme.test/api/v1/public/content-images/token-a/att-1',
            'https://acme.test/api/v1/public/reference-files/only-one-segment',
        ]) {
            expect(referenceFileCacheKey(other)).toBe(other);
        }
    });

    it('saves a PDF under the author’s name, with the extension it needs', () => {
        expect(referenceFileDownloadName('Visit guide', 'application/pdf')).toBe('Visit guide.pdf');
        expect(referenceFileDownloadName('Guide.PDF', 'application/pdf')).toBe('Guide.PDF');
        expect(referenceFileDownloadName('  ', 'application/pdf')).toBe('Reference file.pdf');
        expect(referenceFileDownloadName('Map', 'image/png')).toBe('Map');
    });
});

describe('the list a respondent opens them from', () => {
    function mountList(files: GuestReferenceFile[]): { wrapper: VueWrapper; announced: () => string } {
        const announcer = createAnnouncer();
        const wrapper = mount(ReferenceFileList, {
            props: { files, shareToken: () => 'token-now' },
            global: { provide: { [AnnouncerKey as symbol]: announcer }, stubs: { teleport: true } },
            attachTo: document.body,
        });
        return { wrapper, announced: () => announcer.message.value };
    }

    it('names each file, its kind and size, and what opening it does', () => {
        const { wrapper } = mountList([PDF, MAP]);

        const buttons = wrapper.findAll('.reference-files__open');
        expect(buttons.map((b) => b.find('.reference-files__name').text())).toEqual(['Visit guide', 'Map']);
        expect(buttons[0].text()).toContain('PDF, 117 KB · saves a copy');
        expect(buttons[1].text()).toContain('Image, 39 KB · opens here');
        expect(wrapper.find('ul').attributes('aria-labelledby')).toBe(wrapper.find('.reference-files__label').attributes('id'));
        wrapper.unmount();
    });

    it('fetches a PDF with the current token and saves it under the author’s name', async () => {
        const fetchMock = vi.fn(() => Promise.resolve(new Response(new Blob(['%PDF']), { status: 200 })));
        vi.stubGlobal('fetch', fetchMock);
        URL.createObjectURL = vi.fn(() => 'blob:saved');
        URL.revokeObjectURL = vi.fn();
        const clicked: string[] = [];
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clicked.push(`${this.getAttribute('href')} as ${this.download}`);
        });
        const { wrapper, announced } = mountList([PDF]);

        await wrapper.find('.reference-files__open').trigger('click');
        await flushPromises();

        expect((fetchMock.mock.calls[0] as unknown as [string])[0]).toBe('/api/v1/public/reference-files/token-now/att-1');
        expect(clicked).toEqual(['blob:saved as Visit guide.pdf']);
        expect(announced()).toBe('Saved Visit guide.pdf.');
        wrapper.unmount();
    });

    it('says to open it once online when a PDF that was never opened is asked for offline', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))));
        vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
        const { wrapper, announced } = mountList([PDF]);

        await wrapper.find('.reference-files__open').trigger('click');
        await flushPromises();

        expect(announced()).toBe('Open this file once while you are online to keep it on this device.');
        expect(wrapper.find('.reference-files__message').text()).toBe(announced());
        wrapper.unmount();
    });

    it('opens an image in a dialog through an <img> the worker can serve, and never navigates', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const { wrapper } = mountList([MAP]);

        await wrapper.find('.reference-files__open').trigger('click');

        const image = wrapper.find('img.reference-files__image');
        expect(image.attributes('src')).toBe('/api/v1/public/reference-files/token-now/att-2');
        expect(image.attributes('alt')).toBe('Map');
        expect(fetchMock).not.toHaveBeenCalled();

        await image.trigger('error');
        expect(wrapper.find('img.reference-files__image').exists()).toBe(false);
        expect(wrapper.text()).toContain('This file is not available right now.');
        wrapper.unmount();
    });
});
