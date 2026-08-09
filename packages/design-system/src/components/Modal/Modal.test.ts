import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Modal from './Modal.vue';
import { openModalCount, popModalRoot, pushModalRoot } from './inert-stack';

/**
 * Increment I10a — `MdsModal` marks the rest of the page `inert` while open, closing the WCAG 2.4.3 row
 * filed from I8c (`docs/feature-backlog.md:109`).
 *
 * There was no Modal spec before this file, which is part of why the defect lasted: the Tab trap is a
 * `keydown` handler on the panel, so it only ever sees Tab events that are ALREADY inside the dialog, and
 * nothing anywhere asserted what happens to the page behind it.
 *
 * These assert the DOM contract, not the visual one. `inert` is read as an ATTRIBUTE throughout, which is
 * what axe-core reads. Every case attaches to the real `document.body` except the `#storybook-root` one,
 * which attaches to a `<div>` precisely because its whole point is that the mount target must survive.
 */

/** A body-level element standing in for "the app" — the thing that must go inert. */
function appNode(): HTMLElement {
    const el = document.createElement('div');
    el.id = 'app';
    el.innerHTML = '<button type="button">background action</button>';
    document.body.appendChild(el);
    return el;
}

type Wrapper = ReturnType<typeof mount>;
let mounted: Wrapper[] = [];

function openModal(extra: Record<string, unknown> = {}): Wrapper {
    const wrapper = mount(Modal, {
        attachTo: document.body,
        props: { open: true, title: 'Remove member', ...extra },
    });
    mounted.push(wrapper);
    return wrapper;
}

/**
 * `inert` is asserted through `closest('[inert]')`, not `hasAttribute` on the backdrop.
 *
 * @vue/test-utils STUBS `<Transition>` as a real `transition-stub` ELEMENT, so under test the backdrop is
 * a grandchild of the teleport target where in a real browser it is a direct child (a real Transition
 * renders no wrapper). The walk is correct in both shapes — it marks the topmost node on the path's
 * siblings either way — but an assertion pinned to the backdrop itself would be pinned to the stub.
 */
function isBackground(el: Element): boolean {
    return el.closest('[inert]') !== null;
}

beforeEach(() => {
    mounted = [];
    document.body.innerHTML = '';
    document.body.style.overflow = '';
});

afterEach(() => {
    // Unmount FIRST so a mid-test assertion failure cannot leak a stack entry into the next case and turn
    // one real failure into a cascade of three. The count check below still catches a genuine leak,
    // because releasing on unmount is itself the property under test.
    for (const wrapper of mounted) wrapper.unmount();
    // A leaked entry would silently blank the NEXT spec file's assertions — specs share one happy-dom
    // document, so this is a guard against cross-file pollution, not ceremony.
    expect(openModalCount()).toBe(0);
    document.body.innerHTML = '';
    document.body.style.overflow = '';
});

describe('MdsModal — inert background', () => {
    it('marks a body-level sibling inert while open, and no ancestor of the dialog', async () => {
        const app = appNode();
        const wrapper = openModal();
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(true);

        const backdrop = document.querySelector('.mds-modal__backdrop');
        expect(backdrop).not.toBeNull();
        // The dialog itself, and everything above it, must stay reachable — otherwise the modal inerts
        // itself and the whole component is a no-op with extra steps.
        expect(isBackground(backdrop as Element)).toBe(false);
        expect(document.body.hasAttribute('inert')).toBe(false);

        void wrapper;
    });

    it('does NOT inert the wrapper the dialog renders inside when teleport is off', async () => {
        // THE `#storybook-root` CASE. Every Storybook story and four app specs render with teleport:false,
        // so the backdrop is a CHILD of the story/test root rather than a body sibling. The naive rule
        // ("inert every body child except our backdrop") would mark that root — and `checkA11y(page,
        // '#storybook-root')` would then scan an empty accessibility tree and PASS SILENTLY. A green a11y
        // job over nothing is worse than a red one, so this is the highest-value case in the file.
        const root = document.createElement('div');
        root.id = 'storybook-root';
        document.body.appendChild(root);

        const sibling = document.createElement('p');
        sibling.textContent = 'story background';
        root.appendChild(sibling);

        mounted.push(
            mount(Modal, {
                attachTo: root,
                props: { open: true, title: 'Remove member', teleport: false },
            }),
        );
        await flushPromises();

        expect(root.hasAttribute('inert')).toBe(false);
        expect(sibling.hasAttribute('inert')).toBe(true);
        expect(isBackground(document.querySelector('.mds-modal__backdrop') as Element)).toBe(false);
    });

    it('never marks an element that opts out with data-mds-inert-exempt', async () => {
        // ToastHost teleports to <body> DELIBERATELY so a toast is not trapped under a modal. Inerting it
        // would remove it from the accessibility tree, so a toast raised while a dialog is open would be
        // announced to nobody and clickable by nobody.
        const app = appNode();
        const toasts = document.createElement('div');
        toasts.setAttribute('data-mds-inert-exempt', '');
        document.body.appendChild(toasts);

        openModal();
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(true);
        expect(toasts.hasAttribute('inert')).toBe(false);
    });

    it('releases only what it marked, leaving a consumer’s own inert attribute alone', async () => {
        const app = appNode();
        const alreadyInert = document.createElement('div');
        alreadyInert.setAttribute('inert', '');
        document.body.appendChild(alreadyInert);

        const wrapper = openModal();
        await flushPromises();
        await wrapper.setProps({ open: false });
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(false);
        // Cleaning up via querySelectorAll('[inert]') would strip this and the consumer would never know.
        expect(alreadyInert.hasAttribute('inert')).toBe(true);
    });

    it('releases inert BEFORE returning focus to the opener', async () => {
        // Ordering, not end state: focus() on an element inside an inert subtree is a SILENT no-op, so the
        // reverse order breaks return-focus in every modal in the product with nothing in the console.
        // Asserting `document.activeElement` afterwards would not redden — happy-dom will happily focus an
        // inert element — so this records what the page looked like at the instant focus() was called.
        const app = appNode();
        const opener = app.querySelector('button') as HTMLButtonElement;
        opener.focus();
        expect(document.activeElement).toBe(opener);

        let backgroundInertWhenFocused: boolean | null = null;
        opener.focus = () => {
            backgroundInertWhenFocused = app.hasAttribute('inert');
        };

        const wrapper = openModal();
        await flushPromises();
        await wrapper.setProps({ open: false });
        await flushPromises();

        expect(backgroundInertWhenFocused).toBe(false);
    });

    it('releases inert when unmounted while still open', async () => {
        const app = appNode();
        const wrapper = openModal();
        await flushPromises();
        expect(app.hasAttribute('inert')).toBe(true);

        wrapper.unmount();
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(false);
    });

    it('takes the page when `open` is toggled false → true, the path every consumer actually uses', async () => {
        // Every other case here mounts with open:true, which only exercises the watcher's `immediate` first
        // run. All 29 production consumers bind `:open` to reactive state and toggle it — so without this
        // case the ordinary open path had no coverage at all.
        const app = appNode();
        const wrapper = mount(Modal, {
            attachTo: document.body,
            props: { open: false, title: 'Remove member' },
        });
        mounted.push(wrapper);
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(false);
        expect(document.body.style.overflow).toBe('');

        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');
        expect(isBackground(document.querySelector('.mds-modal__backdrop') as Element)).toBe(false);
    });

    it('returns focus when the modal closes by UNMOUNTING rather than by `open` going false', async () => {
        // `v-if="x" :open="true"` unmounts instead of closing, and two live consumers use it
        // (forms/Index.vue's AssignScopeModal, scopes/Index.vue's move confirm). Releasing inert without
        // returning focus would strand the user on <body> — DSR §4.5 forbids exactly that.
        const app = appNode();
        const opener = app.querySelector('button') as HTMLButtonElement;
        opener.focus();
        expect(document.activeElement).toBe(opener);

        let refocused = false;
        opener.focus = () => {
            refocused = true;
        };

        const wrapper = openModal();
        await flushPromises();

        wrapper.unmount();
        await flushPromises();

        expect(refocused).toBe(true);
        expect(app.hasAttribute('inert')).toBe(false);
    });

    it('applies the lock on a modal that is mounted ALREADY open', async () => {
        // `v-if="x" :open="true"` is a live pattern (forms/Index.vue's AssignScopeModal, scopes/Index.vue's
        // move confirm) and it is how EVERY Storybook story renders. Without `immediate: true` on the
        // watcher those get no inert, no scroll lock and no initial focus — and the axe story would be
        // green over a no-op.
        const app = appNode();
        openModal();
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');
        expect(document.activeElement?.closest('.mds-modal__panel')).not.toBeNull();
    });
});

describe('MdsModal — stacking', () => {
    it('inerts only the topmost dialog’s background, including the dialog beneath it', async () => {
        // Builder.vue's ConflictDialog is ALWAYS mounted and can open from a background PATCH while the
        // schedule / confirm / import modal is up, so this is reachable rather than theoretical.
        const app = appNode();
        const lower = openModal({ title: 'Unsaved changes' });
        await flushPromises();
        const lowerBackdrop = document.querySelector('.mds-modal__backdrop') as HTMLElement;

        const upper = openModal({ title: 'Version conflict' });
        await flushPromises();

        expect(openModalCount()).toBe(2);
        expect(app.hasAttribute('inert')).toBe(true);
        // Matching native <dialog>: the dialog underneath is background too.
        expect(isBackground(lowerBackdrop)).toBe(true);

        await upper.setProps({ open: false });
        await flushPromises();

        // The lower dialog gets the page back; the page itself stays inert. A boolean instead of a stack
        // would have released everything here.
        expect(isBackground(lowerBackdrop)).toBe(false);
        expect(app.hasAttribute('inert')).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');

        await lower.setProps({ open: false });
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(false);
        expect(document.body.style.overflow).toBe('');
    });

    it('inerts by PAINT order, not declaration order, when the later-declared modal opens first', async () => {
        // ⚠️ THE REGRESSION TEST FOR THIS INCREMENT'S OWN WORST DEFECT, found by adversarial review and not
        // by the suite. A teleported modal's position in <body> is frozen at MOUNT time by template
        // declaration order — Vue appends the Teleport's anchors once and never re-anchors — while the stack
        // is ordered by OPEN time. Builder.vue declares ConflictDialog first and ShareModal fifth and mounts
        // both unconditionally, so a 409 landing while Share is open opens the conflict dialog second: later
        // in the stack, earlier in the DOM. At a shared z-index paint order is DOM order, so the naive stack
        // would have inerted the dialog the user is looking at and left the invisible one live.
        //
        // The case below is that exact shape: `first` is declared/mounted first but opened SECOND. Note the
        // other stacking case cannot catch this — it mounts both already open, so the two orders agree.
        const app = appNode();
        const first = openModal({ open: false, title: 'Version conflict' }); // declared first, opens second
        const second = openModal({ open: false, title: 'Share form' }); // declared second, opens first
        await flushPromises();

        await second.setProps({ open: true });
        await flushPromises();
        await first.setProps({ open: true });
        await flushPromises();

        const backdrops = Array.from(
            document.querySelectorAll<HTMLElement>('.mds-modal__backdrop'),
        );
        expect(backdrops).toHaveLength(2);

        // DOM order is still declaration order — that is the premise, and it is what makes this hard.
        const [declaredFirst, declaredSecond] = backdrops;
        expect(declaredFirst.querySelector('.mds-modal__title')?.textContent).toBe('Version conflict');

        // The LAST-OPENED dialog must be the live one and must paint on top.
        expect(isBackground(declaredFirst)).toBe(false);
        expect(isBackground(declaredSecond)).toBe(true);
        expect(Number(declaredFirst.style.zIndex)).toBeGreaterThan(Number(declaredSecond.style.zIndex));
        expect(app.hasAttribute('inert')).toBe(true);

        await first.setProps({ open: false });
        await flushPromises();

        // Handing back down: the remaining dialog becomes live again and its raise is dropped to the base.
        expect(isBackground(declaredSecond)).toBe(false);
        expect(declaredSecond.style.zIndex).toBe('1000');
    });

    it('ignores a re-push of a root already on the stack', async () => {
        const app = appNode();
        const wrapper = openModal();
        await flushPromises();
        const root = document.querySelector('.mds-modal__backdrop') as HTMLElement;

        pushModalRoot(root);
        pushModalRoot(root);

        // Without the includes() guard the stack would hold three entries and the single pop on close would
        // leave the page inert forever with no modal on screen.
        expect(openModalCount()).toBe(1);

        await wrapper.setProps({ open: false });
        await flushPromises();
        expect(app.hasAttribute('inert')).toBe(false);
    });

    it('ignores a pop of a root that is not on the stack, rather than releasing the topmost', async () => {
        // splice(-1, 1) removes the LAST element, so dropping the `at === -1` guard turns a stray pop into a
        // silent release of whichever modal is currently on top.
        const app = appNode();
        openModal();
        await flushPromises();

        popModalRoot(document.createElement('div'));

        expect(openModalCount()).toBe(1);
        expect(app.hasAttribute('inert')).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');
    });

    it('empties its bookkeeping on release, so a later consumer inert is never stripped', async () => {
        // The sibling case plants `inert` BEFORE opening, so that element is never claimed. This covers the
        // other half: an element we DID mark must leave the `marked` set on release, or a later release
        // would strip an attribute the consumer set in the meantime.
        const app = appNode();
        const wrapper = openModal();
        await flushPromises();
        expect(app.hasAttribute('inert')).toBe(true);

        await wrapper.setProps({ open: false });
        await flushPromises();
        expect(app.hasAttribute('inert')).toBe(false);

        // The consumer now owns it. A second open/close cycle must leave that alone.
        app.setAttribute('inert', '');
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.setProps({ open: false });
        await flushPromises();

        expect(app.hasAttribute('inert')).toBe(true);
    });

    it('keeps the scroll lock while any modal is still open', async () => {
        appNode();
        const first = openModal({ title: 'First' });
        const second = openModal({ title: 'Second' });
        await flushPromises();

        expect(document.body.style.overflow).toBe('hidden');

        // The pre-I10a bug this fixes for free: the FIRST close cleared the lock outright.
        await first.setProps({ open: false });
        await flushPromises();
        expect(document.body.style.overflow).toBe('hidden');

        await second.setProps({ open: false });
        await flushPromises();
        expect(document.body.style.overflow).toBe('');
    });
});

/**
 * Increment J1a — `initialFocus`, the designated initial-focus target DSR §4.5 has specified since this
 * component was written and which had never been built.
 *
 * ⚠️ THE DEFAULT IS THE DEFECT, AND THESE CASES PIN IT IN BOTH DIRECTIONS. `focusable()` queries the panel
 * in DOM order and `<header>` — which carries `.mds-modal__close` — precedes `.mds-modal__body`, so every
 * modal in the product opens focused on its own Close button. The first case below asserts that is still
 * true when the prop is absent (a behaviour change there would be a silent regression across ~10 live
 * dialogs), and the rest assert the opt-in.
 *
 * Neither the Storybook story nor any axe scan can reach this: the runner never presses a key, and a broken
 * `initialFocus` renders identical markup. Vitest is the only gate that can see it.
 */
describe('MdsModal — initialFocus', () => {
    function openWithInput(extra: Record<string, unknown> = {}): Wrapper {
        const wrapper = mount(Modal, {
            attachTo: document.body,
            props: { open: true, title: 'Search', ...extra },
            slots: {
                default: '<input data-mds-initial-focus type="search" aria-label="Search" />',
                actions: '<button type="button">Go</button>',
            },
        });
        mounted.push(wrapper);
        return wrapper;
    }

    it('focuses the designated target rather than the close button', async () => {
        openWithInput({ initialFocus: '[data-mds-initial-focus]' });
        await flushPromises();

        // Asserted by ROLE-bearing attributes rather than tag name: `getAttribute('type')` would pass for a
        // `<button type="search">`, which is not a thing, but the pairing here is what the palette needs.
        const active = document.activeElement as HTMLElement | null;
        expect(active?.hasAttribute('data-mds-initial-focus')).toBe(true);
        expect(active?.classList.contains('mds-modal__close')).toBe(false);
    });

    it('falls back to the pre-J1a target when the selector matches nothing', async () => {
        // A consumer whose slot content is v-if'd away must get a focused dialog, never a stranded one.
        openWithInput({ initialFocus: '[data-does-not-exist]' });
        await flushPromises();

        expect(document.activeElement?.closest('.mds-modal__panel')).not.toBeNull();
        expect(document.activeElement?.hasAttribute('data-mds-initial-focus')).toBe(false);
    });

    it('without the prop, focuses the CLOSE BUTTON — right for a confirm, wrong for an input', async () => {
        // Both the regression guard and the proof of the docblock's claim. `focusable()` walks the panel in
        // DOM order and `<header>` precedes `.mds-modal__body`, so the close button wins — even in a dialog
        // whose entire point is the input below it.
        //
        // Worth pinning rather than "fixing": for a destructive confirmation this is exactly what DSR §4.5
        // asks for (a cancel-shaped target, so a stray Enter cannot confirm), and ~10 live dialogs rely on
        // it. `initialFocus` opts a dialog out; it must never become the default.
        //
        // This is assertable here only because happy-dom computes a real `offsetParent` (measured, not
        // assumed: a probe confirmed it is non-null for an attached element), so `focusable()` returns the
        // same list a browser would. Had it returned [], focus would have fallen to the panel and this case
        // would have passed for the wrong reason while proving nothing.
        openWithInput();
        await flushPromises();

        expect(document.activeElement?.classList.contains('mds-modal__close')).toBe(true);
        expect(document.activeElement?.hasAttribute('data-mds-initial-focus')).toBe(false);
    });

    it('resolves the selector INSIDE the panel, never against the whole document', async () => {
        // A page-level element carrying the same marker must not steal focus out of the dialog — which is
        // what a `document.querySelector` implementation would do, and it would pass every case above.
        const decoy = document.createElement('input');
        decoy.setAttribute('data-mds-initial-focus', '');
        document.body.appendChild(decoy);

        openWithInput({ initialFocus: '[data-mds-initial-focus]' });
        await flushPromises();

        expect(document.activeElement).not.toBe(decoy);
        expect(document.activeElement?.closest('.mds-modal__panel')).not.toBeNull();
    });
});
