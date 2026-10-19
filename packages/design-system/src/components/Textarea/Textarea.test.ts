import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Textarea from './Textarea.vue';

/**
 * M176 (`R-923c5041`) — the first spec for this component, added with `autosize` and the counter.
 *
 * The testers' comment (2026-10-11, comment 7): "Label field is too small. It cannot accommodate long questions. Make
 * the field multi-line or expanding." The builder's label boxes take this component with `autosize`, so it must grow with
 * its text and say how near the limit it is — and every existing call site, which passes neither, must not move.
 *
 * ⚠️ happy-dom lays nothing out, so `scrollHeight` is set by hand: these cases prove the component READS the content's
 * height at the right moments and writes it back, never that a browser computes it. The real-browser look is the e2e.
 */
function withContentHeight(element: HTMLElement, height: number): void {
    Object.defineProperty(element, 'scrollHeight', { configurable: true, get: () => height });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('MdsTextarea — the default does not move', () => {
    it('keeps three rows, its resize handle and no inline height when autosize is not asked for', () => {
        const wrapper = mount(Textarea, { props: { modelValue: 'A quarterly survey.' } });
        const area = wrapper.get('textarea');

        expect(area.attributes('rows')).toBe('3');
        expect(area.classes()).not.toContain('mds-textarea--autosize');
        expect((area.element as HTMLTextAreaElement).style.height).toBe('');
        expect(wrapper.find('.mds-textarea__count').exists()).toBe(false);
    });

    it('still hands a parent\'s attributes and class to the textarea itself', () => {
        const wrapper = mount(Textarea, { attrs: { 'aria-label': 'Form description', class: 'extra', maxlength: '1000', 'data-x': 'y' } });
        const area = wrapper.get('textarea');

        expect(area.attributes('aria-label')).toBe('Form description');
        expect(area.attributes('maxlength')).toBe('1000');
        expect(area.attributes('data-x')).toBe('y');
        expect(area.classes()).toContain('extra');
    });
});

describe('MdsTextarea — autosize', () => {
    it('starts one row high and grows to its content on mount, on typing and on a new value', async () => {
        const wrapper = mount(Textarea, { props: { autosize: true, modelValue: 'How many people usually sleep here?' }, attachTo: document.body });
        const area = wrapper.get('textarea').element as HTMLTextAreaElement;

        expect(wrapper.get('textarea').attributes('rows')).toBe('1');
        expect(wrapper.get('textarea').classes()).toContain('mds-textarea--autosize');

        withContentHeight(area, 72);
        await wrapper.get('textarea').trigger('input');
        expect(area.style.height).toBe(`${72 + area.offsetHeight - area.clientHeight}px`);

        withContentHeight(area, 120);
        await wrapper.setProps({ modelValue: 'A much longer question that wraps onto a third line in the settings panel.' });
        expect(area.style.height).toBe(`${120 + area.offsetHeight - area.clientHeight}px`);

        wrapper.unmount();
    });
});

describe('MdsTextarea — the counter', () => {
    it('says how many characters are used only once the text is near the limit, and ties it to the field', async () => {
        const wrapper = mount(Textarea, { props: { maxlength: 500, countFrom: 450, modelValue: 'x'.repeat(449), describedby: 'help-1' } });

        expect(wrapper.find('.mds-textarea__count').exists()).toBe(false);
        expect(wrapper.get('textarea').attributes('maxlength')).toBe('500');
        expect(wrapper.get('textarea').attributes('aria-describedby')).toBe('help-1');

        await wrapper.setProps({ modelValue: 'x'.repeat(480) });
        const count = wrapper.get('.mds-textarea__count');
        expect(count.text()).toBe('480 of 500 characters');
        expect(wrapper.get('textarea').attributes('aria-describedby')).toBe(`help-1 ${count.attributes('id')}`);
    });
});

describe('MdsTextarea — its root', () => {
    it('is the <textarea> itself for a caller that asks for no counter, as a formatting toolbar needs it to be', () => {
        // `ContentBlocksEditor` reads the box through `$el`; a wrapper or a second root would hand it something else.
        expect(mount(Textarea, { props: { autosize: true } }).vm.$el).toBeInstanceOf(HTMLTextAreaElement);
        expect(mount(Textarea).vm.$el).toBeInstanceOf(HTMLTextAreaElement);
    });
});

describe('MdsTextarea — the box itself, for a caller that writes at the caret (M178)', () => {
    it('exposes the <textarea> as `field`, with the counter and without it', () => {
        const bare = mount(Textarea, { props: { modelValue: 'x' } });
        const counted = mount(Textarea, { props: { modelValue: 'x', maxlength: 500, countFrom: 0 } });

        expect((bare.vm as unknown as { field: unknown }).field).toBe(bare.get('textarea').element);
        expect((counted.vm as unknown as { field: unknown }).field).toBe(counted.get('textarea').element);
        // With the counter the root is the wrapper, which is why `field` exists at all.
        expect(counted.element.tagName).toBe('DIV');
        bare.unmount();
        counted.unmount();
    });
});
