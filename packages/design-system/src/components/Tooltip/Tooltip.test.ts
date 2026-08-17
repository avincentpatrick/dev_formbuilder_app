import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Tooltip from './Tooltip.vue';
import Modal from '../Modal/Modal.vue';
import { anchorOffscreen, placeTooltip, TOOLTIP_OFFSET, VIEWPORT_PAD } from './position';

/**
 * MdsTooltip (DSR §3.4a, J4b).
 *
 * Two things carry this component and neither is visible in a rendered snapshot: it must consume Escape
 * before the dialog underneath it does, and its teleported bubble must survive the inert stack. Both fail
 * silently — nothing throws, nothing looks wrong, and the only symptom is a description no assistive
 * technology can reach. The suite is weighted accordingly.
 */

const VIEWPORT = { width: 1000, height: 800 };

/** happy-dom computes no layout, so every rect a component reads is zeros unless it is stubbed. */
function stubRect(el: Element, rect: { top: number; left: number; width: number; height: number }): void {
    el.getBoundingClientRect = () => ({
        ...rect,
        right: rect.left + rect.width,
        bottom: rect.top + rect.height,
        x: rect.left,
        y: rect.top,
        toJSON: () => ({}),
    }) as DOMRect;
}

const TriggerSlot = `<template #default="{ trigger }"><button v-bind="trigger">Forms</button></template>`;

function mountTooltip(props: Record<string, unknown> = {}) {
    return mount(Tooltip, {
        attachTo: document.body,
        props: { text: 'Forms', teleport: false, ...props },
        slots: { default: TriggerSlot },
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('MdsTooltip — WCAG 1.4.13: dismissible, hoverable, persistent', () => {
    it('is dismissed by Escape WITHOUT moving focus, and does not let the key reach a dialog behind it', async () => {
        // ⭐ THE MUTATION IS THE OBVIOUS IMPLEMENTATION. Copying `useDismissable`'s handler gives a
        // bubble-phase listener that returns focus to the trigger — and it passes every other case in this
        // file. It is wrong twice: MdsModal listens on its own panel, so a bubble-phase listener here runs
        // AFTER it and an Escape aimed at the tooltip closes the dialog instead; and moving focus to
        // dismiss a *description* relocates the reader, which 1.4.13 forbids in as many words.
        const outer = vi.fn();
        document.addEventListener('keydown', outer);

        const wrapper = mountTooltip();
        const button = wrapper.get('button').element as HTMLButtonElement;
        button.focus();
        await wrapper.get('.mds-tooltip-anchor').trigger('focusin');
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
        expect(document.activeElement).toBe(button);
        expect(outer).not.toHaveBeenCalled();

        document.removeEventListener('keydown', outer);
    });

    it('lets Escape THROUGH when it is only hovered and focus is somewhere else entirely', async () => {
        // ⭐ J4b1's FOURTH FINDING, as its measured reproduction. The listener is capture-phase on
        // `document`, so it ran before every other Escape claimant on the page whichever mechanism they
        // chose — including a `document` BUBBLE-phase listener, which never ran at all. At 481–1024px: rest
        // the pointer on a collapsed rail item with a shell popover open, press Escape, and the tooltip
        // vanished while the POPOVER STAYED OPEN. Confirmed in the browser against both shell mechanisms.
        //
        // `outer` is bubble-phase on `document` precisely because that is what `useDismissable` is — whose
        // consumers are the notification bell and the feedback button. (The account menu is NOT one: it
        // moved to `MdsMenu`, which binds its own root, and loses the key just the same.)
        const outer = vi.fn();
        document.addEventListener('keydown', outer);

        const elsewhere = document.createElement('button');
        document.body.appendChild(elsewhere);

        const wrapper = mountTooltip();
        await wrapper.get('.mds-tooltip-anchor').trigger('pointerenter', { pointerType: 'mouse' });
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);

        // The menu holds focus; the tooltip is merely under the pointer.
        elsewhere.focus();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        // Dismissal is unconditional (1.4.13) — only the CONSUMPTION is scoped, so both things happen.
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
        expect(outer).toHaveBeenCalledTimes(1);
        // And dismissing a description still must not relocate the reader.
        expect(document.activeElement).toBe(elsewhere);

        document.removeEventListener('keydown', outer);
        elsewhere.remove();
    });

    it('still consumes Escape when nothing at all holds focus, so a lone bubble is not a no-op', async () => {
        // ⭐ THE CONTROL FOR THE FIX. With focus on the document body element, nothing else can be claiming
        // the key (the tag name is named rather than written as a literal -- see Modal.vue's docblock: a
        // tag-shaped literal in a comment breaks the Storybook SFC parse, file-dependently, and this file
        // sits beside one that carries the hazard), so the
        // §3.4a sequence stands and the tooltip takes it. A fix that scoped consumption to "the anchor holds
        // focus" ALONE would break the pointer-only case, which is the commonest way a tooltip is seen.
        const outer = vi.fn();
        document.addEventListener('keydown', outer);

        const wrapper = mountTooltip();
        await wrapper.get('.mds-tooltip-anchor').trigger('pointerenter', { pointerType: 'mouse' });
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
        expect(outer).not.toHaveBeenCalled();

        document.removeEventListener('keydown', outer);
    });

    it('consumes Escape for a control inside the anchor, not merely for the trigger itself', async () => {
        // The anchor wraps the consumer's trigger, and a consumer may bind `trigger` onto something with its
        // own focusable descendants. `contains` rather than identity, so the rail's link-inside-a-wrapper
        // shape keeps §3.4a's sequence.
        const outer = vi.fn();
        document.addEventListener('keydown', outer);

        const wrapper = mountTooltip();
        const button = wrapper.get('button').element as HTMLButtonElement;
        button.focus();
        await wrapper.get('.mds-tooltip-anchor').trigger('focusin');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        expect(outer).not.toHaveBeenCalled();

        document.removeEventListener('keydown', outer);
    });

    it('stays dismissed while the pointer rests on the trigger, and shows again once it has left and returned', async () => {
        // Without the latch, Escape is a no-op the user watches fail: the bubble vanishes and the pointer
        // that never moved re-shows it on the next enter event.
        const wrapper = mountTooltip();
        const anchor = wrapper.get('.mds-tooltip-anchor');

        await anchor.trigger('pointerenter', { pointerType: 'mouse' });
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        await anchor.trigger('pointerenter', { pointerType: 'mouse' });
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);

        await anchor.trigger('pointerleave');
        await anchor.trigger('pointerenter', { pointerType: 'mouse' });
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);
    });

    it('survives the journey from trigger to bubble, and hides once both are left', async () => {
        vi.useFakeTimers();
        const wrapper = mountTooltip();
        const anchor = wrapper.get('.mds-tooltip-anchor');

        await anchor.trigger('pointerenter', { pointerType: 'mouse' });
        await anchor.trigger('pointerleave');

        // Mid-journey: the pointer has left the trigger and reached the bubble.
        await wrapper.get('[role="tooltip"]').trigger('pointerenter');
        vi.advanceTimersByTime(1000);
        await flushPromises();
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);

        await wrapper.get('[role="tooltip"]').trigger('pointerleave');
        vi.advanceTimersByTime(1000);
        await flushPromises();
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);

        vi.useRealTimers();
    });

    it('never auto-hides while the pointer is still on the trigger', async () => {
        // "Persistent" forbids a timer that dismisses while the user is engaged. The grace period is on
        // DISENGAGEMENT only, and this is the case that tells the two apart.
        vi.useFakeTimers();
        const wrapper = mountTooltip();

        await wrapper.get('.mds-tooltip-anchor').trigger('pointerenter', { pointerType: 'mouse' });
        vi.advanceTimersByTime(30_000);
        await flushPromises();

        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);
        vi.useRealTimers();
    });

    it('removes every document and window listener when it hides and when it unmounts', async () => {
        // A leaked capture-phase keydown listener in the shared happy-dom document silently eats Escape for
        // whatever mounts next, and the failure surfaces in an unrelated file.
        const removeDoc = vi.spyOn(document, 'removeEventListener');
        const removeWin = vi.spyOn(window, 'removeEventListener');

        const wrapper = mountTooltip();
        await wrapper.get('.mds-tooltip-anchor').trigger('pointerenter', { pointerType: 'mouse' });
        await wrapper.get('.mds-tooltip-anchor').trigger('focusout');

        expect(removeDoc).toHaveBeenCalledWith('keydown', expect.any(Function), true);
        expect(removeDoc).toHaveBeenCalledWith('scroll', expect.any(Function), true);
        expect(removeWin).toHaveBeenCalledWith('resize', expect.any(Function));

        removeDoc.mockClear();
        wrapper.unmount();
        expect(removeDoc).toHaveBeenCalledWith('keydown', expect.any(Function), true);

        removeDoc.mockRestore();
        removeWin.mockRestore();
    });
});

describe('MdsTooltip — it describes, it never names', () => {
    it('points aria-describedby at the bubble only while the bubble exists', async () => {
        // ⭐ TWO MUTATIONS, BOTH INVISIBLE TO EVERY GATE WE RUN. `aria-labelledby` instead of
        // `aria-describedby` makes the tooltip the accessible NAME, which §4.5 forbids outright. A
        // permanently-present `aria-describedby` dangles whenever the tooltip is hidden — and axe
        // downgrades a dangling describedby to *incomplete* rather than a violation, so the merge gate
        // stays green over it.
        const wrapper = mountTooltip();
        const button = wrapper.get('button');

        expect(button.attributes('aria-describedby')).toBeUndefined();
        expect(button.attributes('aria-labelledby')).toBeUndefined();

        await wrapper.get('.mds-tooltip-anchor').trigger('focusin');

        const described = button.attributes('aria-describedby');
        expect(described).toBeTruthy();
        expect(wrapper.get('[role="tooltip"]').attributes('id')).toBe(described);
        expect(button.attributes('aria-labelledby')).toBeUndefined();

        await wrapper.get('.mds-tooltip-anchor').trigger('focusout');
        expect(button.attributes('aria-describedby')).toBeUndefined();
    });

    it('renders nothing whatsoever while hidden', async () => {
        const wrapper = mountTooltip();
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
        expect(wrapper.text()).toBe('Forms');
    });

    it('carries the text as the bubble’s only content, under role="tooltip"', async () => {
        const wrapper = mountTooltip({ text: 'Response statistics' });
        await wrapper.get('.mds-tooltip-anchor').trigger('focusin');

        const bubble = wrapper.get('[role="tooltip"]');
        expect(bubble.text()).toBe('Response statistics');
        expect(bubble.element.children).toHaveLength(0);
    });
});

describe('MdsTooltip — it escapes its clipping ancestor, and stays reachable across a modal', () => {
    it('teleports to a DIRECT child of body carrying the inert exemption', async () => {
        // ⭐ Mutation targets: wrapping the bubble in a Transition, or moving the attribute onto an inner
        // node. Both render identically. The inert stack walks ancestor SIBLINGS, so an exempt node nested
        // inside a node it already marked inherits `inert` and the attribute does nothing at all.
        const wrapper = mount(Tooltip, {
            attachTo: document.body,
            props: { text: 'Forms', defaultVisible: true },
            slots: { default: TriggerSlot },
        });
        await flushPromises();

        const bubble = document.body.querySelector('[role="tooltip"]');
        expect(bubble).not.toBeNull();
        expect(bubble?.parentElement).toBe(document.body);
        expect(bubble?.hasAttribute('data-mds-inert-exempt')).toBe(true);

        wrapper.unmount();
    });

    it('is NOT marked inert by an open modal, while a plain body sibling is', async () => {
        // ⭐ THE INTEROP CASE, AND IT ASSERTS BOTH HALVES ON PURPOSE. Checking only that the bubble escaped
        // would also pass if the walk were broken and nothing anywhere got marked — which is precisely the
        // bug that would let every other exemption rot unnoticed. The control sibling proves the stack ran.
        const control = document.createElement('div');
        document.body.appendChild(control);

        const tooltip = mount(Tooltip, {
            attachTo: document.body,
            props: { text: 'Forms', defaultVisible: true },
            slots: { default: TriggerSlot },
        });
        const modal = mount(Modal, {
            attachTo: document.body,
            props: { open: true, title: 'Confirm' },
        });
        await flushPromises();

        const bubble = document.body.querySelector('[role="tooltip"]');
        expect(control.hasAttribute('inert')).toBe(true);
        expect(bubble?.closest('[inert]') ?? null).toBeNull();

        modal.unmount();
        tooltip.unmount();
        control.remove();
    });

    it('renders in place when teleport is disabled, which is what the axe stories need', async () => {
        const wrapper = mountTooltip({ defaultVisible: true });
        await flushPromises();

        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);
        expect(document.body.querySelector('[role="tooltip"]')?.parentElement).not.toBe(document.body);
    });
});

describe('MdsTooltip — placement is computed, and the geometry is pure', () => {
    const bubble = { width: 120, height: 40 };
    const centre = { top: 400, left: 400, width: 40, height: 40 };

    it('keeps the requested side when it fits', () => {
        expect(placeTooltip(centre, bubble, VIEWPORT, 'right').placement).toBe('right');
        expect(placeTooltip(centre, bubble, VIEWPORT, 'top').placement).toBe('top');
    });

    it('flips away from a viewport edge', () => {
        expect(placeTooltip({ ...centre, top: 4 }, bubble, VIEWPORT, 'top').placement).toBe('bottom');
        expect(placeTooltip({ ...centre, left: 950 }, bubble, VIEWPORT, 'right').placement).toBe('left');
    });

    it('does NOT flip when the opposite side is no better — it keeps the preferred side and clamps', () => {
        // ⭐ A naive `!fits ? opposite : preferred` sends the bubble to a side that is equally bad, so the
        // placement appears to jump at random as the viewport resizes. Narrower than the bubble on both
        // sides is the case that separates the two implementations.
        const narrow = { width: 130, height: 800 };
        const anchor = { top: 400, left: 40, width: 40, height: 40 };

        expect(placeTooltip(anchor, bubble, narrow, 'right').placement).toBe('right');
    });

    it('shifts along the cross axis so the bubble never crosses the edge pad', () => {
        const atLeft = placeTooltip({ top: 400, left: 0, width: 40, height: 40 }, bubble, VIEWPORT, 'top');
        expect(atLeft.left).toBe(VIEWPORT_PAD);

        const atRight = placeTooltip({ top: 400, left: 980, width: 40, height: 40 }, bubble, VIEWPORT, 'top');
        expect(atRight.left).toBe(VIEWPORT.width - bubble.width - VIEWPORT_PAD);
    });

    it('offsets by exactly TOOLTIP_OFFSET from the anchor', () => {
        expect(placeTooltip(centre, bubble, VIEWPORT, 'right').left).toBe(centre.left + centre.width + TOOLTIP_OFFSET);
        expect(placeTooltip(centre, bubble, VIEWPORT, 'top').top).toBe(centre.top - bubble.height - TOOLTIP_OFFSET);
    });

    it('reports the anchor offscreen only once it has left entirely', () => {
        expect(anchorOffscreen({ top: -40, left: 400, width: 40, height: 40 }, VIEWPORT)).toBe(true);
        expect(anchorOffscreen({ top: -20, left: 400, width: 40, height: 40 }, VIEWPORT)).toBe(false);
        expect(anchorOffscreen({ top: 400, left: 1000, width: 40, height: 40 }, VIEWPORT)).toBe(true);
    });

    it('clamps the MAIN axis when neither side fits, not just the cross axis', () => {
        // ⭐ FOUND BY THE ADVERSARIAL PASS, AND THE DOCSTRING ALREADY PROMISED IT. When both sides are too
        // tight the preferred one is kept — and without clamping the main axis the bubble is placed off
        // screen. Being `position: fixed` it is then simply cut off: no scrollbar, and nothing the
        // document-level overflow assertion can see. The flip case above asserts the PLACEMENT string, so
        // it passes straight over this; only the coordinate catches it.
        // Neither side has room for bubble + offset + pad, so the preferred side is kept and the
        // coordinate must still land inside the glass.
        const bubble = { width: 120, height: 40 };

        const tight = { width: 260, height: 800 };
        const midRight = { top: 400, left: 100, width: 40, height: 40 };
        const horizontal = placeTooltip(midRight, bubble, tight, 'right');
        expect(horizontal.placement).toBe('right');
        expect(horizontal.left).toBeGreaterThanOrEqual(VIEWPORT_PAD);
        expect(horizontal.left + bubble.width).toBeLessThanOrEqual(tight.width - VIEWPORT_PAD);

        const short = { width: 1000, height: 120 };
        const vertical = placeTooltip({ top: 50, left: 400, width: 40, height: 40 }, bubble, short, 'top');
        expect(vertical.placement).toBe('top');
        expect(vertical.top).toBeGreaterThanOrEqual(VIEWPORT_PAD);
        expect(vertical.top + bubble.height).toBeLessThanOrEqual(short.height - VIEWPORT_PAD);

        // And when the bubble is genuinely wider than the viewport allows, the readable edge wins rather
        // than the overflow being split across both sides — which is what `clamp` documents.
        const impossible = placeTooltip(midRight, { width: 400, height: 40 }, tight, 'right');
        expect(impossible.left).toBe(VIEWPORT_PAD);
    });

    it('treats an UNMEASURED anchor as present, not as absent', () => {
        // ⭐ THE REGRESSION THIS SUITE ACTUALLY CAUGHT. An all-zeros rect satisfies `top + height <= 0`,
        // so without the zero-area guard the tooltip shows, measures on the next tick, decides its own
        // trigger is off screen, and hides in the same frame — it never appears for any user action.
        // ⚠️ And it hid from the cheap tests: every case that seeds visibility directly passed, because
        // nothing measures on that path. Seeding visibility is what the axe stories do, so the
        // accessibility gate would have been green over a tooltip nobody could open.
        expect(anchorOffscreen({ top: 0, left: 0, width: 0, height: 0 }, VIEWPORT)).toBe(false);
    });

    it('hides itself once its anchor has scrolled out of view', async () => {
        const wrapper = mountTooltip();
        const anchor = wrapper.get('.mds-tooltip-anchor');
        stubRect(anchor.element, { top: 400, left: 400, width: 40, height: 40 });

        await anchor.trigger('pointerenter', { pointerType: 'mouse' });
        await flushPromises();
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);

        stubRect(anchor.element, { top: -200, left: 400, width: 40, height: 40 });
        document.dispatchEvent(new Event('scroll'));
        await flushPromises();

        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
    });
});

describe('MdsTooltip — touch has no hover', () => {
    it('ignores a touch pointerenter but still shows on focus', async () => {
        // ⭐ On touch this event is the first half of a tap, not a hover. A bubble that appears and is torn
        // down by the navigation it preceded is worse than none, and it is why §3.4a forbids a tooltip
        // being the sole carrier of anything on touch.
        const wrapper = mountTooltip();
        const anchor = wrapper.get('.mds-tooltip-anchor');

        await anchor.trigger('pointerenter', { pointerType: 'touch' });
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);

        await anchor.trigger('focusin');
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);
    });
});

describe('MdsTooltip — the paint-order contract, held in source text', () => {
    const root = process.cwd();
    const source = readFileSync(
        join(root, 'packages/design-system/src/components/Tooltip/Tooltip.vue'),
        'utf8',
    );

    // ⚠️ Scoped to the stylesheet, never the whole file: this component's docblock discusses the very
    // tokens and idioms asserted below, and a whole-file negative would match the prose written to justify
    // them. Three gates in this repo have already failed on exactly that.
    const stylesheet = source.slice(source.indexOf('<style'));

    it('is fixed-positioned and takes its layer from the scale, with a literal fallback', () => {
        // ⭐ The fallback is the only control on a failure no gate can see: `dist/` is gitignored, so a dev
        // who has not built tokens gets an invalid declaration, `z-index` computes to `auto`, and the
        // bubble paints UNDER an open dialog. CI always builds tokens first, which is why CI is blind here.
        expect(stylesheet).toMatch(/position:\s*fixed/);
        expect(stylesheet).toMatch(/z-index:\s*var\(--mds-z-index-tooltip,\s*1050\)/);
    });

    it('keeps the z-index scale and the inert stack telling the same story', () => {
        // ⭐ The scale is a JSON file describing numbers that live in TypeScript and CSS. Nothing but this
        // assertion stands between it and becoming documentation that lies with authority.
        const scale = JSON.parse(
            readFileSync(join(root, 'packages/design-system/tokens/z-index.json'), 'utf8'),
        ) as { 'z-index': Record<string, { value: string }> };

        const inertStack = readFileSync(
            join(root, 'packages/design-system/src/components/Modal/inert-stack.ts'),
            'utf8',
        );
        const modalSfc = readFileSync(
            join(root, 'packages/design-system/src/components/Modal/Modal.vue'),
            'utf8',
        );

        // The stack raises each open backdrop from a TypeScript constant while the stylesheet names the
        // token — two spellings of one rung, and this is the only thing holding them together.
        const base = inertStack.match(/BASE_Z_INDEX\s*=\s*(\d+)/)?.[1];
        expect(base).toBe(scale['z-index'].modal.value);
        expect(modalSfc.slice(modalSfc.indexOf('<style'))).toMatch(
            new RegExp(`z-index:\\s*var\\(--mds-z-index-modal,\\s*${scale['z-index'].modal.value}\\)`),
        );

        expect(Number(scale['z-index'].tooltip.value)).toBeGreaterThan(Number(scale['z-index'].modal.value));
        expect(Number(scale['z-index'].tooltip.value)).toBeLessThan(Number(scale['z-index'].toast.value));
    });

    it('bridges the gap on all four sides, at exactly the offset the geometry uses', () => {
        // ⭐ If the bridge and the offset drift apart, a pointer travelling to the bubble crosses a dead
        // zone and the tooltip dismisses itself mid-journey — which reads as a flaky hover rather than as
        // two numbers that stopped matching.
        for (const placement of ['top', 'right', 'bottom', 'left']) {
            const rule = stylesheet.match(
                new RegExp(`\\[data-placement='${placement}'\\]::before\\s*\\{([^}]*)\\}`),
            )?.[1];
            expect(rule).toBeTruthy();
            expect(rule).toMatch(new RegExp(`(width|height):\\s*${TOOLTIP_OFFSET}px`));
            expect(rule).toMatch(new RegExp(`-${TOOLTIP_OFFSET}px`));
        }
    });

    it('wears the measured popover surface and the popover elevation', () => {
        // The same pairing AccountMenu and NotificationBell already use, so `theme-overrides.test.ts`'s
        // contrast measurements cover this component in both themes. An inverted dark chip — the classic
        // tooltip look — would be a brand-new unmeasured pairing.
        expect(stylesheet).toMatch(/box-shadow:\s*var\(--mds-shadow-3\)/);
        expect(stylesheet).toMatch(/background-color:\s*var\(--mds-color-bg-surface-raised\)/);
        expect(stylesheet).toMatch(/color:\s*var\(--mds-color-text-body\)/);
        expect(stylesheet).toMatch(/border:\s*1px solid var\(--mds-color-border-default\)/);
    });

    it('reuses an existing type role rather than minting a twelfth', () => {
        // `type-scale.test.ts` asserts there are exactly eleven, and each needs four declarations per
        // font-size level. A tooltip is a label; `body-sm` is what the expanded rail label already uses.
        expect(stylesheet).toMatch(/font-size:\s*var\(--mds-type-body-sm-font-size\)/);
        expect(stylesheet).toMatch(/line-height:\s*var\(--mds-type-body-sm-line-height\)/);
    });

    it('hides nothing with the clip idiom, so it needs no containing-block guard', () => {
        expect(stylesheet).not.toContain('clip: rect(0 0 0 0)');
    });
});
