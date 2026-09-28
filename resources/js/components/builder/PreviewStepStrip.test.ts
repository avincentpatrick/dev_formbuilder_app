import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

/*
 * Increment M119, `R-ae391298` — the preview's section strip, mounted alone.
 *
 * `PreviewPane.test.ts` pins what the strip does to the preview around it: live labels, step movement, and
 * surviving an engine rebuild. This file pins the two properties that belong to the control itself and that
 * the pane cannot see — WHERE IT DEGRADES, and WHAT ROLE IT ACTUALLY IMPLEMENTS.
 *
 * ⛔ THE THRESHOLD IS ASSERTED FROM BOTH SIDES, WHICH IS THE ONLY WAY IT MEANS ANYTHING. A case that renders
 * three options and finds segments passes with the comparison deleted, with it inverted, and with the select
 * branch removed outright. The ceiling and the ceiling plus one, asserted as a pair, fail on all three.
 */

import PreviewStepStrip from './PreviewStepStrip.vue';
import { PREVIEW_STRIP_MAX_SEGMENTS, type PreviewStripOption } from './preview-model';

function options(count: number): PreviewStripOption[] {
    return Array.from({ length: count }, (_, i) => ({ value: `s${i + 1}`, label: `${i + 1}. Section ${i + 1}` }));
}

function mountStrip(count: number, currentKey: string | null = 's1') {
    return mount(PreviewStepStrip, { props: { options: options(count), currentKey } });
}

describe('it degrades at a stated threshold rather than at a CSS guess', () => {
    it('renders segments at the ceiling', () => {
        const wrapper = mountStrip(PREVIEW_STRIP_MAX_SEGMENTS);

        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(PREVIEW_STRIP_MAX_SEGMENTS);
        expect(wrapper.find('select').exists()).toBe(false);
    });

    it('renders a select one step above the ceiling', () => {
        const wrapper = mountStrip(PREVIEW_STRIP_MAX_SEGMENTS + 1);

        expect(wrapper.find('select').exists()).toBe(true);
        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(0);
        expect(wrapper.findAll('option')).toHaveLength(PREVIEW_STRIP_MAX_SEGMENTS + 1);
    });
});

describe('it reports a choice, and reports nothing else', () => {
    it('emits the chosen step from the segmented form', async () => {
        const wrapper = mountStrip(3);

        await wrapper.findAll('input[type="radio"]')[2].setValue();

        expect(wrapper.emitted('go')).toEqual([['s3']]);
    });

    it('emits the chosen step from the select form', async () => {
        const wrapper = mountStrip(PREVIEW_STRIP_MAX_SEGMENTS + 1);

        await wrapper.find('select').setValue('s4');

        expect(wrapper.emitted('go')).toEqual([['s4']]);
    });

    // Re-choosing the step already on screen is not a navigation. Forwarding it would send the preview
    // through `goToStep` for no reason, and a radio group re-fires `change` more readily than a click does.
    it('stays quiet when the step already on screen is chosen again', async () => {
        const wrapper = mountStrip(3, 's2');

        await wrapper.findAll('input[type="radio"]')[1].setValue();

        expect(wrapper.emitted('go')).toBeUndefined();
    });

    // Before the engine settles there is no current step. Checking a segment anyway would tell the author
    // they are somewhere they are not.
    it('checks nothing while no step is current', () => {
        const checked = mountStrip(3, null)
            .findAll('input[type="radio"]')
            .filter((r) => (r.element as HTMLInputElement).checked);

        expect(checked).toHaveLength(0);
    });
});

describe('it claims no role it does not implement', () => {
    // ⛔ THIRTEEN PLAYWRIGHT LOCATORS WALK `[role="tab"]` ON THE BUILDER and four of them click every match,
    // so a tab role here would be clicked mid-scan by tests that have nothing to do with the preview. The
    // radiogroup semantics come from the native fieldset, which brings the arrow-key roving with them; a
    // hand-written `role="radiogroup"` would claim the contract and implement none of it.
    it('is a native fieldset of radios, with no tab role anywhere', () => {
        const wrapper = mountStrip(3);

        expect(wrapper.findAll('fieldset')).toHaveLength(1);
        expect(wrapper.html()).not.toContain('tablist');
        expect(wrapper.html()).not.toContain('role="tab"');
        expect(wrapper.html()).not.toContain('role="radiogroup"');
    });

    it('names itself for a screen reader in both forms', () => {
        expect(mountStrip(3).find('legend').text()).toBe('Jump to section');
        expect(mountStrip(PREVIEW_STRIP_MAX_SEGMENTS + 1).find('select').attributes('aria-label'))
            .toBe('Jump to section');
    });
});
