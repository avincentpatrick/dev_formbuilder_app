/**
 * The presentation-mode panel (`M120`, `R-f1332829`, `D35`) — one page, or one section at a time.
 *
 * ⛔ WHY THIS FILE EXISTS WHEN `SaveResumePanel` HAS NO SUITE AT ALL. `FormSettingsModal.test.ts` argues that
 * a panel is pinned by the Pest test for its route rather than through the modal shell, and that argument is
 * right about the PAYLOAD and silent about the URL: this panel builds `/forms/${id}/page-mode` as a template
 * literal, and no PHP test can see a TypeScript string. A renamed route would leave every Pest case green and
 * every author's toggle silently 404ing. That seam is what is pinned here.
 *
 * The optimistic write and its revert are the other half — `SaveResumePanel` carries the same logic untested,
 * which `M120` filed rather than fixed in passing.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { mount } from '@vue/test-utils';
import { describe as group, beforeEach, expect, it, vi } from 'vitest';

import PageModePanel from './PageModePanel.vue';

interface Patch {
    url: string;
    payload: Record<string, unknown>;
    opts: { onError?: () => void; preserveScroll?: boolean; preserveState?: boolean };
}

const patches: Patch[] = [];

vi.mock('@inertiajs/vue3', () => ({
    router: {
        patch: (url: string, payload: Record<string, unknown>, opts: Patch['opts']) =>
            patches.push({ url, payload, opts }),
    },
}));

function mountPanel(singlePageMode = false, open = true) {
    return mount(PageModePanel, {
        props: { open, formId: 'form-1', singlePageMode },
    });
}

/** The label of the currently chosen segment. */
function chosen(wrapper: ReturnType<typeof mountPanel>): string {
    return wrapper.get('.is-selected').text();
}

beforeEach(() => {
    patches.length = 0;
});

group('PageModePanel — seeding', () => {
    it('seeds from the form in both directions', () => {
        const stepped = mountPanel(false);
        expect(chosen(stepped)).toBe('Step by step');
        stepped.unmount();

        const onePage = mountPanel(true);
        expect(chosen(onePage)).toBe('One page');
        onePage.unmount();
    });

    it('re-seeds on reopen, so a reverted optimistic value cannot survive a close', async () => {
        // The modal keeps sections mounted once visited, so a stale local value would otherwise outlive the
        // dialog — the same reason `SaveResumePanel` watches `open` rather than mounting fresh.
        const wrapper = mountPanel(false, false);

        await wrapper.setProps({ open: true });
        expect(chosen(wrapper)).toBe('Step by step');

        await wrapper.findAll('input[type="radio"]')[1].setValue();
        expect(chosen(wrapper)).toBe('One page');

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        expect(chosen(wrapper)).toBe('Step by step');

        wrapper.unmount();
    });
});

group('PageModePanel — the write', () => {
    it('patches the page-mode route with the COLUMN name the FormRequest validates', async () => {
        const wrapper = mountPanel(false);

        await wrapper.findAll('input[type="radio"]')[1].setValue();

        expect(patches).toHaveLength(1);
        // ⛔ BOTH HALVES ARE THE ASSERTION, AND NEITHER IS VISIBLE TO A PEST TEST. The url is a template
        // literal in this file; the key is `single_page_mode` because that is what `UpdatePageModeRequest`
        // validates, even though everything around it is named for the setting rather than the column.
        expect(patches[0].url).toBe('/forms/form-1/page-mode');
        expect(patches[0].payload).toEqual({ single_page_mode: true });
        expect(patches[0].opts.preserveScroll).toBe(true);
        expect(patches[0].opts.preserveState).toBe(true);

        wrapper.unmount();
    });

    it('sends false when the author goes back to step by step', async () => {
        // Non-vacuous in the way that matters: a controller hard-coding `true` passes the case above.
        const wrapper = mountPanel(true);

        await wrapper.findAll('input[type="radio"]')[0].setValue();

        expect(patches[0].payload).toEqual({ single_page_mode: false });

        wrapper.unmount();
    });

    it('reverts the control when the write is refused', async () => {
        const wrapper = mountPanel(false);

        await wrapper.findAll('input[type="radio"]')[1].setValue();
        expect(chosen(wrapper)).toBe('One page');

        patches[0].opts.onError?.();
        await wrapper.vm.$nextTick();

        expect(chosen(wrapper)).toBe('Step by step');

        wrapper.unmount();
    });
});

group('PageModePanel — what it claims to be', () => {
    it('is a native fieldset of two radios and claims no role of its own', async () => {
        // ⛔ THE MODAL THAT HOSTS THIS ASSERTS ITS WHOLE HTML CONTAINS NO `radiogroup` AND NO `[role=tab]`,
        // because four e2e loops click every `[role="tab"]` on the builder page. `MdsSegmentedControl` takes
        // its radiogroup semantics from the native elements and writes no role attribute, which is what lets
        // both things be true at once. Asserted here as well as there, since this is the file that chose it.
        const wrapper = mountPanel(false);

        expect(wrapper.findAll('fieldset')).toHaveLength(1);
        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(2);
        expect(wrapper.html()).not.toContain('radiogroup');
        expect(wrapper.find('[role="tab"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('names both modes, so the default is visible rather than implied', () => {
        // The reason this is a segmented control and not a checkbox: a lone checkbox can name only one mode.
        const wrapper = mountPanel(false);
        const labels = wrapper.findAll('.mds-segmented__seg').map((s) => s.text());

        expect(labels).toEqual(['Step by step', 'One page']);

        wrapper.unmount();
    });

    it('carries D28\'s flex-wrap host guard, which the settings sheet needs below 480px', () => {
        // ⚠️ SOURCE-TEXT, DELIBERATELY. `jsdom` applies no stylesheet, so the only honest way to assert a
        // scoped CSS rule is present is to read the file — the idiom `builder-layout.test.ts` uses for the
        // whole of `Builder.vue`. `min-width: 0` was measured inert on a host the control is already the
        // width of, so what has to wrap is the control's own flex line.
        // `process.cwd()` + a repo-relative path, exactly as `builder-layout.test.ts` does it — an
        // `import.meta.url` file URL is not resolvable in this runner.
        const source = readFileSync(
            join(process.cwd(), 'resources/js/components/builder/PageModePanel.vue'),
            'utf8',
        );

        expect(source).toMatch(/\.page-mode \.mds-segmented\s*\{[^}]*flex-wrap:\s*wrap/);
    });
});
