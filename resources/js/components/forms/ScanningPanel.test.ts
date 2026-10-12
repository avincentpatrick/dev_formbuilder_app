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

/** The Scanning section's props as the server sends them; M165 added the public link's paper copies. */
function scanning(overrides: Record<string, unknown> = {}, guest: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        enabled: false,
        eligible: true,
        reason: null,
        guest: {
            enabled: false,
            window_minutes: 10,
            daily_cap: 20,
            uploads_per_window: 3,
            windows: [1, 2, 5, 10],
            daily_cap_max: 100,
            link_live: true,
            ...guest,
        },
        ...overrides,
    };
}

function checkbox(wrapper: ReturnType<typeof mount>): HTMLInputElement {
    return wrapper.find('input[type="checkbox"]').element as HTMLInputElement;
}

beforeEach(() => {
    mocks.patch.mockReset();
});

describe('ScanningPanel', () => {
    it('shows what the server holds, and writes the switch to the form\'s own route', async () => {
        const wrapper = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: scanning() } });

        expect(checkbox(wrapper).checked).toBe(false);

        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        const [url, body, options] = mocks.patch.mock.calls[0] as [string, Record<string, unknown>, Record<string, unknown>];
        expect(url).toBe('/forms/form-1/ocr-scanning');
        expect(body).toEqual({ allow_ocr_single: true });
        expect(options.preserveState).toBe(true);
    });

    it('puts the switch back when the server refuses it', async () => {
        const wrapper = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: scanning() } });

        await wrapper.find('input[type="checkbox"]').setValue(true);
        const options = mocks.patch.mock.calls[0][2] as { onError: () => void };
        options.onError();
        await nextTick();

        expect(checkbox(wrapper).checked).toBe(false);
    });

    it('says why the form cannot be read yet, and says nothing when it can', () => {
        const blocked = mount(ScanningPanel, {
            props: { open: true, formId: 'form-1', scanning: scanning({ enabled: true, eligible: false, reason: 'Scans can be read once this form is published.' }) },
        });
        expect(blocked.find('[data-scanning-reason]').text()).toBe('Scans can be read once this form is published.');

        const fine = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: scanning({ enabled: true }) } });
        expect(fine.find('[data-scanning-reason]').exists()).toBe(false);
        expect(checkbox(fine).checked).toBe(true);
    });
});

describe('ScanningPanel — paper copies from the public link (M165, `D106`)', () => {
    it("offers the public link's paper copies only while scanning is on", () => {
        const off = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: scanning() } });
        expect(off.find('[data-scanning-guest]').exists()).toBe(false);

        const on = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: scanning({ enabled: true }) } });
        expect(on.find('[data-scanning-guest]').exists()).toBe(true);
    });

    it("offers the server's windows, shortest first, and says how many copies one device may send in one", () => {
        const wrapper = mount(ScanningPanel, {
            props: { open: true, formId: 'form-1', scanning: scanning({ enabled: true }, { window_minutes: 5, uploads_per_window: 3 }) },
        });
        const options = wrapper.findAll('select option').map((option) => option.text());

        expect(options).toEqual(['1 minute', '2 minutes', '5 minutes', '10 minutes']);
        expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('5');
        expect(wrapper.text()).toContain('can send 3 paper copies in this time');
    });

    it('saves the switch and both limits together, with scanning as it stands', async () => {
        const wrapper = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: scanning({ enabled: true }) } });
        const guest = wrapper.find('[data-scanning-guest]');

        await guest.find('input[type="checkbox"]').setValue(true);
        await guest.find('select').setValue('2');
        await guest.find('input[type="number"]').setValue('35');
        await guest.trigger('submit');

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        const [url, body] = mocks.patch.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe('/forms/form-1/ocr-scanning');
        expect(body).toEqual({ allow_ocr_single: true, allow_guest_ocr: true, guest_ocr_window_minutes: 2, guest_ocr_daily_cap: 35 });
    });

    it("shows the server's refusal beside the limit it refused", async () => {
        const wrapper = mount(ScanningPanel, { props: { open: true, formId: 'form-1', scanning: scanning({ enabled: true }) } });

        await wrapper.find('[data-scanning-guest]').trigger('submit');
        const options = mocks.patch.mock.calls[0][2] as { onError: (errors: Record<string, string>) => void };
        options.onError({ guest_ocr_daily_cap: 'Allow at most 100 paper copies a day.' });
        await nextTick();

        expect(wrapper.text()).toContain('Allow at most 100 paper copies a day.');
    });

    it('says nobody can send a copy while the public link is closed', async () => {
        const wrapper = mount(ScanningPanel, {
            props: { open: true, formId: 'form-1', scanning: scanning({ enabled: true }, { enabled: true, link_live: false }) },
        });
        expect(wrapper.find('[data-scanning-link-off]').exists()).toBe(true);

        const live = mount(ScanningPanel, {
            props: { open: true, formId: 'form-1', scanning: scanning({ enabled: true }, { enabled: true, link_live: true }) },
        });
        expect(live.find('[data-scanning-link-off]').exists()).toBe(false);
    });
});
