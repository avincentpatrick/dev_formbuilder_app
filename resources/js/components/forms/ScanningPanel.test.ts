import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

/**
 * M129 — the Scanning settings section: an optimistic toggle that reverts when the server refuses, and the
 * reason a form cannot be read yet, in the upload's own words.
 */

const mocks = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { patch: mocks.patch } }));

const ScanningPanel = (await import('./ScanningPanel.vue')).default;

function checkbox(wrapper: ReturnType<typeof mount>): HTMLInputElement {
    return wrapper.find('input[type="checkbox"]').element as HTMLInputElement;
}

beforeEach(() => {
    mocks.patch.mockReset();
});

describe('ScanningPanel', () => {
    it('shows what the server holds, and writes the switch to the form\'s own route', async () => {
        const wrapper = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: { enabled: false, eligible: true, reason: null } } });

        expect(checkbox(wrapper).checked).toBe(false);

        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        const [url, body, options] = mocks.patch.mock.calls[0] as [string, Record<string, unknown>, Record<string, unknown>];
        expect(url).toBe('/forms/form-1/ocr-scanning');
        expect(body).toEqual({ allow_ocr_single: true });
        expect(options.preserveState).toBe(true);
    });

    it('puts the switch back when the server refuses it', async () => {
        const wrapper = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: { enabled: false, eligible: true, reason: null } } });

        await wrapper.find('input[type="checkbox"]').setValue(true);
        const options = mocks.patch.mock.calls[0][2] as { onError: () => void };
        options.onError();
        await nextTick();

        expect(checkbox(wrapper).checked).toBe(false);
    });

    it('says why the form cannot be read yet, and says nothing when it can', () => {
        const blocked = mount(ScanningPanel, {
            props: { open: true, formId: 'form-1', scanning: { enabled: true, eligible: false, reason: 'Scans can be read once this form is published.' } },
        });
        expect(blocked.find('[data-scanning-reason]').text()).toBe('Scans can be read once this form is published.');

        const fine = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: { enabled: true, eligible: true, reason: null } } });
        expect(fine.find('[data-scanning-reason]').exists()).toBe(false);
        expect(checkbox(fine).checked).toBe(true);
    });
});
