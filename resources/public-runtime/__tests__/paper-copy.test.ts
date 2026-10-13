import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PaperCopyUpload from '../components/PaperCopyUpload.vue';
import PaperReading from '../components/PaperReading.vue';
import { ApiError } from '../lib/error-normalizer';
import type { ApiClient } from '../lib/api-client';
import type { NormalizedError } from '../lib/types';

/**
 * M165 (`R-7173c02c`, `D106`) — "Filled in a paper copy? Upload it instead" on the public form. Collapsed until asked
 * for; the chosen pages are checked as the scans page checks them; a send hands the files to the client and passes
 * the server's reference up; and every refusal is said in words a respondent can act on.
 */

const limits = {
    max_pages: 5,
    accepted_types: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    max_bytes_per_file: 7_000_000,
    max_bytes_per_scan: 25_000_000,
};

const uploadPaperCopy = vi.fn();
const client = { uploadPaperCopy } as unknown as ApiClient;

const realCreate = URL.createObjectURL;
const realRevoke = URL.revokeObjectURL;

beforeEach(() => {
    uploadPaperCopy.mockReset();
    URL.createObjectURL = vi.fn((file: Blob) => `blob:${(file as File).name}`) as unknown as typeof URL.createObjectURL;
    URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
    URL.createObjectURL = realCreate;
    URL.revokeObjectURL = realRevoke;
});

const png = (name: string): File => new File(['x'], name, { type: 'image/png' });
const pdf = (name: string): File => new File(['x'], name, { type: 'application/pdf' });

function mountUpload(online = true): VueWrapper {
    return mount(PaperCopyUpload, { props: { limits, client, online } });
}

function toggle(wrapper: VueWrapper) {
    return wrapper.findAll('button').find((button) => button.text().includes('Filled in a paper copy'))!;
}

async function choose(wrapper: VueWrapper, files: File[]): Promise<void> {
    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: files, configurable: true });
    await input.trigger('change');
}

function refusal(normalized: Partial<NormalizedError>): ApiError {
    return new ApiError({ httpStatus: 429, code: '', message: '', fieldErrors: {}, kind: 'rate_limited', retryAfterSeconds: null, ...normalized });
}

describe('PaperCopyUpload', () => {
    it('stays one quiet button until asked for, and says so to a screen reader', async () => {
        const wrapper = mountUpload();
        const panel = wrapper.find('form');

        expect(toggle(wrapper).attributes('aria-expanded')).toBe('false');
        expect(toggle(wrapper).attributes('aria-controls')).toBe(panel.attributes('id'));
        expect((panel.element as HTMLElement).style.display).toBe('none');

        await toggle(wrapper).trigger('click');

        expect(toggle(wrapper).attributes('aria-expanded')).toBe('true');
        expect((panel.element as HTMLElement).style.display).toBe('');
    });

    it('refuses a PDF sent with photos before anything is sent, in the scans page\'s words', async () => {
        const wrapper = mountUpload();
        await toggle(wrapper).trigger('click');
        await choose(wrapper, [pdf('scan.pdf'), png('photo.png')]);
        await wrapper.find('form').trigger('submit');

        expect(uploadPaperCopy).not.toHaveBeenCalled();
        expect(wrapper.find('[role="alert"]').text()).toContain('Upload a PDF scan on its own');
    });

    it('sends the chosen pages and passes the server\'s reference up', async () => {
        uploadPaperCopy.mockResolvedValue({ reference: '7K4M-2QXB', paperToken: 'paper-1' });
        const wrapper = mountUpload();
        await toggle(wrapper).trigger('click');
        await choose(wrapper, [png('one.png'), png('two.png')]);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(uploadPaperCopy).toHaveBeenCalledTimes(1);
        expect((uploadPaperCopy.mock.calls[0][0] as File[]).map((file) => file.name)).toEqual(['one.png', 'two.png']);
        expect(wrapper.emitted('received')).toEqual([['7K4M-2QXB', 'paper-1']]);
    });

    it('sends nothing offline, and says why', async () => {
        const wrapper = mountUpload(false);
        await toggle(wrapper).trigger('click');
        await choose(wrapper, [png('one.png')]);
        await wrapper.find('form').trigger('submit');

        expect(uploadPaperCopy).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('You are offline');
    });

    it('asks a device that sent several copies to wait, in minutes, and shows the daily limit in the server\'s words', async () => {
        const wrapper = mountUpload();
        await toggle(wrapper).trigger('click');
        await choose(wrapper, [png('one.png')]);

        uploadPaperCopy.mockRejectedValueOnce(refusal({ code: 'rate_limited', message: 'Too Many Attempts.', retryAfterSeconds: 290 }));
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toBe('You have sent several copies just now. Please wait 5 minutes and try again.');

        uploadPaperCopy.mockRejectedValueOnce(refusal({ code: 'paper_upload_limit_reached', message: 'Please try again tomorrow.' }));
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toBe('Please try again tomorrow.');
        expect(wrapper.emitted('received')).toBeUndefined();
    });
});

/*
 * M166 (`D116`) — the wait while the copy is read. It asks the server where the scan stands until it is read, then hands
 * up the answers; a failure offers filling in by hand or leaving it for staff, and leaving is possible from the start.
 */
describe('PaperReading', () => {
    const fetchPaper = vi.fn();
    const paperClient = { fetchPaper } as unknown as ApiClient;

    beforeEach(() => {
        fetchPaper.mockReset();
    });

    function mountReading(): VueWrapper {
        return mount(PaperReading, { props: { client: paperClient, paperToken: 'paper-1', intervalMs: 5 } });
    }

    function button(wrapper: VueWrapper, name: string) {
        return wrapper.findAll('button').find((candidate) => candidate.text() === name)!;
    }

    it('asks until the copy is read, then hands up its answers, notes and pages', async () => {
        const read = { status: 'read', version_id: 'v1', answers: { name: 'Ada' }, fields: {}, pages: [] };
        fetchPaper.mockResolvedValueOnce({ status: 'reading' }).mockResolvedValueOnce({ status: 'reading' }).mockResolvedValueOnce(read);
        const wrapper = mountReading();

        await vi.waitFor(() => expect(wrapper.emitted('read')).toBeDefined());
        expect(fetchPaper).toHaveBeenCalledTimes(3);
        expect(fetchPaper).toHaveBeenCalledWith('paper-1');
        expect(wrapper.emitted('read')?.[0]).toEqual([{ answers: { name: 'Ada' }, fields: {}, pages: [] }]);
    });

    it('offers filling in by hand or leaving it for staff when the copy cannot be read', async () => {
        fetchPaper.mockResolvedValue({ status: 'failed' });
        const wrapper = mountReading();
        await vi.waitFor(() => expect(wrapper.text()).toContain('We could not read your pages'));

        await button(wrapper, 'Fill in the form myself').trigger('click');
        expect(wrapper.emitted('fillByHand')).toHaveLength(1);
        await button(wrapper, 'Leave it for our staff').trigger('click');
        expect(wrapper.emitted('leave')).toHaveLength(1);
    });

    it('lets the sender leave it for staff while it is read, and stops asking', async () => {
        fetchPaper.mockResolvedValue({ status: 'reading' });
        const wrapper = mountReading();
        await flushPromises();

        await button(wrapper, 'Leave it for our staff instead').trigger('click');
        expect(wrapper.emitted('leave')).toHaveLength(1);
        const asked = fetchPaper.mock.calls.length;
        await new Promise((resolve) => setTimeout(resolve, 30));
        expect(fetchPaper.mock.calls.length).toBe(asked);
    });

    it('treats a copy already submitted, and a refused token, as done with', async () => {
        fetchPaper.mockResolvedValue({ status: 'submitted' });
        const submitted = mountReading();
        await vi.waitFor(() => expect(submitted.emitted('leave')).toHaveLength(1));

        fetchPaper.mockReset();
        fetchPaper.mockRejectedValue(refusal({ httpStatus: 403, code: 'paper_token_expired', kind: 'terminal', message: 'expired' }));
        const refused = mountReading();
        await vi.waitFor(() => expect(refused.text()).toContain('We could not read your pages'));
    });
});
