import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * M131 (`R-6017d6d8`, `D81`) — the Theme section: the workspace brand plus every TRANSMITTED preset, the current
 * one checked, null sent for the workspace brand, and an optimistic choice reverted when the server refuses it.
 */

const mocks = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { patch: mocks.patch } }));

const ThemePanel = (await import('./ThemePanel.vue')).default;

const TOKENS = {
    light: { bg: '#4A5568', bg_hover: '#3C4759', bg_active: '#2C3648', fg: '#4A5568', tint: '#F0F5FE', ring: '#3C4759' },
    dark: { bg: '#545F73', bg_hover: '#3E485B', bg_active: '#2F3A4B', fg: '#8793A8', tint: '#364052', ring: '#8793A8' },
};

const PRESETS = [
    { value: 'forest', label: 'Forest', description: 'Green.', font: 'Standard type', radius: 'Standard corners', tokens: TOKENS, lines: {} },
    { value: 'graphite', label: 'Graphite', description: 'Slate.', font: 'Serif headings', radius: 'Crisp corners', tokens: TOKENS, lines: {} },
];

function mountPanel(preset: string | null) {
    return mount(ThemePanel, { props: { open: true, formId: 'form-1', preset, presets: PRESETS } });
}

beforeEach(() => mocks.patch.mockReset());

describe('ThemePanel', () => {
    it('offers the workspace brand and every transmitted preset, with the current one checked', () => {
        const wrapper = mountPanel('graphite');
        const radios = wrapper.findAll('input[type="radio"]');

        expect(radios.map((r) => (r.element as HTMLInputElement).value)).toEqual(['', 'forest', 'graphite']);
        expect((radios[2].element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.text()).toContain('Workspace brand');
        expect(wrapper.text()).toContain('Serif headings, crisp corners.');
        // One name for the whole group, per form.
        expect(new Set(radios.map((r) => r.attributes('name')))).toEqual(new Set(['form-theme-form-1']));
    });

    it('checks the workspace brand when the form has no preset', () => {
        const wrapper = mountPanel(null);

        expect((wrapper.findAll('input[type="radio"]')[0].element as HTMLInputElement).checked).toBe(true);
    });

    it('saves a preset by its value, and the workspace brand as null', async () => {
        const wrapper = mountPanel(null);

        await wrapper.findAll('input[type="radio"]')[1].setValue(true);
        expect(mocks.patch.mock.calls[0][0]).toBe('/forms/form-1/theme');
        expect(mocks.patch.mock.calls[0][1]).toEqual({ preset: 'forest' });

        await wrapper.findAll('input[type="radio"]')[0].setValue(true);
        expect(mocks.patch.mock.calls[1][1]).toEqual({ preset: null });
    });

    it('puts the previous choice back when the server refuses the new one', async () => {
        const wrapper = mountPanel('forest');

        await wrapper.findAll('input[type="radio"]')[2].setValue(true);
        const options = mocks.patch.mock.calls[0][2] as { onError: () => void };
        options.onError();
        await wrapper.vm.$nextTick();

        expect((wrapper.findAll('input[type="radio"]')[1].element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.find('[data-theme-option="forest"]').classes()).toContain('theme-panel__option--on');
    });

    it('hides the swatches from assistive technology, which the words already say', () => {
        const wrapper = mountPanel(null);

        expect(wrapper.findAll('.theme-panel__swatches').every((s) => s.attributes('aria-hidden') === 'true')).toBe(true);
    });
});
