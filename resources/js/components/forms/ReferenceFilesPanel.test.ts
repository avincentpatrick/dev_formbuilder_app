/**
 * The Reference files section (M132, `R-bf49e4c1`), through the rendered DOM, with `fetch` stubbed at the boundary.
 *
 * What it has to get right: the list is the SERVER's answer, never a local guess; a file still being checked is not
 * offered as a link and is read again until it is ready, a bounded number of times; and every refusal shows the
 * server's own sentence where the author is looking.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import ReferenceFilesPanel from './ReferenceFilesPanel.vue';
import type { ReferenceFileRow } from '@/components/forms/types';

const FORM_ID = 'form-1';

function row(over: Partial<ReferenceFileRow> = {}): ReferenceFileRow {
    return {
        id: 'att-1',
        label: 'Visit guide.pdf',
        file_name: 'Visit guide.pdf',
        mime_type: 'application/pdf',
        size_bytes: 1_258_291,
        scan: 'ready',
        url: '/attachments/att-1',
        ...over,
    };
}

function mountPanel(files: ReferenceFileRow[] = []): VueWrapper {
    return mount(ReferenceFilesPanel, { props: { open: true, formId: FORM_ID, files } });
}

function respond(body: unknown, status = 200): Promise<Response> {
    return Promise.resolve(new Response(body === null ? null : JSON.stringify(body), { status }));
}

function choose(wrapper: VueWrapper, file: File) {
    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    return input.trigger('change');
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('the list', () => {
    it('says first that respondents do not see these files (M138, D92), and offers the types the server accepts', () => {
        const wrapper = mountPanel();

        expect(wrapper.find('[data-reference-notice="staff-only"]').text()).toBe('Respondents do not see these files. Using a file inside the form itself, as KoboToolbox does, comes later.');
        expect(wrapper.text()).toContain('No reference files yet.');
        expect(wrapper.find('input[type="file"]').attributes('accept')).toBe('application/pdf,image/png,image/jpeg,image/webp');
    });

    it('links a ready file for staff, and names its kind and size', () => {
        const wrapper = mountPanel([row()]);

        const link = wrapper.find('a.reference-files__name');
        expect(link.text()).toBe('Visit guide.pdf');
        expect(link.attributes('href')).toBe('/attachments/att-1');
        expect(wrapper.find('[data-reference-file="att-1"]').text()).toContain('PDF, 1.2 MB');
    });

    it('does not link a file still being checked or refused, and says which', () => {
        const wrapper = mountPanel([row({ id: 'a', scan: 'checking' }), row({ id: 'b', label: 'Bad.pdf', scan: 'refused' })]);

        expect(wrapper.find('a.reference-files__name').exists()).toBe(false);
        expect(wrapper.find('[data-reference-file="a"]').text()).toContain('Checking this file for viruses');
        expect(wrapper.find('[data-reference-file="b"]').text()).toContain('The virus check refused this file.');
    });
});

describe('attaching', () => {
    it('uploads a chosen file to the form and lists what the server answered', async () => {
        const fetchMock = vi.fn(() => respond({ data: row({ id: 'att-2', label: 'map.png', mime_type: 'image/png', size_bytes: 40_000 }) }, 201));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPanel([row()]);

        await choose(wrapper, new File([new Uint8Array(4)], 'map.png', { type: 'image/png' }));
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe(`/forms/${FORM_ID}/reference-files`);
        expect(init.method).toBe('POST');
        expect((init.body as FormData).get('file')).toBeInstanceOf(File);
        expect(wrapper.findAll('[data-reference-file]').map((item) => item.attributes('data-reference-file'))).toEqual(['att-1', 'att-2']);
        expect(wrapper.text()).toContain('Added map.png.');
    });

    it("shows the server's reason on the field when an upload is refused, and lists nothing new", async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond({ message: 'Invalid.', errors: { file: ['A form can show at most 10 reference files. Remove one to add another.'] } }, 422)));
        const wrapper = mountPanel([row()]);

        await choose(wrapper, new File(['%PDF'], 'more.pdf', { type: 'application/pdf' }));
        await flushPromises();

        expect(wrapper.findAll('[data-reference-file]')).toHaveLength(1);
        expect(wrapper.text()).toContain('A form can show at most 10 reference files. Remove one to add another.');
        expect(wrapper.find('input[type="file"]').attributes('aria-invalid')).toBe('true');
    });

    it('reads the list again while a file is being checked, and stops once it is ready', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(() => respond({ data: [row({ scan: 'ready' })] }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPanel([row({ scan: 'checking' })]);

        expect(fetchMock).not.toHaveBeenCalled();
        vi.advanceTimersByTime(3000);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect((fetchMock.mock.calls[0] as unknown as [string])[0]).toBe(`/forms/${FORM_ID}/reference-files`);
        expect(wrapper.find('a.reference-files__name').exists()).toBe(true);

        vi.advanceTimersByTime(30_000);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('gives up after the last try and says what to do instead', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(() => respond({ data: [row({ scan: 'checking' })] }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPanel([row({ scan: 'checking' })]);

        for (let i = 0; i < 12; i++) {
            vi.advanceTimersByTime(3000);
            await flushPromises();
        }

        expect(fetchMock).toHaveBeenCalledTimes(10);
        expect(wrapper.text()).toContain('Still being checked for viruses. Reopen these settings in a minute.');
    });
});

describe('renaming and removing', () => {
    it('renames a file with the name the server stored', async () => {
        const fetchMock = vi.fn(() => respond({ data: row({ label: 'Visit guide (English)' }) }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPanel([row()]);

        await wrapper.find('button[aria-label="Rename Visit guide.pdf"]').trigger('click');
        await wrapper.find('[data-reference-file="att-1"] input').setValue('Visit guide (English)');
        await wrapper.findAll('button').find((b) => b.text() === 'Save name')!.trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe(`/forms/${FORM_ID}/reference-files/att-1`);
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(init.body as string)).toEqual({ label: 'Visit guide (English)' });
        expect(wrapper.find('a.reference-files__name').text()).toBe('Visit guide (English)');
    });

    it('removes a file and says so', async () => {
        const fetchMock = vi.fn(() => respond(null, 204));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPanel([row()]);

        await wrapper.find('button[aria-label="Remove Visit guide.pdf"]').trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe(`/forms/${FORM_ID}/reference-files/att-1`);
        expect(init.method).toBe('DELETE');
        expect(wrapper.findAll('[data-reference-file]')).toHaveLength(0);
        expect(wrapper.text()).toContain('Removed Visit guide.pdf.');
        expect(wrapper.text()).not.toContain('Respondents keep');
    });
});
