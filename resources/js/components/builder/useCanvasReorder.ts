// Reordering for the builder canvas (Increment D4b) — hand-rolled, no DnD library (libraries inject
// aria-hidden drag-mirror DOM and steal focus, failing the merge-blocking axe gate). Two fully independent
// paths over the same store primitives, both crossing section boundaries (WCAG 2.5.7):
//   - POINTER drag: pointerdown on a grip arms a drag that begins once the pointer has moved DRAG_THRESHOLD
//     pixels (M150 — a click on a grip used to announce "Dragging…" and "Dropped…" and move nothing); each move
//     then hit-tests the group container + row under the cursor and live-reorders; pointerup commits, Escape or
//     pointercancel cancels. The listeners are on `window` and the pointer is NOT captured: a question moved
//     into another section is a new element, and a capture on the old one would go with it. touch-action:none
//     on the grip keeps a touch-drag from scrolling.
//   - KEYBOARD grab-mode: Enter/Space on a grip "grabs" the item; Arrow keys walk it up/down across the
//     whole form (into and out of sections); Enter/Space drops, Escape cancels. Every state change is announced
//     through an assertive aria-live region. A step can rebuild the item's row (a question moved into another
//     section is a new element) or move its node, and either takes focus with it — so after each step focus is
//     put back on the item's grip (M150): before, the drop key landed on the page, the move was never saved, and
//     the grab stayed on and refused every later drag.
// A drag/grab is ONE reorder session on the store → one persist + one undo entry (or a clean restore).
// The glide itself is useFlipReorder's; this file only decides where things go.

import { computed, nextTick, ref } from 'vue';
import type { BuilderStore } from './useBuilderStore';

/** How far, in CSS pixels, a pointer must travel from a grip before a press becomes a drag. */
export const DRAG_THRESHOLD = 4;

type Kind = 'field' | 'section';

export function useCanvasReorder(
    store: BuilderStore,
    getRoot: () => HTMLElement | null,
    options: { onDragStart?: () => void } = {},
) {
    const draggingUid = ref<string | null>(null);
    const grabbedUid = ref<string | null>(null);
    const announcement = ref('');

    const isReordering = computed(() => draggingUid.value !== null || grabbedUid.value !== null);

    function announce(message: string): void {
        announcement.value = message;
    }

    function fieldLabel(uid: string): string {
        return store.flattenedFields().find((f) => f.uid === uid)?.label || 'field';
    }

    function describeFieldPosition(uid: string): string {
        const flat = store.flattenedFields();
        const field = flat.find((f) => f.uid === uid);
        if (!field) return '';
        const siblings = flat.filter((f) => f.form_section_id === field.form_section_id);
        const index = siblings.findIndex((f) => f.uid === uid);
        const where = field.form_section_id
            ? (store.orderedSections().find((s) => s.id === field.form_section_id)?.label ?? 'a section')
            : 'the top level';
        return `position ${index + 1} of ${siblings.length} in ${where}`;
    }

    function describeSectionPosition(uid: string): string {
        const ordered = store.orderedSections();
        const index = ordered.findIndex((s) => s.uid === uid);
        return `section ${index + 1} of ${ordered.length}`;
    }

    // ── Pointer drag: armed on press, begun past the threshold ───────────────
    function startDrag(event: PointerEvent, uid: string, kind: Kind): void {
        if (event.button !== 0 || grabbedUid.value !== null || draggingUid.value !== null) return;
        event.preventDefault();
        const pointerId = event.pointerId;
        const startX = event.clientX;
        const startY = event.clientY;
        let started = false;

        const finish = (): void => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', cancel);
            window.removeEventListener('keydown', key, true);
            draggingUid.value = null;
        };
        const abort = (): void => {
            finish();
            if (!started) return;
            store.cancelReorder();
            announce('Reorder cancelled.');
        };
        const move = (e: PointerEvent): void => {
            if (e.pointerId !== pointerId) return;
            if (!started) {
                if (Math.hypot(e.clientX - startX, e.clientY - startY) < DRAG_THRESHOLD) return;
                started = true;
                options.onDragStart?.();
                draggingUid.value = uid;
                store.beginReorder();
                announce(
                    kind === 'field'
                        ? `Dragging ${fieldLabel(uid)}. Move over a position and release to drop.`
                        : 'Dragging a section. Move over a position and release to drop.',
                );
            }
            if (kind === 'field') onFieldDragMove(e, uid);
            else onSectionDragMove(e, uid);
        };
        const up = (e: PointerEvent): void => {
            if (e.pointerId !== pointerId) return;
            finish();
            if (!started) return;
            if (kind === 'field') {
                void store.commitReorder('Move field');
                announce(`Dropped ${fieldLabel(uid)}, ${describeFieldPosition(uid)}.`);
            } else {
                void store.commitReorder('Move section');
                announce(`Dropped section, ${describeSectionPosition(uid)}.`);
            }
        };
        const cancel = (e: PointerEvent): void => {
            if (e.pointerId === pointerId) abort();
        };
        const key = (e: KeyboardEvent): void => {
            if (e.key !== 'Escape') return;
            if (started) e.preventDefault();
            abort();
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', cancel);
        window.addEventListener('keydown', key, true);
    }

    function onFieldPointerDown(event: PointerEvent, uid: string): void {
        startDrag(event, uid, 'field');
    }

    function onSectionPointerDown(event: PointerEvent, uid: string): void {
        startDrag(event, uid, 'section');
    }

    /** After a keyboard step re-renders, put focus back on the grabbed item's grip, wherever its row now is. */
    function refocusGrip(uid: string, kind: Kind): void {
        void nextTick(() => {
            const selector = kind === 'field' ? `[data-field-uid="${uid}"] .canvas__grip` : `[data-section-uid="${uid}"] .canvas__grip`;
            const grip = getRoot()?.querySelector<HTMLElement>(selector);
            if (grip && document.activeElement !== grip) grip.focus();
        });
    }

    function onFieldDragMove(event: PointerEvent, uid: string): void {
        const el = document.elementFromPoint(event.clientX, event.clientY) as HTMLElement | null;
        const groupEl = el?.closest<HTMLElement>('[data-drop-group]');
        if (!groupEl) return;
        const raw = groupEl.getAttribute('data-drop-group');
        const group = raw === 'ungrouped' ? null : raw;

        // The dragged row is left out by its uid, not by `data-dragging`: the move that begins a drag hit-tests in the
        // same tick that sets the flag, before Vue has rendered it (M150's threshold), and counting the row against
        // itself would shift it a place before the pointer had left it.
        const rows = Array.from(groupEl.querySelectorAll<HTMLElement>(`[data-field-uid]:not([data-field-uid="${uid}"])`));
        let index = rows.length;
        for (let k = 0; k < rows.length; k++) {
            const rect = rows[k].getBoundingClientRect();
            if (event.clientY < rect.top + rect.height / 2) {
                index = k;
                break;
            }
        }
        store.placeField(uid, group, index);
    }

    // ── Keyboard grab-mode: fields ────────────────────────────────────────────
    function onFieldKeydown(event: KeyboardEvent, uid: string): void {
        if (grabbedUid.value === null) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                grabbedUid.value = uid;
                store.beginReorder();
                announce(
                    `Grabbed ${fieldLabel(uid)}, ${describeFieldPosition(uid)}. ` +
                        'Use up and down arrows to move, Enter to drop, Escape to cancel.',
                );
            }
            return;
        }

        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            const moved = store.stepFieldAcross(uid, event.key === 'ArrowDown' ? 1 : -1);
            announce(moved ? `${describeFieldPosition(uid)}.` : `Already at the ${event.key === 'ArrowDown' ? 'end' : 'start'}.`);
            if (moved) refocusGrip(uid, 'field');
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            grabbedUid.value = null;
            void store.commitReorder('Move field');
            announce(`Dropped ${fieldLabel(uid)}, ${describeFieldPosition(uid)}.`);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            grabbedUid.value = null;
            store.cancelReorder();
            announce('Reorder cancelled.');
        }
    }

    function onSectionDragMove(event: PointerEvent, uid: string): void {
        const heads = Array.from(getRoot()?.querySelectorAll<HTMLElement>('[data-section-uid]') ?? []).filter(
            (h) => h.getAttribute('data-section-uid') !== uid,
        );
        let index = heads.length;
        for (let k = 0; k < heads.length; k++) {
            const rect = heads[k].getBoundingClientRect();
            if (event.clientY < rect.top + rect.height / 2) {
                index = k;
                break;
            }
        }
        store.placeSection(uid, index);
    }

    // ── Keyboard grab-mode: sections ──────────────────────────────────────────
    function onSectionKeydown(event: KeyboardEvent, uid: string): void {
        if (grabbedUid.value === null) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                grabbedUid.value = uid;
                store.beginReorder();
                announce(
                    `Grabbed a section, ${describeSectionPosition(uid)}. ` +
                        'Use up and down arrows to move, Enter to drop, Escape to cancel.',
                );
            }
            return;
        }

        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            const moved = store.stepSection(uid, event.key === 'ArrowDown' ? 1 : -1);
            announce(moved ? `${describeSectionPosition(uid)}.` : `Already at the ${event.key === 'ArrowDown' ? 'end' : 'start'}.`);
            if (moved) refocusGrip(uid, 'section');
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            grabbedUid.value = null;
            void store.commitReorder('Move section');
            announce(`Dropped section, ${describeSectionPosition(uid)}.`);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            grabbedUid.value = null;
            store.cancelReorder();
            announce('Reorder cancelled.');
        }
    }

    return {
        draggingUid,
        grabbedUid,
        announcement,
        isReordering,
        onFieldPointerDown,
        onFieldKeydown,
        onSectionPointerDown,
        onSectionKeydown,
    };
}
