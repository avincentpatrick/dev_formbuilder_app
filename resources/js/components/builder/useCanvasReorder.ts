// Reordering for the builder canvas (Increment D4b) — hand-rolled, no DnD library (libraries inject
// aria-hidden drag-mirror DOM and steal focus, failing the merge-blocking axe gate). Two fully independent
// paths over the same store primitives, both crossing section boundaries (WCAG 2.5.7):
//   - POINTER drag: pointerdown on a grip captures the pointer; each move hit-tests the group container +
//     row under the cursor and live-reorders; pointerup commits, Escape cancels. touch-action:none on the
//     grip keeps a touch-drag from scrolling.
//   - KEYBOARD grab-mode: Enter/Space on a grip "grabs" the item; Arrow keys walk it up/down across the
//     whole form (into and out of sections); Enter/Space drops, Escape cancels. Focus never leaves the grip
//     (no vanishing control). Every state change is announced through an assertive aria-live region.
// A drag/grab is ONE reorder session on the store → one persist + one undo entry (or a clean restore).

import { computed, ref } from 'vue';
import type { BuilderStore } from './useBuilderStore';

export function useCanvasReorder(store: BuilderStore, getRoot: () => HTMLElement | null) {
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

    // ── Pointer drag: fields ──────────────────────────────────────────────────
    function onFieldPointerDown(event: PointerEvent, uid: string): void {
        if (event.button !== 0 || grabbedUid.value !== null) return;
        event.preventDefault();
        draggingUid.value = uid;
        store.beginReorder();
        announce(`Dragging ${fieldLabel(uid)}. Move over a position and release to drop.`);

        const move = (e: PointerEvent): void => onFieldDragMove(e, uid);
        const finish = (): void => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('keydown', key, true);
            draggingUid.value = null;
        };
        const up = (): void => {
            finish();
            void store.commitReorder('Move field');
            announce(`Dropped ${fieldLabel(uid)}, ${describeFieldPosition(uid)}.`);
        };
        const key = (e: KeyboardEvent): void => {
            if (e.key === 'Escape') {
                e.preventDefault();
                finish();
                store.cancelReorder();
                announce('Reorder cancelled.');
            }
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('keydown', key, true);
    }

    function onFieldDragMove(event: PointerEvent, uid: string): void {
        const el = document.elementFromPoint(event.clientX, event.clientY) as HTMLElement | null;
        const groupEl = el?.closest<HTMLElement>('[data-drop-group]');
        if (!groupEl) return;
        const raw = groupEl.getAttribute('data-drop-group');
        const group = raw === 'ungrouped' ? null : raw;

        const rows = Array.from(groupEl.querySelectorAll<HTMLElement>('[data-field-uid]:not([data-dragging="true"])'));
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

    // ── Pointer drag: sections ────────────────────────────────────────────────
    function onSectionPointerDown(event: PointerEvent, uid: string): void {
        if (event.button !== 0 || grabbedUid.value !== null) return;
        event.preventDefault();
        draggingUid.value = uid;
        store.beginReorder();
        announce(`Dragging a section. Move over a position and release to drop.`);

        const move = (e: PointerEvent): void => onSectionDragMove(e, uid);
        const finish = (): void => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('keydown', key, true);
            draggingUid.value = null;
        };
        const up = (): void => {
            finish();
            void store.commitReorder('Move section');
            announce(`Dropped section, ${describeSectionPosition(uid)}.`);
        };
        const key = (e: KeyboardEvent): void => {
            if (e.key === 'Escape') {
                e.preventDefault();
                finish();
                store.cancelReorder();
                announce('Reorder cancelled.');
            }
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('keydown', key, true);
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
