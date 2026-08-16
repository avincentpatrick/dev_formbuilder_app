import { nextTick, onBeforeUnmount, watch, type Ref } from 'vue';
import { popModalRoot, pushModalRoot } from './inert-stack';

/**
 * Take the page for a non-dialog surface (J4b).
 *
 * `MdsModal` has owned this sequence since I10a, and it is the whole of what makes an overlay reachable:
 * push the root onto the inert stack, move focus inside it, and on the way out release the page BEFORE
 * restoring focus. This extracts it for the surfaces that need the same guarantee without being dialogs —
 * the sidebar's mobile drawer first.
 *
 * ⚠️ THE DRAWER IS NOT A DIALOG, AND MAKING IT ONE WOULD BE A REGRESSION RATHER THAN A FIX. Its root wraps
 * the primary navigation landmark at ALL THREE breakpoints; stamping `role="dialog"` on it at the narrowest
 * one would make the landmark's identity depend on the viewport, which is exactly what DSR §6 forbids when
 * it promises "a page never needs to know which of the three is currently rendering". So the seam is the
 * answer rather than a conversion — the backlog row that filed this defect reached the same conclusion.
 *
 * ⚠️ IT IS ALSO NOT A FOCUS TRAP, AND DOES NOT NEED TO BE. With every non-ancestor sibling marked `inert`
 * there is nothing tabbable left in the document outside `root`, so Tab cycles within it through the
 * browser and back — which is what the platform's own top layer does. `inert-stack.ts` argues the same
 * point from the other side: a Tab-cycling keydown handler only ever sees events that are already inside
 * the panel, so it never covered the escapes that actually matter (a screen reader's virtual cursor, a
 * round trip through browser chrome, a programmatic `focus()` from the page). `inert` covers all three.
 *
 * What IS still required is focus MANAGEMENT, and skipping it is a keyboard trap rather than an
 * inconvenience: by the time the stack has been pushed, the opener is inside an inert subtree, so leaving
 * focus on it makes the user agent drop focus to the body — from where a root-bound Escape handler can
 * never fire. That is the same argument `Modal.vue` makes at its own initial-focus block.
 */
export interface InertBackgroundOptions {
    /** Take the page while this is true, release it when it goes false. Watched immediately. */
    active: Ref<boolean>;
    /** The element that stays reachable. Everything else goes inert. A null root is a no-op. */
    root: Ref<HTMLElement | null>;
    /**
     * CSS selector for the element to focus, queried INSIDE `root`. Falls back to the first focusable
     * descendant, then to `root` itself — so `root` should carry `tabindex="-1"`.
     */
    initialFocus?: string;
}

const FOCUSABLE =
    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function useInertBackground(options: InertBackgroundOptions): void {
    const { active, root, initialFocus } = options;

    /**
     * The element that was pushed, remembered rather than re-read. `root` can change or null out between
     * take and release — during an unmount, most obviously — and releasing a root the stack never received
     * would leave the page permanently inert. `Modal.vue` keeps the same handle for the same reason.
     */
    let ownedRoot: HTMLElement | null = null;
    let opener: HTMLElement | null = null;

    function take(): void {
        opener = document.activeElement as HTMLElement | null;

        // nextTick, because the surface may not have rendered yet — and on the immediate run below we are
        // still inside setup(). `active` is re-read inside the callback because an open immediately
        // followed by a close in the SAME tick would otherwise push a page nothing wants back: the close
        // runs synchronously as a no-op, and this callback would then take the page for a surface that is
        // already gone, leaving the document inert forever.
        void nextTick(() => {
            if (!active.value || root.value === null || ownedRoot !== null) return;

            ownedRoot = root.value;
            pushModalRoot(ownedRoot);

            let designated: HTMLElement | null = null;
            if (initialFocus !== undefined) {
                try {
                    designated = ownedRoot.querySelector<HTMLElement>(initialFocus);
                } catch {
                    // An invalid selector throws a DOMException rather than returning null, and this runs
                    // inside nextTick, which does not route through Vue's error handler — so an unguarded
                    // throw would escape as an unhandled rejection and focus would never move at all.
                    designated = null;
                }
            }

            designated?.focus();

            // Verified AFTER the fact rather than trusted, because every way the lookup can fail to move
            // focus ends in the same place: the opener still holds it, the opener is now inert, and the
            // user agent drops focus to the body.
            if (!ownedRoot.contains(document.activeElement)) {
                const first = ownedRoot.querySelector<HTMLElement>(FOCUSABLE);
                (first ?? ownedRoot).focus();
            }
        });
    }

    function release(): void {
        if (ownedRoot === null) return;

        // ⚠️ ORDER IS THE CONTRACT: release the page BEFORE restoring focus. Calling `.focus()` on an
        // element that is still inside an inert subtree is a silent no-op, and the user is left with focus
        // on the body and no way back.
        popModalRoot(ownedRoot);
        ownedRoot = null;
        opener?.focus?.();
        opener = null;
    }

    watch(active, (isActive) => {
        if (isActive) take();
        else release();
    }, { immediate: true });

    // Non-negotiable: a leaked `inert` in a shared test document silently blanks the NEXT spec file's
    // assertions, and in the application it would strand a page whose drawer was unmounted mid-navigation.
    onBeforeUnmount(release);
}
