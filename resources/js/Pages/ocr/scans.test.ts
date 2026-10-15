import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * M129 — the scans page. The client checks are a courtesy (the server re-checks), so a refusal here must stop
 * the upload before any request, and an acceptance must send it and move on to the review.
 */

const mocks = vi.hoisted(() => ({ visit: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: mocks.visit, get: mocks.get },
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
        search: { reference: null },
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
    mocks.get.mockReset();
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

describe('ocr/Scans — a paper copy from the public link (M165, `D106`)', () => {
    const row = (overrides: Record<string, unknown>): Record<string, unknown> => ({
        id: 'a',
        status: 'read',
        status_label: 'Ready to review',
        pages: 1,
        uploaded_by: null,
        from_public_link: false,
        reference: null,
        created_at: '2026-10-12T09:00:00Z',
        review_url: '/r/a',
        submission_url: null,
        error_message: null,
        ...overrides,
    });

    it("marks a guest's scan as from the public link with its reference, and a staff scan with neither", () => {
        const wrapper = mount(Scans, {
            props: props({
                scans: [
                    row({ id: 'g', from_public_link: true, reference: '7K4M-2QXB' }),
                    row({ id: 's', uploaded_by: 'Ana' }),
                ],
            }) as never,
        });
        const [guest, staff] = wrapper.findAll('.scans__row');

        expect(guest.text()).toContain('From the public link');
        expect(guest.text()).toContain('Reference 7K4M-2QXB');
        expect(staff.text()).not.toContain('From the public link');
        expect(staff.text()).not.toContain('Reference');
        expect(staff.text()).toContain('Ana');
    });

    it('asks the server for one reference on Enter, and for the newest scans when the box is emptied', async () => {
        const wrapper = mount(Scans, { props: props() as never });
        const box = wrapper.get('input[type="search"]');

        await box.setValue('7k4m 2qxb');
        await box.trigger('keyup', { key: 'Enter' });
        expect(mocks.get).toHaveBeenLastCalledWith('/forms/form-1/ocr/scans', { reference: '7k4m 2qxb' }, expect.objectContaining({ preserveState: true }));

        await box.setValue('');
        await box.trigger('keyup', { key: 'Enter' });
        expect(mocks.get).toHaveBeenLastCalledWith('/forms/form-1/ocr/scans', {}, expect.anything());
    });

    it('says no scan has a searched reference rather than that there are no scans, and offers the whole list back', async () => {
        const wrapper = mount(Scans, { props: props({ search: { reference: 'NOPE' } }) as never });

        expect(wrapper.text()).toContain('No scan has that reference');
        expect(wrapper.text()).not.toContain('No scans yet');

        await wrapper.findAll('button').find((button) => button.text() === 'Show all scans')!.trigger('click');
        expect(mocks.get).toHaveBeenLastCalledWith('/forms/form-1/ocr/scans', {}, expect.anything());
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

/**
 * M173 (`R-8ee7d2dc`, `D120`, `D121`) — the page fills the width: the upload form keeps a measure as a form, the recent
 * scans become a grid. Pinned as source text because happy-dom lays out nothing; the boxes are measured at 1920 in
 * `tests/e2e/list-layout.spec.ts`.
 */
describe('ocr/Scans — the page fills the width', () => {
    const source = readFileSync(join(process.cwd(), 'resources/js/Pages/ocr/Scans.vue'), 'utf8');
    const style = source.slice(source.indexOf('<style'));
    const rule = (selector: string): string => {
        const start = style.indexOf(`\n${selector} {`);
        expect(start, selector).toBeGreaterThan(-1);

        return style.slice(start, style.indexOf('}', start));
    };

    it('drops the 720px cap and makes the page the container for its own split', () => {
        expect(style).not.toMatch(/^\s+max-width:\s*720px/m);
        // The page itself is the container, and an inner wrapper takes the split — an element cannot query itself.
        expect(rule('.scans')).toMatch(/container-type:\s*inline-size/);
        expect(source).toContain('class="scans__layout"');
        expect(style).toMatch(/^@container \(min-width: 75rem\) \{\s*\.scans__layout \{/m);
    });

    it('lays the recent scans in a grid of rows rather than one full-width column', () => {
        expect(rule('.scans__list')).toMatch(/display:\s*grid/);
        expect(rule('.scans__list')).toMatch(/repeat\(auto-fill,\s*minmax\(min\(100%,\s*28rem\),\s*1fr\)\)/);
    });

    it('sets its error text in the on-surface danger pair', () => {
        // The other danger token resolves to danger-300 in dark and measures 4.22:1 on the surface.
        expect(style).not.toContain('--mds-color-danger-text');
        expect(rule('.scans__error')).toContain('--mds-color-status-danger-fg');
        expect(rule('.scans__row-error')).toContain('--mds-color-status-danger-fg');
    });
});
