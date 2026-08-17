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

    /**
     * Whether this run has ALREADY tried to capture an opener — which is a different question from
     * whether it HAS one, and conflating the two is a bug the adversarial pass found in J6's own new code.
     *
     * `opener === null` means both *"not captured yet"* and *"captured, and the answer was legitimately
     * nothing"* — the second being what happens when the surface opens with nothing focused, now that the
     * body element is refused. A hand-over then re-ran the capture and grabbed whatever was focused
     * **inside the outgoing root**, so closing the surface would have returned focus to a detached element
     * in a root that no longer exists. That is precisely the drift the single-capture rule exists to stop,
     * reintroduced by the guard added beside it.
     */
    let openerCaptured = false;

    function take(): void {
        // ⚠️ `??=`, NOT `=` (J6). A root that changes identity mid-run takes the page again, and the element
        // the surface was OPENED from must survive that — overwriting it here would make return-focus land
        // on whatever happened to be focused inside the previous root, which is the surface returning the
        // user to itself.
        //
        // ⚠️ AND THE BODY ELEMENT IS NEVER AN OPENER, WHICH J6's ADVERSARIAL PASS FOUND HERE AFTER FIXING THE
        // SAME THING IN `Modal.vue` — AND THIS SEAM'S VERSION WAS MADE REACHABLE BY J6's OWN FIRST FIX.
        // Focusing the body element is not a no-op: the body is the document's default focus target, so the
        // call SUCCEEDS. A surface opened by touch or by a programmatic toggle, with nothing focused, would
        // capture it — and `release()` would then move focus TO the body. Harmless while nothing else holds
        // the page, and not harmless now: the drawer is a `surface`, so as of J6 a dialog can be open ON TOP
        // of it (⌘K), and the drawer releasing underneath — a resize past 480px does exactly that — would
        // yank focus out of the open dialog. Null instead, so `release()`'s optional call simply does nothing
        // and whatever holds focus keeps it.
        if (!openerCaptured) {
            openerCaptured = true;
            const active = document.activeElement as HTMLElement | null;
            opener = active === null || active === document.body ? null : active;
        }

        // nextTick, because the surface may not have rendered yet — and on the immediate run below we are
        // still inside setup(). `active` is re-read inside the callback because an open immediately
        // followed by a close in the SAME tick would otherwise push a page nothing wants back: the close
        // runs synchronously as a no-op, and this callback would then take the page for a surface that is
        // already gone, leaving the document inert forever.
        void nextTick(() => {
            if (!active.value || root.value === null || ownedRoot !== null) return;

            ownedRoot = root.value;
            // ⚠️ `surface`, NOT the default `dialog` (J6). This seam exists precisely because its consumers
            // take the page WITHOUT being dialogs — see the docblock above — and `openModalCount()`
            // documents itself as counting blocking dialogs. Pushing as a dialog made ⌘K a dead key
            // whenever the drawer was open: a global affordance asked "would I be stacking onto an
            // unfinished dialog?" and a navigation flyout answered yes.
            pushModalRoot(ownedRoot, 'surface');

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

    /**
     * Give the page back without touching focus — the half a hand-over needs and a close does not.
     *
     * ⚠️ IT IS NOT UNIT-DISTINGUISHABLE FROM CALLING `release()` HERE, AND THE MUTATION PASS PROVED THAT
     * RATHER THAN THE OPPOSITE. Swapping this for the full `release()` in the hand-over arm survives every
     * case in this file, and the reasoning holds: `release()` restores focus to the opener and nulls it,
     * then `take()`'s `??=` re-reads `document.activeElement` — which is the element `release()` just
     * focused. The captured opener comes out the same, so the round trip is a no-op in the DOM.
     *
     * It stays because of something happy-dom cannot represent: in a browser that round trip **paints**. The
     * focus ring lands on the opener and jumps away again within one frame, which is a flash on a control the
     * user is not interacting with. Recorded as a deliberate mutation survivor rather than left looking like
     * a gap — a surviving mutant is only a defect if the difference is one somebody can observe.
     */
    function releasePageOnly(): void {
        if (ownedRoot === null) return;

        popModalRoot(ownedRoot);
        ownedRoot = null;
    }

    function release(): void {
        if (ownedRoot === null) return;

        // ⚠️ ORDER IS THE CONTRACT: release the page BEFORE restoring focus. Calling `.focus()` on an
        // element that is still inside an inert subtree is a silent no-op, and the user is left with focus
        // on the body and no way back.
        releasePageOnly();
        opener?.focus?.();
        opener = null;
        // Reset here and NOT in `releasePageOnly()` — a hand-over must keep the run's original capture,
        // which is the whole point of the flag.
        openerCaptured = false;
    }

    /**
     * ⚠️ IT WATCHES `root` AS WELL AS `active`, AND WATCHING ONLY `active` WAS A LATENT DEFECT (J6, J4b1's
     * third finding). `ownedRoot` is captured once and the `ownedRoot !== null` guard inside `take()` makes
     * every later take a no-op, so a `root` that changed identity while active left the stack holding the
     * OLD element: the inert walk is then computed from a root that may not even be in the document, and the
     * new surface — being an off-path sibling of the stale one — can be marked inert by its own seam.
     *
     * The shipped consumer's root is stable, so nothing was broken. But this is exported API, and the next
     * consumer is where a `v-if`-swapped root or a `<component :is>` would find it.
     *
     * Three transitions, and the middle one is the new one:
     *  - active goes false → full release, focus back to the opener.
     *  - root is replaced while active → hand the page over WITHOUT going through the opener. A full release
     *    would restore focus to the opener and then immediately steal it back, and would discard the opener
     *    the run started with.
     *  - root goes null while we hold one → the surface is gone, so this is a close, not a hand-over.
     *
     * ⚠️ AND `root === null` WHILE WE HOLD NOTHING IS NOT ANY OF THEM — it is simply "not rendered yet",
     * which is the state the `immediate` run always sees from inside `setup()`. Treating it as a teardown
     * would break the mount-already-active path that two call sites in this repo depend on.
     */
    watch([active, root], ([isActive, current]) => {
        if (!isActive) {
            release();

            return;
        }

        if (ownedRoot !== null && ownedRoot !== current) {
            if (current === null) {
                release();

                return;
            }

            releasePageOnly();
        }

        take();
    }, { immediate: true });

    // Non-negotiable: a leaked `inert` in a shared test document silently blanks the NEXT spec file's
    // assertions, and in the application it would strand a page whose drawer was unmounted mid-navigation.
    onBeforeUnmount(release);
}
