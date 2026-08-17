<script setup lang="ts">
/**
 * The shared modal / dialog (DSR section 3.6). Every blocking overlay in the app is this component --
 * no page builds a "modal-like" floating panel that skips the focus trap. Ships the full contract:
 *   - a top-right close affordance (icon button with aria-label), also closable via Escape;
 *   - a focus trap (Tab cycles within the panel) with initial focus into the panel and
 *     return-focus to the opener on close;
 *   - the rest of the page marked `inert` while open (Increment I10a) -- see ./inert-stack.ts;
 *   - role="dialog" + aria-modal + aria-labelledby (the title);
 *   - renders in a top-level portal above the scrim (shadow-4), and becomes a full-screen
 *     sheet below the tablet breakpoint (section 6).
 * The package is isolated (no app dependency), so the trap/escape/scroll-lock are self-contained here.
 *
 * Actions go in the #actions slot (one primary button, bottom-right -- the one-primary rule, 3.1).
 */
/*
 * ⚠️ DO NOT WRITE A BARE HTML TAG LITERAL IN A COMMENT IN THIS FILE (J1a, learned the hard way).
 * A `<`+`header`+`>` inside this docblock fails the STORYBOOK build only, as
 * "Modal.vue (358:817): Element is missing end tag" -- a line past the end of the file, naming no
 * construct you can see. Storybook's vue3-vite preset preserves comments for docgen, so the SFC
 * parser tokenizes comment bodies that Vitest, vue-tsc and the app's own Vite build all skip; those
 * three stay green, and only the merge-blocking a11y job goes red. Note the pre-existing `body` tag
 * literals below survive it, so "but there is one right there" is not evidence it is safe.
 * Name the CSS class instead -- more precise anyway, since it is greppable.
 */
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import Icon from '../Icon/Icon.vue';
import { pageOwningRoot, popModalRoot, pushModalRoot } from './inert-stack';
import { firstFocusable } from './focus-target';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        closeLabel?: string;
        // Teleport the overlay to the document body (default). Set false to render in place -- used by the axe
        // stories so the scanner (scoped to #storybook-root) can see the dialog.
        teleport?: boolean;
        /**
         * A CSS selector, resolved INSIDE the panel, for the control that should receive focus on open.
         * Omitted (the default) keeps the pre-J1a behaviour byte-for-byte: `focusable()[0] ?? panel`.
         *
         * ⚠️ WHAT THE DEFAULT ACTUALLY DOES, STATED PRECISELY BECAUSE IT IS RIGHT HALF THE TIME.
         * `focusable()` queries the whole panel in DOM order, and `.mds-modal__header` -- which carries
         * `.mds-modal__close` -- precedes `.mds-modal__body`, so every modal opens focused on its own
         * Close button. For the destructive confirmations that were this component's first consumers that
         * is BENIGN, and for the same reason DSR 4.5 gives when it asks for "a designated initial-focus
         * target -- e.g. the cancel button by default for destructive confirmations, so an accidental
         * `Enter` press doesn't confirm a destructive action": a stray Enter dismisses instead of deleting.
         *
         * Be precise about the gap, though, because it is easy to overclaim: 4.5 names the CANCEL button
         * and this focuses the CLOSE (X) affordance, which 3.6 enumerates as a separate required element
         * with a different accessible name. The safety property matches; the control does not. Nothing here
         * satisfies the second half of 4.5's sentence -- that is what this prop finally supplies.
         *
         * It is wrong for the other shape: a dialog whose POINT is an input opens focused on the one
         * control that throws the user's intent away. J1d's command palette is the first of those, and a
         * palette that opens on Close is not a palette. So this prop does not replace the default -- it
         * makes the choice explicit for the dialogs where the DOM-order accident does not land well, and
         * it must NOT become the default: 41 `MdsModal` call sites across 28 files rely on today's
         * behaviour, and most of them are confirmations.
         *
         * A selector rather than a ref/element, because the target lives in the CONSUMER's slot content:
         * a ref would have to be threaded back out through the slot, and an element prop would be null on
         * the first `nextTick` for exactly the mount-open case `immediate: true` exists to serve.
         *
         * Falls back to today's behaviour whenever the selector fails to move focus -- it matches nothing,
         * it is invalid CSS, or it matches something that cannot take focus. All three are verified rather
         * than assumed; see the comment at the call site for why the page being already inert makes a
         * silent failure here a keyboard trap rather than a cosmetic slip.
         */
        initialFocus?: string;
        /**
         * Selectors, in PREFERENCE order, for where focus should go on close when the captured opener
         * cannot take it. Resolved against the DOCUMENT rather than the panel — by then the panel is gone.
         *
         * ⚠️ THIS IS THE MIRROR OF `initialFocus`, AND ITS ABSENCE WAS A LIVE DEFECT (J6). `takePage()`
         * verifies that focus actually landed and falls back, and explains at length why: the page is
         * already inert by then, so every way the lookup can fail ends with the user on the document body with
         * Escape and the Tab trap unreachable. `closePage()` did none of that — it called
         * `opener?.focus?.()` and trusted it. But `.focus()` fails silently in three ways on the way OUT
         * too: the opener was removed while the dialog was open (an Inertia visit re-rendering the shell),
         * it became `display: none` (a control that only exists above a breakpoint), or it is inside a
         * subtree that is STILL inert because a non-dialog surface is holding the page underneath.
         *
         * That last one is the reachable case J4b1 filed: a palette opened over the mobile nav drawer
         * captures an opener inside the drawer, the user activates a result, the shell re-renders and the
         * drawer closes — and the element focus was promised to no longer exists.
         *
         * Omitted keeps the pre-J6 behaviour for every existing consumer, except that the failure is now
         * VERIFIED rather than assumed: with no candidates and a dead opener, focus is handed to whichever
         * surface still owns the page, and only if none does is it left alone.
         */
        returnFocus?: readonly string[];
    }>(),
    { closeLabel: 'Close', teleport: true },
);

const emit = defineEmits<{ close: []; 'update:open': [value: boolean] }>();

const titleId = useId();
const panel = ref<HTMLElement | null>(null);
const backdrop = ref<HTMLElement | null>(null);
let opener: HTMLElement | null = null;

/**
 * The backdrop element THIS instance handed to the inert stack, held separately from the template ref.
 * On close the leave-Transition and the ref's own teardown both race the watcher, and popping an element
 * the stack never received would leave the page permanently inert.
 */
let ownedRoot: HTMLElement | null = null;

const FOCUSABLE =
    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusable(): HTMLElement[] {
    if (!panel.value) return [];
    return Array.from(panel.value.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
        (el) => el.offsetParent !== null || el === document.activeElement,
    );
}

function requestClose() {
    emit('update:open', false);
    emit('close');
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        requestClose();
        return;
    }
    if (event.key !== 'Tab') return;

    const items = focusable();
    if (items.length === 0) {
        event.preventDefault();
        panel.value?.focus();
        return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement as HTMLElement | null;

    if (event.shiftKey && (active === first || !panel.value?.contains(active))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

function onBackdrop(event: MouseEvent) {
    if (event.target === event.currentTarget) requestClose();
}

function takePage() {
    // nextTick, because the backdrop does not exist until the v-if renders -- and on the `immediate` run
    // below we are still inside setup(). `props.open` is re-read inside the callback because an open
    // immediately followed by a close in the SAME tick would otherwise push a page the modal no longer
    // wants: the close runs synchronously (releasePage is a no-op, nothing was pushed yet) and this
    // callback would then take the page for a dialog that is already gone, leaving it inert forever.
    nextTick(() => {
        if (!props.open || backdrop.value === null) return;
        ownedRoot = backdrop.value;
        pushModalRoot(ownedRoot);
        const items = focusable();

        // ── THE DESIGNATED TARGET, AND WHY IT IS TRIED-THEN-VERIFIED RATHER THAN TRUSTED ───────────
        // By this line the page is ALREADY inert and scroll-locked (pushModalRoot, above). So every way
        // this lookup can fail to move focus ends in the same place: the opener still holds focus, the
        // opener is now inside an inert subtree, the UA drops focus to the document body -- and because
        // `onKeydown` is bound to the panel, ESCAPE AND THE TAB TRAP BOTH BECOME UNREACHABLE. That is a
        // keyboard trap (WCAG 2.1.2) and the exact "never left stranded" outcome DSR 4.5 forbids, reached
        // through the prop that was added to serve 4.5. It is worth six lines to make structurally
        // impossible rather than one line to leave as a consumer's problem.
        //
        // THREE FAILURE MODES, none of which a `?? fallback` on the RETURN VALUE can catch:
        //   1. An invalid selector THROWS. `querySelector('[')` is a DOMException, not a null return, and
        //      this callback runs inside `nextTick`, which does not route through Vue's error handler --
        //      so the throw escapes as an unhandled rejection and `.focus()` never runs at all.
        //   2. A MATCH THAT CANNOT TAKE FOCUS is a silent no-op: a disabled input, a `display: none`
        //      element, or a plain wrapper the marker attribute drifted onto during a refactor.
        //   3. A match outside the panel -- prevented by scoping the query to `panel`, which also keeps a
        //      page-level element carrying the same marker from pulling focus out of the dialog.
        //
        // The target is deliberately NOT filtered through `focusable()` first: that query drops anything
        // whose `offsetParent` is null (a `position: fixed` descendant, or an element inside a wrapper
        // that generates no box), and a palette input silently falling back to Close would be
        // indistinguishable from the bug this prop exists to fix. Verifying AFTER the fact gets the
        // permissiveness and the safety net at once.
        let designated: HTMLElement | null = null;
        if (props.initialFocus) {
            try {
                designated = panel.value?.querySelector<HTMLElement>(props.initialFocus) ?? null;
            } catch {
                designated = null;
            }
        }
        designated?.focus();

        // The safety net. `contains()` is true for the panel itself, so a panel-focused fallback is not
        // re-run. When no selector was given this is simply the pre-J1a path, unchanged.
        if (panel.value && !panel.value.contains(document.activeElement)) {
            (items[0] ?? panel.value).focus();
        }
    });
}

function releasePage() {
    if (ownedRoot === null) return;
    popModalRoot(ownedRoot);
    ownedRoot = null;
}

/**
 * Release the page, then return focus. Order matters -- see popModalRoot's contract.
 *
 * ⚠️ TRIED-THEN-VERIFIED, EXACTLY LIKE `takePage()`, AND UNTIL J6 THIS HALF WAS TRUSTED (J4b1 finding 2).
 * `.focus()` on an element that cannot take it is a silent no-op, and on the way OUT there are three ways
 * for that to happen to a captured opener: it was removed while the dialog was open, it stopped being
 * rendered, or it is still inside an inert subtree because a non-dialog SURFACE is holding the page
 * underneath. All three land in the same place -- focus on `document.body`, with no dialog left to bind
 * Escape or trap Tab, which is the stranding DSR 4.5 forbids in the same sentence that asks for
 * return-focus at all.
 *
 * The verification is `activeElement` being body/null rather than "is the opener focused", and that
 * distinction preserves popModalRoot's own contract for free: when a LOWER dialog closes under an open
 * upper one, focus is legitimately inside the upper panel and the opener's no-op there is CORRECT -- focus
 * must not jump to a control sitting behind an open dialog. Testing for stranding rather than for success
 * leaves that case untouched.
 */
function closePage() {
    const wasOpen = ownedRoot !== null;
    releasePage();
    if (!wasOpen) return;

    // The release-then-focus order stays SYNCHRONOUS -- that is popModalRoot's contract and the reason this
    // function exists in this shape.
    opener?.focus?.();
    opener = null;

    // ⚠️ BUT THE VERIFICATION CANNOT BE, AND MEASURING IT IS WHAT ESTABLISHED THAT. The first version checked
    // `activeElement` right here and the three fallback cases all failed, stranded on the body element -- the
    // watcher is PRE-FLUSH, so the panel is still mounted at this line and focus is still legitimately
    // inside it. The strand happens one tick later, when the `v-if` tears the panel out and the user agent
    // drops focus. Checking early does not measure the failure; it measures the moment before it. So the
    // verification runs in `nextTick`, exactly as `takePage()`'s does and for the mirror-image reason.
    //
    // `returnFocus` is read NOW rather than in the callback: this also runs from `onBeforeUnmount`, and a
    // prop read on a torn-down instance is not something to rely on a tick later.
    const candidates = props.returnFocus;

    void nextTick(() => {
        if (!isStranded()) return;

        firstFocusable(candidates ?? [])?.focus();

        if (!isStranded()) return;

        // Last resort, and it only fires where the reader would otherwise have nothing at all: focus is on
        // the body element and a surface is still holding the page, so every other element in the document is inert.
        const owner = pageOwningRoot();
        if (owner === null) return;

        (owner.querySelector<HTMLElement>(FOCUSABLE) ?? owner).focus();
    });
}

/**
 * Focus has ended up nowhere.
 *
 * ⚠️ AN EARLIER VERSION OF THIS LINE SAID `document.body.focus()` IS ITSELF A NO-OP, AND THAT IS FALSE --
 * the body is the document's default focus target, so the call succeeds. It matters because it inverts the
 * consequence: an opener that IS the body element does not fail to restore focus, it actively TAKES focus. That is
 * why `takePage()` refuses to capture one. Corrected rather than left flattering, because a docblock
 * asserting a safety that does not exist is how the next author builds on it.
 */
function isStranded(): boolean {
    return document.activeElement === null || document.activeElement === document.body;
}

watch(
    () => props.open,
    (isOpen, was) => {
        if (isOpen && !was) {
            // ⚠️ NEVER CAPTURE THE BODY ELEMENT AS AN OPENER, AND THIS IS A DEFECT J6's TESTS UNCOVERED RATHER THAN
            // INTRODUCED -- it predates the return-focus work. A modal MOUNTED already open with nothing
            // focused (two live call sites do exactly that, and every Storybook story) captured
            // `document.activeElement`, which is the body element. `closePage()` then called `.focus()` on it, and
            // `document.body.focus()` is NOT the no-op that comment assumed: the body IS the document's
            // default focus target, so the call SUCCEEDS and moves focus there. So closing such a modal did
            // not merely fail to restore focus -- it actively took it, including out of an upper dialog that
            // was still open. Found by strengthening the stacked case to focus something other than the
            // panel's first focusable, which is what a surviving mutation pointed at.
            const active = document.activeElement as HTMLElement | null;
            opener = active === null || active === document.body ? null : active;
            takePage();
        } else if (!isOpen && was) {
            closePage();
        }
    },
    // `immediate` is load-bearing, not tidiness. A modal MOUNTED already open never ran this watcher, so it
    // got no scroll lock, no captured opener and no initial focus -- and two live sites do exactly that
    // (`forms/Index.vue`'s AssignScopeModal, `scopes/Index.vue`'s move confirm), as does every Storybook
    // story (`args: { open: true }`), which would have made the axe story green over a no-op. The first run
    // arrives as (true, undefined), so `isOpen && !was` holds and `!isOpen && was` cannot.
    { immediate: true },
);

/**
 * Unmounting while open is a close the watcher never sees, and it is not an edge case: the `v-if="x"
 * :open="true"` shape unmounts INSTEAD of setting `open` to false, and two live consumers use it
 * (`forms/Index.vue`'s AssignScopeModal, `scopes/Index.vue`'s move confirm).
 *
 * It therefore has to run the full close, not just the release. Before I10a that did not matter, because a
 * mount-open modal never captured an opener in the first place; `immediate: true` now captures one, and
 * releasing `inert` without returning focus would strand the user on the document body -- the exact outcome DSR §4.5
 * says must never happen ("focus returns to the element that triggered the modal, never left stranded").
 * Focusing an already-detached opener (a full page teardown) is a harmless no-op.
 *
 * It is also the guard against cross-file pollution in Vitest, where specs share one happy-dom document and
 * a leaked `inert` would silently blank the next file's assertions.
 */
onBeforeUnmount(closePage);
</script>

<template>
    <Teleport to="body" :disabled="!teleport">
        <Transition name="mds-modal">
            <div v-if="open" ref="backdrop" class="mds-modal__backdrop" @mousedown="onBackdrop">
                <div
                    ref="panel"
                    class="mds-modal__panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    tabindex="-1"
                    @keydown="onKeydown"
                >
                    <header class="mds-modal__header">
                        <h2 :id="titleId" class="mds-modal__title">{{ title }}</h2>
                        <button
                            type="button"
                            class="mds-modal__close"
                            :aria-label="closeLabel"
                            @click="requestClose"
                        >
                            <Icon name="close" size="md" />
                        </button>
                    </header>

                    <div class="mds-modal__body"><slot /></div>

                    <footer v-if="$slots.actions" class="mds-modal__footer">
                        <slot name="actions" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.mds-modal__backdrop {
    position: fixed;
    inset: 0;
    z-index: var(--mds-z-index-modal, 1000);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-4);
    background-color: var(--mds-color-overlay-scrim);
}

.mds-modal__panel {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 520px;
    max-height: calc(100dvh - var(--mds-space-8));
    background-color: var(--mds-color-bg-surface-raised);
    border: 1px solid var(--mds-color-border-default);
    /* JR2: the page-level card/dialog tier (DSR §2.6), in step with `MdsCard`. The close button
       below stays at `md` — it is a control, and controls hold the 12px tier. The `border-radius: 0`
       in the ≤480px block is the full-screen sheet and must stay: it is the one radius in this
       package that is a literal rather than a token, and the one that has to be kept in step by hand. */
    border-radius: var(--mds-radius-xl);
    box-shadow: var(--mds-shadow-4);
    color: var(--mds-color-text-body);
}

.mds-modal__panel:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-modal__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-4);
    padding: var(--mds-space-5) var(--mds-space-6);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.mds-modal__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.mds-modal__close {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-md);
    background: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}
.mds-modal__close::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    min-width: 44px;
    min-height: 44px;
    width: 100%;
    height: 100%;
    transform: translate(-50%, -50%);
}
.mds-modal__close:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}
.mds-modal__close:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-modal__body {
    padding: var(--mds-space-6);
    overflow-y: auto;
}

.mds-modal__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--mds-space-3);
    padding: var(--mds-space-4) var(--mds-space-6);
    border-top: 1px solid var(--mds-color-border-default);
}

@media (max-width: 480px) {
    .mds-modal__backdrop {
        padding: 0;
        align-items: stretch;
    }
    .mds-modal__panel {
        max-width: none;
        max-height: none;
        min-height: 100dvh;
        border: 0;
        border-radius: 0;
    }
}

.mds-modal-enter-active {
    transition: opacity var(--mds-duration-slow) var(--mds-ease-decelerate);
}
.mds-modal-leave-active {
    transition: opacity var(--mds-duration-moderate) var(--mds-ease-accelerate);
}
.mds-modal-enter-from,
.mds-modal-leave-to {
    opacity: 0;
}
.mds-modal-enter-active .mds-modal__panel {
    transition: transform var(--mds-duration-slow) var(--mds-ease-decelerate);
}
.mds-modal-enter-from .mds-modal__panel {
    transform: translateY(8px);
}
</style>
