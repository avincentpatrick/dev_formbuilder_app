import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * M129 — the scans page. The client checks are a courtesy (the server re-checks), so a refusal here must stop
 * the upload before any request, and an acceptance must send it and move on to the review.
 */

const mocks = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: mocks.visit },
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', props: ['title'], template: '<header><h1>{{ title }}</h1><slot name="breadcrumbs" /></header>' },
}));

const Scans = (await import('./Scans.vue')).default;

function props(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        form: { id: 'form-1', title: 'Clinic Visit' },
        accepts_scans: true,
        refusal: null,
        upload: {
            url: '/forms/form-1/ocr/scans',
            max_pages: 5,
            accepted_types: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
            max_bytes_per_file: 7_000_000,
            max_bytes_per_scan: 25_000_000,
        },
        scans: [],
        crumbs: [{ label: 'Forms', href: '/forms' }, { label: 'Scanned forms' }],
        ...overrides,
    };
}

function choose(wrapper: VueWrapper, files: File[]): Promise<void> {
    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: files, configurable: true });
    return input.trigger('change');
}

const fetchMock = vi.fn();

// M154 — each chosen photo gets a preview by object URL. Stubbed on the real `URL` (a stubbed global `URL` would
// take `new URL()` away from everything else), and restored after every case.
const createUrl = vi.fn((file: File) => `blob:${file.name}`);
const revokeUrl = vi.fn();
const realCreate = URL.createObjectURL;
const realRevoke = URL.revokeObjectURL;

beforeEach(() => {
    mocks.visit.mockReset();
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    createUrl.mockClear();
    revokeUrl.mockClear();
    URL.createObjectURL = createUrl as unknown as typeof URL.createObjectURL;
    URL.revokeObjectURL = revokeUrl;
});

afterEach(() => {
    vi.unstubAllGlobals();
    URL.createObjectURL = realCreate;
    URL.revokeObjectURL = realRevoke;
});

const png = (name: string): File => new File(['x'], name, { type: 'image/png' });

/** The names of the files the upload request carried, in the order it carried them. */
function sentNames(): string[] {
    const body = fetchMock.mock.calls[0][1].body as FormData;
    return body.getAll('pages[]').map((file) => (file as File).name);
}

describe('ocr/Scans', () => {
    it('refuses a PDF sent with photos before any request, and says why', async () => {
        const wrapper = mount(Scans, { props: props() as never });

        await choose(wrapper, [new File(['%PDF'], 'scan.pdf', { type: 'application/pdf' }), new File(['x'], '1.png', { type: 'image/png' })]);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[role="alert"]').text()).toContain('Upload a PDF scan on its own.');
        expect(wrapper.find('input[type="file"]').attributes('aria-invalid')).toBe('true');
    });

    it('sends the pages and opens the review of the new scan', async () => {
        fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: { id: 'scan-9' } }), { status: 202 }));
        const wrapper = mount(Scans, { props: props() as never });

        await choose(wrapper, [new File(['x'], '1.png', { type: 'image/png' })]);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(mocks.visit).toHaveBeenCalledWith('/forms/form-1/ocr/scans/scan-9/review');
    });

    it('shows the server\'s refusal and stays on the page', async () => {
        fetchMock.mockResolvedValue(new Response(JSON.stringify({ error: { message: 'A scan can have at most 5 pages.' } }), { status: 422 }));
        const wrapper = mount(Scans, { props: props() as never });

        await choose(wrapper, [new File(['x'], '1.png', { type: 'image/png' })]);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(mocks.visit).not.toHaveBeenCalled();
        expect(wrapper.find('[role="alert"]').text()).toBe('A scan can have at most 5 pages.');
    });

    it('says why uploading is off instead of offering an upload that would be refused', () => {
        const wrapper = mount(Scans, {
            props: props({ accepts_scans: false, refusal: 'This form does not accept scans. Turn on scanning in the form\'s settings first.' }) as never,
        });

        expect(wrapper.find('input[type="file"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('This form does not accept scans.');
    });

    it('lists scans with the server\'s status words and where each leads', () => {
        const wrapper = mount(Scans, {
            props: props({
                scans: [
                    { id: 'a', status: 'read', status_label: 'Ready to review', pages: 2, uploaded_by: 'Ana', created_at: '2026-10-03T09:00:00Z', review_url: '/r/a', submission_url: null, error_message: null },
                    { id: 'b', status: 'saved', status_label: 'Saved as a response', pages: 1, uploaded_by: null, created_at: '2026-10-03T08:00:00Z', review_url: null, submission_url: '/submissions/x', error_message: null },
                ],
            }) as never,
        });
        const rows = wrapper.findAll('.scans__row');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Ready to review');
        expect(rows[0].find('a').attributes('href')).toBe('/r/a');
        expect(rows[0].text()).toContain('Review');
        expect(rows[1].find('a').attributes('href')).toBe('/submissions/x');
        expect(rows[1].text()).toContain('View the response');
    });

    it('shows the empty state when there are no scans yet', () => {
        const wrapper = mount(Scans, { props: props() as never });

        expect(wrapper.text()).toContain('No scans yet');
        expect(wrapper.find('.scans__row').exists()).toBe(false);
        // M154: a saved scan stays in the list (as "Saved as a response"), so the copy must not promise otherwise.
        expect(wrapper.text()).not.toContain('until each is saved');
    });
});

describe('ocr/Scans — the chosen pages, before "Read this scan" (M154, `R-c84e4f12`)', () => {
    it('lists each chosen photo with its preview before anything is sent', async () => {
        const wrapper = mount(Scans, { props: props() as never });

        await choose(wrapper, [png('page-1.png'), png('page-2.png')]);

        const items = wrapper.findAll('.chosen__item');
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain('page-1.png');
        expect(items[1].text()).toContain('page-2.png');
        expect(items[0].find('img').attributes('src')).toBe('blob:page-1.png');
        expect(wrapper.text()).toContain('2 pages chosen');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('adds a second pick to the first instead of replacing it, and sends them all', async () => {
        fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: { id: 'scan-9' } }), { status: 202 }));
        const wrapper = mount(Scans, { props: props() as never });
        // The picker is emptied after each pick, or choosing the file just removed would fire no `change`. Read
        // through a setter spy: a file input's `value` cannot be set to anything else, so reading it back
        // proves nothing (the M154 mutant that dropped the reset survived exactly that).
        const cleared = vi.fn();
        Object.defineProperty(wrapper.find('input[type="file"]').element, 'value', { configurable: true, get: () => '', set: cleared });

        await choose(wrapper, [png('page-1.png')]);
        await choose(wrapper, [png('page-2.png')]);
        expect(wrapper.findAll('.chosen__item')).toHaveLength(2);
        expect(cleared).toHaveBeenCalledTimes(2);
        expect(cleared).toHaveBeenCalledWith('');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(sentNames()).toEqual(['page-1.png', 'page-2.png']);
    });

    it('leaves a removed photo out of what is sent, and frees its preview', async () => {
        fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: { id: 'scan-9' } }), { status: 202 }));
        const wrapper = mount(Scans, { props: props() as never });

        await choose(wrapper, [png('wrong.png'), png('right.png')]);
        await wrapper.get('button[aria-label="Remove wrong.png"]').trigger('click');

        expect(wrapper.findAll('.chosen__item')).toHaveLength(1);
        expect(revokeUrl).toHaveBeenCalledWith('blob:wrong.png');
        expect(wrapper.text()).toContain('wrong.png removed.');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(sentNames()).toEqual(['right.png']);
    });

    it('checks the files as soon as they are chosen, and lifts the refusal once the culprit is removed', async () => {
        const wrapper = mount(Scans, { props: props() as never });

        await choose(wrapper, [new File(['%PDF'], 'scan.pdf', { type: 'application/pdf' }), png('page-1.png')]);
        // Refused at the pick, not only at the send — and the list stays, so the wrong file can be taken out.
        expect(wrapper.find('[role="alert"]').text()).toContain('Upload a PDF scan on its own.');
        expect(wrapper.findAll('.chosen__item')).toHaveLength(2);

        await wrapper.get('button[aria-label="Remove scan.pdf"]').trigger('click');

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.find('input[type="file"]').attributes('aria-invalid')).toBeUndefined();
    });

    it('frees every preview when the page is left', async () => {
        const wrapper = mount(Scans, { props: props() as never });
        await choose(wrapper, [png('page-1.png'), png('page-2.png')]);

        wrapper.unmount();

        expect(revokeUrl.mock.calls.map((call) => call[0]).sort()).toEqual(['blob:page-1.png', 'blob:page-2.png']);
    });
});
