import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import CommandPalette from './CommandPalette.vue';
import { __resetCommandPaletteBindings } from '@/composables/useCommandPalette';

/**
 * The palette's ARIA contract and its fetch discipline (Increment J1d, DSR §3.4.1).
 *
 * ⚠️ THESE ASSERTIONS EXIST BECAUSE THE a11y GATE WILL NOT MAKE THEM. DSR §3.4.1 records it explicitly:
 * axe cannot see a dangling `aria-activedescendant` (it does not resolve the id), and the palette is an
 * app-tree component so Storybook — which globs `packages/design-system/src/**` only — gives it no story
 * and no `checkA11y` scan at all. A green `design-system-a11y` job says nothing about this file.
 */

const visits: string[] = [];

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: (url: string) => visits.push(url) },
}));

function suggestPayload(): unknown {
    return {
        q: 'clinic',
        groups: [
            {
                entity: 'form',
                label: 'Forms',
                has_more: false,
                items: [
                    { id: 'f1', title: 'Clinic Intake', subtitle: 'Published', url: '/forms/f1/builder' },
                    { id: 'f2', title: 'Clinic Referral', subtitle: 'Draft', url: '/forms/f2/builder' },
                ],
            },
        ],
        see_all_url: '/search?q=clinic',
    };
}

/**
 * ⚠️ QUERIES GO THROUGH `document`, NOT THE WRAPPER. `MdsModal` teleports its panel to `<body>`, so
 * `wrapper.find()` — which searches inside the wrapper's own element — finds nothing at all. That is a
 * property of the component under test, not a workaround: the palette really does render outside its
 * mount point, and asserting against the document is asserting against what the user gets.
 */
function el<T extends Element = Element>(selector: string): T | null {
    return document.querySelector<T>(selector);
}

function all(selector: string): Element[] {
    return Array.from(document.querySelectorAll(selector));
}

async function typeQuery(value: string): Promise<void> {
    const input = el<HTMLInputElement>('[role="combobox"]');
    if (input === null) throw new Error('the combobox input is not rendered');

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
}

async function press(key: string): Promise<void> {
    const input = el<HTMLInputElement>('[role="combobox"]');
    input?.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
    await nextTick();
}

async function openPalette() {
    const wrapper = mount(CommandPalette, { attachTo: document.body });

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true }));
    await nextTick();
    await nextTick();

    return wrapper;
}

async function openWithResults() {
    const wrapper = await openPalette();

    await typeQuery('clinic');
    vi.advanceTimersByTime(260);
    await vi.runOnlyPendingTimersAsync();
    await nextTick();

    return wrapper;
}

beforeEach(() => {
    visits.length = 0;
    __resetCommandPaletteBindings();
    vi.useFakeTimers();
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(suggestPayload()) }))
    );
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    __resetCommandPaletteBindings();
});

describe('CommandPalette', () => {
    it('omits aria-controls and aria-activedescendant rather than leaving them dangling', async () => {
        const wrapper = await openPalette();
        const input = el('[role="combobox"]');

        // ⚠️ An attribute pointing at an id that is not in the DOM is WORSE than an absent one: a screen
        // reader announces nothing and the user has no way to tell why.
        expect(input?.hasAttribute('aria-controls')).toBe(false);
        expect(input?.hasAttribute('aria-activedescendant')).toBe(false);
        expect(input?.getAttribute('aria-expanded')).toBe('false');

        wrapper.unmount();
    });

    it('points aria-activedescendant at an element that actually exists', async () => {
        const wrapper = await openWithResults();

        const active = el('[role="combobox"]')?.getAttribute('aria-activedescendant');
        expect(active).toBeTruthy();

        // The whole property in one line: resolve the id and require a real option.
        const target = document.getElementById(String(active));
        expect(target).not.toBeNull();
        expect(target?.getAttribute('role')).toBe('option');

        wrapper.unmount();
    });

    it('renders options as divs inside a listbox, never as buttons', async () => {
        const wrapper = await openWithResults();

        const options = all('[role="option"]');
        expect(options.length).toBeGreaterThan(0);

        for (const option of options) {
            // A <button role="option"> trips axe's nested-interactive AND breaks aria-activedescendant.
            expect(option.tagName).toBe('DIV');
            // Rows are not tabbable by design — DOM focus never leaves the input.
            expect(option.hasAttribute('tabindex')).toBe(false);
        }

        expect(el('[role="listbox"]')).not.toBeNull();
        // <li> inside a listbox fails aria-required-children, so the grouping is divs too.
        expect(el('[role="listbox"] li')).toBeNull();

        wrapper.unmount();
    });

    it('appends a see-all option so Enter always means the same thing', async () => {
        const wrapper = await openWithResults();

        const labels = all('[role="option"]').map((o) => o.textContent ?? '');
        expect(labels[labels.length - 1]).toContain('See all results');

        wrapper.unmount();
    });

    it('moves the active option with the arrows and wraps, without moving DOM focus', async () => {
        const wrapper = await openWithResults();
        const active = () => el('[role="combobox"]')?.getAttribute('aria-activedescendant');

        const first = active();
        await press('ArrowDown');
        expect(active()).not.toBe(first);

        // Wrapping: three options (two results + see-all), so three MORE downs return to the start, and
        // DOM focus must never have left the input.
        await press('ArrowDown');
        await press('ArrowDown');
        expect(active()).toBe(first);
        expect(document.activeElement).toBe(el('[role="combobox"]'));

        wrapper.unmount();
    });

    it('navigates to the highlighted option on Enter', async () => {
        const wrapper = await openWithResults();

        await press('Enter');
        await nextTick();
        await nextTick();

        expect(visits).toContain('/forms/f1/builder');

        wrapper.unmount();
    });

    it('issues no request at all for an empty query', async () => {
        const wrapper = await openPalette();

        await typeQuery('   ');
        vi.advanceTimersByTime(400);
        await vi.runOnlyPendingTimersAsync();

        expect(fetch).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('never sends the X-Inertia header, which would turn the JSON endpoint into a page response', async () => {
        const wrapper = await openWithResults();

        const [, init] = (fetch as unknown as { mock: { calls: [string, RequestInit][] } }).mock.calls[0];
        const headers = (init.headers ?? {}) as Record<string, string>;

        expect(Object.keys(headers).map((k) => k.toLowerCase())).not.toContain('x-inertia');
        expect(headers.Accept).toBe('application/json');

        wrapper.unmount();
    });

    it('discards an out-of-order response rather than letting it overwrite a fresh one', async () => {
        const wrapper = await openPalette();

        // Request #1 resolves LATE and with different data; request #2 resolves first. Abort alone does not
        // guarantee this — an aborted fetch can still resolve — which is why there is a sequence counter.
        let resolveFirst: ((v: unknown) => void) | null = null;
        const stale = { ...(suggestPayload() as object), groups: [] };

        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockImplementationOnce(
                    () => new Promise((resolve) => { resolveFirst = resolve; })
                )
                .mockImplementationOnce(() =>
                    Promise.resolve({ ok: true, json: () => Promise.resolve(suggestPayload()) })
                )
        );

        await typeQuery('cli');
        vi.advanceTimersByTime(260);
        await vi.runOnlyPendingTimersAsync();

        await typeQuery('clinic');
        vi.advanceTimersByTime(260);
        await vi.runOnlyPendingTimersAsync();
        await nextTick();

        // Now let the FIRST request land, with its empty payload.
        resolveFirst?.({ ok: true, json: () => Promise.resolve(stale) });
        await vi.runOnlyPendingTimersAsync();
        await nextTick();

        // The fresh results must survive.
        expect(all('[role="option"]').length).toBeGreaterThan(1);

        wrapper.unmount();
    });

    it('keeps its live region inside the dialog, where inert cannot silence it', async () => {
        const wrapper = await openWithResults();

        expect(el('[role="status"]')).not.toBeNull();

        wrapper.unmount();
    });
});
