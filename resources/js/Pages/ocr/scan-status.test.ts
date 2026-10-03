import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * M129 — the page a scan sits on while it is read. It asks the scan's JSON route on a timer, reloads once
 * reading has ended (the server then renders the review or the failure), and stops asking when it goes.
 *
 * ⚠️ The M118 timer rule applies: change state → `flushPromises()` → advance → `flushPromises()`. A clock
 * advanced before the awaited fetch settles has nothing queued on it.
 */

const mocks = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload: mocks.reload },
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', props: ['title'], template: '<header><h1>{{ title }}</h1><slot name="breadcrumbs" /></header>' },
}));

const ScanStatus = (await import('./ScanStatus.vue')).default;

function props(status: string, errorMessage: string | null = null): Record<string, unknown> {
    return {
        form: { id: 'form-1', title: 'Clinic Visit' },
        scan: { id: 'scan-1', status, status_label: 'Being read', pages: 2, error_message: errorMessage, poll_url: '/forms/form-1/ocr/scans/scan-1' },
        encode_url: '/forms/form-1/submissions/create',
        scans_url: '/forms/form-1/ocr/scans',
        crumbs: [{ label: 'Forms', href: '/forms' }, { label: 'Reading a scan' }],
    };
}

function answer(status: string): Response {
    return new Response(JSON.stringify({ data: { status } }), { status: 200 });
}

const fetchMock = vi.fn();

beforeEach(() => {
    vi.useFakeTimers();
    mocks.reload.mockReset();
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('ocr/ScanStatus', () => {
    it('keeps asking while the scan is read, and reloads once reading has ended', async () => {
        fetchMock.mockResolvedValueOnce(answer('reading')).mockResolvedValueOnce(answer('read'));
        mount(ScanStatus, { props: props('reading') as never });

        await vi.advanceTimersByTimeAsync(2500);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(mocks.reload).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(2500);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).toBe('/forms/form-1/ocr/scans/scan-1');
        expect(mocks.reload).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(10_000);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('stops asking when the page goes', async () => {
        fetchMock.mockResolvedValue(answer('reading'));
        const wrapper = mount(ScanStatus, { props: props('queued') as never });

        await vi.advanceTimersByTimeAsync(2500);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(10_000);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('says when progress cannot be checked, rather than spinning for ever', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
        const wrapper = mount(ScanStatus, { props: props('reading') as never });

        for (let i = 0; i < 3; i++) {
            await vi.advanceTimersByTimeAsync(2500);
            await flushPromises();
        }

        expect(wrapper.find('[role="alert"]').text()).toContain('could not be checked');
    });

    it('shows why a failed scan was not read, the way forward, and asks nothing', async () => {
        const wrapper = mount(ScanStatus, { props: props('failed', 'The reading service could not open this file.') as never });

        await vi.advanceTimersByTimeAsync(10_000);
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('h1').text()).toBe('This scan could not be read');
        expect(wrapper.text()).toContain('The reading service could not open this file.');
        expect(wrapper.find('a[href="/forms/form-1/submissions/create"]').text()).toBe('Enter the response by hand');
    });
});
