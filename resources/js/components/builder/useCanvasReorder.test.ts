import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import BuilderCanvas from './BuilderCanvas.vue';
import { fetchMock, jsonResponse, pageProps, requestLog, serverField, serverSection } from './builder-store-fixtures';
import type { ServerField } from './types';
import { useBuilderStore } from './useBuilderStore';

/**
 * M150 (`R-adce6e14`, `R-732e0715`, and the focus row filed with them) — the canvas's pointer drag and keyboard grab, driven
 * through the real component. happy-dom has no layout, so each row is given a 40px slot by its place in its list, and
 * `elementFromPoint` answers with the list under the pointer.
 */
const ROW = 40;
let wrapper: VueWrapper | null = null;
let fetch: ReturnType<typeof fetchMock>;

beforeEach(() => {
    fetch = fetchMock().mockResolvedValue(jsonResponse(200, {}));
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
        const index = this.parentElement ? Array.from(this.parentElement.children).indexOf(this) : 0;
        return { top: index * ROW, height: ROW, bottom: (index + 1) * ROW, left: 0, right: 300, width: 300, x: 0, y: index * ROW } as DOMRect;
    });
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

function canvas(fields: Array<Partial<ServerField>>, sections = [serverSection({ id: 's1', key: 'visit', label: 'Visit', sequence: 1 })]) {
    const store = useBuilderStore(
        pageProps({
            sections,
            fields: fields.map((f, i) =>
                serverField({ id: `f${i + 1}`, key: `q${i + 1}`, label: `Q${i + 1}`, sequence: i + 1, form_section_id: null, ...f }),
            ),
        }),
    );
    wrapper = mount(BuilderCanvas, { props: { store, fieldTypeLabels: { short_text: 'Text' } }, attachTo: document.body });
    // The pointer is always over the top-level list.
    document.elementFromPoint = vi.fn(() => wrapper!.find('[data-drop-group="ungrouped"]').element) as typeof document.elementFromPoint;

    return { store, wrapper };
}

function order(store: ReturnType<typeof useBuilderStore>): string[] {
    return store.groups.value.map((g) => `${g.section?.id ?? 'top'}:${g.fields.map((f) => f.label).join(',')}`);
}

function grip(w: VueWrapper, label: string): HTMLElement {
    return w.find(`button[aria-label^="Reorder ${label}."]`).element as HTMLElement;
}

function pointer(target: EventTarget, type: string, y: number, pointerId = 1): void {
    target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, button: 0, pointerId, clientX: 10, clientY: y }));
}

function said(w: VueWrapper): string {
    return w.find('.canvas__sr').text();
}

function reorders(): number {
    return requestLog(fetch).filter((r) => r.method === 'POST' && r.url.endsWith('/reorder')).length;
}

describe('pointer drag (M150)', () => {
    it('does nothing for a press and release on a grip', async () => {
        const { store, wrapper: w } = canvas([{}, {}, {}]);

        pointer(grip(w, 'Q1'), 'pointerdown', 20);
        pointer(window, 'pointerup', 20);
        await flushPromises();

        expect(said(w)).toBe('');
        expect(reorders()).toBe(0);
        expect(order(store)).toEqual(['top:Q1,Q2,Q3', 's1:']);
    });

    it('does not begin a drag for a move under the threshold', async () => {
        const { store, wrapper: w } = canvas([{}, {}, {}]);

        pointer(grip(w, 'Q1'), 'pointerdown', 20);
        pointer(window, 'pointermove', 23);
        pointer(window, 'pointerup', 23);
        await flushPromises();

        expect(said(w)).toBe('');
        expect(reorders()).toBe(0);
        expect(order(store)).toEqual(['top:Q1,Q2,Q3', 's1:']);
    });

    it('moves a question below the row it is dropped on, and saves it once', async () => {
        const { store, wrapper: w } = canvas([{}, {}, {}]);

        pointer(grip(w, 'Q1'), 'pointerdown', 20);
        // Past the threshold, still over its own row: nothing moves.
        pointer(window, 'pointermove', 30);
        await flushPromises();
        expect(said(w)).toContain('Dragging Q1');
        expect(order(store)).toEqual(['top:Q1,Q2,Q3', 's1:']);

        // Over the lower half of the last row.
        pointer(window, 'pointermove', 115);
        await flushPromises();
        expect(order(store)).toEqual(['top:Q2,Q3,Q1', 's1:']);

        pointer(window, 'pointerup', 115);
        await flushPromises();
        expect(said(w)).toContain('Dropped Q1');
        expect(reorders()).toBe(1);
    });

    it('puts everything back when the browser cancels the pointer, and listens no further', async () => {
        const { store, wrapper: w } = canvas([{}, {}, {}]);

        pointer(grip(w, 'Q1'), 'pointerdown', 20);
        pointer(window, 'pointermove', 30);
        await flushPromises();
        pointer(window, 'pointermove', 115);
        await flushPromises();
        pointer(window, 'pointercancel', 115);
        await flushPromises();

        expect(order(store)).toEqual(['top:Q1,Q2,Q3', 's1:']);
        expect(said(w)).toBe('Reorder cancelled.');
        pointer(window, 'pointermove', 115);
        pointer(window, 'pointerup', 115);
        await flushPromises();
        expect(order(store)).toEqual(['top:Q1,Q2,Q3', 's1:']);
        expect(reorders()).toBe(0);
    });

    it('puts everything back on Escape', async () => {
        const { store, wrapper: w } = canvas([{}, {}, {}]);

        pointer(grip(w, 'Q1'), 'pointerdown', 20);
        pointer(window, 'pointermove', 30);
        await flushPromises();
        pointer(window, 'pointermove', 115);
        await flushPromises();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(order(store)).toEqual(['top:Q1,Q2,Q3', 's1:']);
        expect(said(w)).toBe('Reorder cancelled.');
        expect(reorders()).toBe(0);
    });

    it('ignores a second pointer', async () => {
        const { store, wrapper: w } = canvas([{}, {}, {}]);

        pointer(grip(w, 'Q1'), 'pointerdown', 20);
        pointer(window, 'pointermove', 115, 2);
        pointer(window, 'pointerup', 115, 2);
        await flushPromises();

        expect(said(w)).toBe('');
        expect(order(store)).toEqual(['top:Q1,Q2,Q3', 's1:']);
    });
});

describe('keyboard grab (M150)', () => {
    it('tells a screen reader that Space grabs too, on a question and on a section', () => {
        const { wrapper: w } = canvas([{}]);

        expect(grip(w, 'Q1').getAttribute('aria-label')).toBe('Reorder Q1. Press Enter or Space to grab, then arrow keys; or drag.');
        expect(w.find('button[aria-label^="Reorder section Visit."]').attributes('aria-label')).toBe(
            'Reorder section Visit. Press Enter or Space to grab, then arrow keys; or drag.',
        );
    });

    it('grabs on Space', async () => {
        const { wrapper: w } = canvas([{}, {}]);

        grip(w, 'Q1').dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        await flushPromises();

        expect(said(w)).toContain('Grabbed Q1');
    });

    it('keeps focus on the grip of a question stepped out of its section, so the drop is saved', async () => {
        const { store, wrapper: w } = canvas([{ form_section_id: 's1' }]);
        const before = grip(w, 'Q1');
        before.focus();

        before.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        before.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
        await flushPromises();

        // A new row in another list: the old grip is gone, and focus is on the new one.
        expect(order(store)).toEqual(['top:Q1', 's1:']);
        const after = grip(w, 'Q1');
        expect(after).not.toBe(before);
        expect(document.activeElement).toBe(after);

        (document.activeElement as HTMLElement).dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();
        expect(said(w)).toContain('Dropped Q1');
        expect(reorders()).toBe(1);
    });
});
