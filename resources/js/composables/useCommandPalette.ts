import { openModalCount } from '@meridian/design-system';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The ⌘K chord and the palette's open state (Increment J1d, DSR §3.4.1).
 *
 * ⚠️ MODULE-LEVEL STATE, ON PURPOSE. The palette is mounted once, in the single persistent `AppLayout`, and
 * `open` is module-scoped so any future opener (a nav button, a help menu) toggles the same instance rather
 * than minting a second dialog on top of the first.
 */
const open = ref(false);

/**
 * How many components have asked us to bind the chord. A double mount — HMR replacing the layout, or a
 * second layout during a transition — would otherwise install two capture listeners and toggle twice per
 * keypress, which reads as "the palette does not open".
 */
let bindCount = 0;
let boundHandler: ((event: KeyboardEvent) => void) | null = null;

function isChord(event: KeyboardEvent): boolean {
    // `metaKey` for macOS, `ctrlKey` elsewhere. `event.key` rather than `code`, so a Dvorak or AZERTY user
    // gets the chord under the key that actually prints "k".
    return (event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'k';
}

export function useCommandPalette(opener?: () => HTMLElement | null) {
    function onChord(event: KeyboardEvent): void {
        if (!isChord(event)) return;

        // Before anything else: browsers bind ⌘K/Ctrl+K to their own address bar or search field, so
        // without this the palette and the browser both act on the same keystroke.
        //
        // ⚠️ IT STAYS ABOVE THE GUARDS, AND MOVING IT BELOW THEM IS THE NAIVE FIX THAT TRADES ONE DEFECT
        // FOR ANOTHER (J6). Declining the chord because a real dialog owns the page is CORRECT, and so is
        // swallowing it there: handing ⌘K back to the browser at that moment opens its find bar on top of
        // a modal, which is worse than nothing happening. The dead-key defect this line was blamed for was
        // never about its position — it was that the mobile nav DRAWER counted as a dialog, so the guard
        // below declined a case it should always have admitted. Fixed in `inert-stack.ts`, where the
        // question is asked, rather than here, where it is only read.
        event.preventDefault();

        // ⚠️ GUARD ORDER IS THE WHOLE DESIGN, AND IT IS WHY THERE IS NO `await nextTick()` HERE.
        //
        // `openModalCount()` LAGS `open` by one tick: MdsModal pushes onto the inert stack inside
        // `nextTick` (Modal.vue), though it releases synchronously on close. So checking the count first
        // would see our OWN palette as "another dialog" and the chord could never toggle it shut.
        //
        // Checking our own state first fixes that without waiting for anything: step 1 is synchronous and
        // authoritative, and step 2 is then only ever consulted about OTHER dialogs — for which the count
        // is never stale at chord time, because a human keypress is many ticks after the dialog that owns
        // the page opened.
        //
        // The residual hazard is honest and small: another dialog opening in the SAME tick as the chord is
        // not seen, and the palette would stack on top of it. That is best-effort by choice — making this
        // handler `async` to await a tick would surrender `preventDefault()`'s synchronous window, and the
        // browser would take the keystroke instead. A worse failure, for a narrower case.
        if (open.value) {
            open.value = false;

            return;
        }

        // Only BLOCKING DIALOGS count here, which is what `openModalCount()` documents and — since J6 —
        // what it actually returns. A non-dialog surface that has taken the page (the mobile nav drawer)
        // is not a reason to refuse: the palette is a global affordance and stacking over a navigation
        // flyout is exactly what a user pressing ⌘K from an open drawer is asking for.
        if (openModalCount() > 0) return;

        // ⚠️ FOCUS A REAL ELEMENT BEFORE OPENING. MdsModal captures the active element to return focus to
        // on close, and `document.body.focus()` is a silent no-op — so a palette opened by chord from a
        // page with nothing focused would strand focus on close, which is exactly what §4.5 forbids.
        opener?.()?.focus();

        open.value = true;
    }

    onMounted(() => {
        bindCount += 1;
        if (bindCount > 1) return;

        boundHandler = onChord;
        // ⚠️ CAPTURE PHASE. Bubble is silently dead on any page whose editor calls `stopPropagation()`, and
        // "opens from anywhere" is the entire point of the shortcut.
        document.addEventListener('keydown', boundHandler, true);
    });

    onBeforeUnmount(() => {
        bindCount = Math.max(0, bindCount - 1);
        if (bindCount > 0 || boundHandler === null) return;

        // ⚠️ THE `true` HERE IS NOT OPTIONAL. removeEventListener matches on (type, handler, capture); a
        // capture listener removed without the flag is never removed at all, and the leak survives every
        // subsequent mount. Worth its own test, because nothing else would show it.
        document.removeEventListener('keydown', boundHandler, true);
        boundHandler = null;
    });

    return { open };
}

/** Test-only reset, so a spec's leak assertions start from a known state. */
export function __resetCommandPaletteBindings(): void {
    if (boundHandler !== null) {
        document.removeEventListener('keydown', boundHandler, true);
    }

    bindCount = 0;
    boundHandler = null;
    open.value = false;
}
