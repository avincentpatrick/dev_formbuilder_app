/**
 * The "After submitting" panel (M248, `D232`) — another response, "Change my answers" until the form closes, or one
 * response. Pinned here for `PageModePanel.test.ts`'s reason: the panel builds `/forms/${id}/after-submit` as a template
 * literal, which no Pest test can see, so a renamed route would leave every PHP case green and every author's choice
 * silently 404ing. The optimistic write and its revert are the other half.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AfterSubmitPanel from './AfterSubmitPanel.vue';

interface Patch {
    url: string;
    payload: Record<string, unknown>;
    opts: { onError?: () => void; preserveScroll?: boolean; preserveState?: boolean };
}

const patches: Patch[] = [];

vi.mock('@inertiajs/vue3', () => ({
    router: {
        patch: (url: string, payload: Record<string, unknown>, opts: Patch['opts']) => patches.push({ url, payload, opts }),
    },
}));

function mountPanel(afterSubmit: 'another' | 'amend' | 'once' = 'another', open = true) {
    return mount(AfterSubmitPanel, { props: { open, formId: 'form-1', afterSubmit } });
}

function checked(wrapper: ReturnType<typeof mountPanel>): string | undefined {
    return (wrapper.findAll('input[type="radio"]').find((r) => (r.element as HTMLInputElement).checked)?.element as HTMLInputElement | undefined)?.value;
}

beforeEach(() => {
    patches.length = 0;
});

describe('AfterSubmitPanel', () => {
    it('offers the three choices in one radio group, seeded from the form', () => {
        for (const value of ['another', 'amend', 'once'] as const) {
            const wrapper = mountPanel(value);
            const radios = wrapper.findAll('input[type="radio"]');

            expect(radios).toHaveLength(3);
            expect(new Set(radios.map((r) => r.attributes('name'))).size).toBe(1);
            expect(checked(wrapper)).toBe(value);
            wrapper.unmount();
        }
    });

    it('says the one-device limit before the author chooses', () => {
        expect(mountPanel().text()).toContain('hold for one device');
    });

    it('saves the choice to the form\'s own route at once', async () => {
        const wrapper = mountPanel('another');

        await wrapper.findAll('input[type="radio"]')[1].setValue(true);

        expect(patches).toHaveLength(1);
        expect(patches[0].url).toBe('/forms/form-1/after-submit');
        expect(patches[0].payload).toEqual({ after_submit: 'amend' });
        expect(checked(wrapper)).toBe('amend');
    });

    it('reverts the optimistic choice when the write is refused', async () => {
        const wrapper = mountPanel('another');

        await wrapper.findAll('input[type="radio"]')[2].setValue(true);
        patches[0].opts.onError?.();
        await wrapper.vm.$nextTick();

        expect(checked(wrapper)).toBe('another');
    });

    it('re-seeds on reopen, so a reverted value cannot survive a close', async () => {
        const wrapper = mountPanel('once', false);

        await wrapper.setProps({ open: true });

        expect(checked(wrapper)).toBe('once');
    });
});
