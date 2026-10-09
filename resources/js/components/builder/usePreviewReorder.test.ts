import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { fetchMock, jsonResponse, pageProps, requestLog, serverField, serverSection } from './builder-store-fixtures';
import PreviewPane from './PreviewPane.vue';
import { PREVIEW_REBUILD_DEBOUNCE_MS } from './preview-model';
import type { ServerField } from './types';
import { useBuilderStore } from './useBuilderStore';

/**
 * M151 (`R-2baef8ea`) — a question moved in the Preview, driven through the real pane, the real store and the REAL engine,
 * because what is under test is the remount a move causes: the order the rebuilt engine shows, the answers it was handed,
 * and the grip focus lands on afterwards. happy-dom has no layout, so each row is given a 40px slot by its place in its
 * list, and `elementFromPoint` answers with whatever element a case points at.
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
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

const VISIT = serverSection({ id: 's1', key: 'visit', label: 'Visit', sequence: 1 });
const LATER = serverSection({ id: 's2', key: 'later', label: 'Later', sequence: 2 });

/** Q1–Q3 in Visit and Q4 in Later, unless a case says otherwise. */
function pane(options: { singlePage?: boolean; fields?: Array<Partial<ServerField>>; sections?: ReturnType<typeof serverSection>[] } = {}) {
    const fields = options.fields ?? [{ form_section_id: 's1' }, { form_section_id: 's1' }, { form_section_id: 's1' }, { form_section_id: 's2' }];
    const props = pageProps({
        sections: options.sections ?? [VISIT, LATER],
        fields: fields.map((f, i) =>
            serverField({ id: `f${i + 1}`, key: `q${i + 1}`, label: `Q${i + 1}`, sequence: i + 1, section_sequence: null, ...f }),
        ),
    });
    const store = useBuilderStore(props);
    wrapper = mount(PreviewPane, {
        props: { store, form: { ...props.form, single_page_mode: options.singlePage ?? true }, draft: props.draft, active: true },
        attachTo: document.body,
    });

    return { store, wrapper };
}

function pointAt(el: Element): void {
    document.elementFromPoint = vi.fn(() => el) as typeof document.elementFromPoint;
}

function section(w: VueWrapper, key: string): Element {
    return w.find(`[data-section-key="${key}"]`).element;
}

function grip(w: VueWrapper, key: string): HTMLElement {
    return w.find(`[data-preview-field="${key}"] [data-preview-grip]`).element as HTMLElement;
}

function pointer(target: EventTarget, type: string, y: number, pointerId = 1): void {
    target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, button: 0, pointerId, clientX: 10, clientY: y }));
}

function key(target: Element, name: string): void {
    target.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }));
}

/** The order the ENGINE shows, section by section — never the store's. */
function shown(w: VueWrapper): string[] {
    return w
        .findAll('[data-section]')
        .map((s) => `${s.attributes('data-section-key')}:${s.findAll('[data-preview-field]').map((r) => r.attributes('data-preview-field')).join(',')}`);
}

function said(w: VueWrapper): string {
    return w.find('.builder-preview__sr').text();
}

function reorders(): number {
    return requestLog(fetch).filter((r) => r.method === 'POST' && r.url.endsWith('/reorder')).length;
}

describe('a question dragged in the preview (M151)', () => {
    it('does nothing for a press and release on a grip', async () => {
        const { wrapper: w } = pane();
        await flushPromises();

        pointer(grip(w, 'q1'), 'pointerdown', 20);
        pointer(window, 'pointerup', 20);
        await flushPromises();

        expect(said(w)).toBe('');
        expect(reorders()).toBe(0);
    });

    it('draws where it would land and writes nothing while it moves, then writes once and shows the new order AT ONCE', async () => {
        const { wrapper: w } = pane();
        await flushPromises();
        pointAt(section(w, 'visit'));

        pointer(grip(w, 'q1'), 'pointerdown', 20);
        pointer(window, 'pointermove', 115);
        await flushPromises();

        expect(said(w)).toContain('Dragging Q1');
        expect(w.find('[data-preview-field="q3"]').classes()).toContain('preview__row--drop-after');
        expect(w.find('[data-preview-field="q1"]').classes()).toContain('preview__row--moving');
        expect(reorders()).toBe(0);
        expect(shown(w)).toEqual(['visit:q1,q2,q3', 'later:q4']);

        pointer(window, 'pointerup', 115);
        await flushPromises();

        // No timer has run: the move is on screen without waiting for the preview's debounce.
        expect(shown(w)).toEqual(['visit:q2,q3,q1', 'later:q4']);
        expect(reorders()).toBe(1);
        expect(said(w)).toContain('Dropped Q1');
    });

    it('moves a question into another section, above the question it is dropped on', async () => {
        const { wrapper: w } = pane();
        await flushPromises();
        pointAt(section(w, 'later'));

        pointer(grip(w, 'q1'), 'pointerdown', 0);
        pointer(window, 'pointermove', 10);
        pointer(window, 'pointerup', 10);
        await flushPromises();

        expect(shown(w)).toEqual(['visit:q2,q3', 'later:q1,q4']);
    });

    it('moves a question into a section with none yet, through its placeholder', async () => {
        const { wrapper: w } = pane({ sections: [VISIT, LATER, serverSection({ id: 's3', key: 'empty', label: 'Empty', sequence: 3 })] });
        await flushPromises();
        pointAt(w.find('[data-preview-empty-section]').element);

        pointer(grip(w, 'q1'), 'pointerdown', 0);
        pointer(window, 'pointermove', 10);
        pointer(window, 'pointerup', 10);
        await flushPromises();

        expect(shown(w)).toEqual(['visit:q2,q3', 'later:q4', 'empty:q1']);
        expect(w.find('[data-preview-empty-section]').exists()).toBe(false);
    });

    it('writes nothing when the browser cancels the pointer, and says so', async () => {
        const { wrapper: w } = pane();
        await flushPromises();
        pointAt(section(w, 'visit'));

        pointer(grip(w, 'q1'), 'pointerdown', 20);
        pointer(window, 'pointermove', 115);
        pointer(window, 'pointercancel', 115);
        await flushPromises();

        expect(said(w)).toBe('Reorder cancelled.');
        expect(reorders()).toBe(0);
        expect(w.find('.preview__row--drop-after').exists()).toBe(false);
    });

    it('keeps an answer the author typed to try the form out', async () => {
        const { wrapper: w } = pane();
        await flushPromises();
        await w.find('[data-preview-field="q2"] input').setValue('kept');
        pointAt(section(w, 'visit'));

        pointer(grip(w, 'q1'), 'pointerdown', 20);
        pointer(window, 'pointermove', 115);
        pointer(window, 'pointerup', 115);
        await flushPromises();

        expect(shown(w)).toEqual(['visit:q2,q3,q1', 'later:q4']);
        expect((w.find('[data-preview-field="q2"] input').element as HTMLInputElement).value).toBe('kept');
    });

    it('holds a rebuild that falls due while a question is moving, so the row under the pointer survives', async () => {
        vi.useFakeTimers();
        const { store, wrapper: w } = pane();
        await flushPromises();
        pointAt(section(w, 'visit'));

        const held = grip(w, 'q1');
        pointer(held, 'pointerdown', 20);
        pointer(window, 'pointermove', 115);
        store.fields.value.find((f) => f.key === 'q4')!.key = 'q4_renamed';
        await flushPromises();
        vi.advanceTimersByTime(PREVIEW_REBUILD_DEBOUNCE_MS * 4);
        await flushPromises();

        expect(held.isConnected).toBe(true);

        pointer(window, 'pointerup', 115);
        await flushPromises();
        expect(shown(w)).toEqual(['visit:q2,q3,q1', 'later:q4_renamed']);
    });

    it('ends an open label edit as a blur would when a drag begins', async () => {
        const { wrapper: w } = pane();
        await flushPromises();
        await w.find('[data-preview-field="q2"] [data-preview-edit-label]').trigger('click');
        await flushPromises();
        await w.find('input[aria-label="Question label"]').setValue('Renamed');
        pointAt(section(w, 'visit'));

        pointer(grip(w, 'q1'), 'pointerdown', 20);
        pointer(window, 'pointermove', 115);
        await flushPromises();

        expect(w.find('input[aria-label="Question label"]').exists()).toBe(false);
        expect(requestLog(fetch).some((r) => r.method === 'PATCH' && (r.body as { label?: string }).label === 'Renamed')).toBe(true);
        pointer(window, 'pointerup', 115);
    });
});

describe('a question moved in the preview by keyboard (M151)', () => {
    it('steps over the places a question can go without writing, drops once, and keeps focus on its grip', async () => {
        const { wrapper: w } = pane();
        await flushPromises();
        grip(w, 'q1').focus();

        key(grip(w, 'q1'), 'Enter');
        await flushPromises();
        expect(said(w)).toContain('Grabbed Q1');

        // Its own place, then "above Q2" — where it already is, so skipped — then above Q3.
        key(grip(w, 'q1'), 'ArrowDown');
        await flushPromises();
        expect(w.find('[data-preview-field="q3"]').classes()).toContain('preview__row--drop-before');
        expect(said(w)).toBe('above Q3.');
        expect(reorders()).toBe(0);

        key(grip(w, 'q1'), 'Enter');
        await flushPromises();

        expect(shown(w)).toEqual(['visit:q2,q1,q3', 'later:q4']);
        expect(reorders()).toBe(1);
        // A NEW grip: the engine host was rebuilt under it.
        expect(document.activeElement).toBe(grip(w, 'q1'));
    });

    it('writes nothing on Escape, or when its grip loses focus', async () => {
        const { wrapper: w } = pane();
        await flushPromises();

        grip(w, 'q1').focus();
        key(grip(w, 'q1'), 'Enter');
        key(grip(w, 'q1'), 'ArrowDown');
        key(grip(w, 'q1'), 'Escape');
        await flushPromises();
        expect(said(w)).toBe('Reorder cancelled.');

        key(grip(w, 'q1'), 'Enter');
        key(grip(w, 'q1'), 'ArrowDown');
        grip(w, 'q1').blur();
        await flushPromises();
        key(grip(w, 'q2'), 'Enter');
        await flushPromises();

        expect(reorders()).toBe(0);
        expect(said(w)).toContain('Grabbed Q2');
    });
});

describe('a stepped form: the pages in the strip are places to drop (M151, D105)', () => {
    it('offers each page while a question moves, and a drop there leaves the author on their page', async () => {
        const { wrapper: w } = pane({ singlePage: false });
        await flushPromises();
        expect(w.find('[data-preview-drop-strip]').exists()).toBe(false);

        pointer(grip(w, 'q1'), 'pointerdown', 20);
        pointAt(section(w, 'visit'));
        pointer(window, 'pointermove', 115);
        await flushPromises();

        const pages = w.findAll('[data-preview-drop-strip] li');
        expect(pages.map((p) => p.text())).toEqual(['1. Visit', '2. Later']);

        pointAt(pages[1].element);
        pointer(window, 'pointermove', 116);
        await flushPromises();
        expect(w.findAll('[data-preview-drop-strip] li')[1].classes()).toContain('preview__strip-drop-item--active');

        pointer(window, 'pointerup', 116);
        await flushPromises();

        expect(shown(w)).toEqual(['visit:q2,q3']);
        expect(w.find('[data-preview-drop-strip]').exists()).toBe(false);
        expect(said(w)).toBe('Dropped Q1, at the end of 2. Later.');
    });
});
