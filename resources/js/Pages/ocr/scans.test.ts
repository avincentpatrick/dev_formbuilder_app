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

beforeEach(() => {
    mocks.visit.mockReset();
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

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
    });
});
