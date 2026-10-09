// Moving a question in the builder's Preview (M151, `R-2baef8ea`) — Round 2's note `r2-c18`: "please include the preview
// section to be draggable … questions or indicators must be draggable to sections, sequencing, etc."
//
// ── WHY NOT `useCanvasReorder` ────────────────────────────────────────────────────────────────────────────────────────────
// Structure draws its rows from the store, so it moves a question in the store on every pointer move and lets the canvas
// follow. The preview draws its rows from the ENGINE, which is rebuilt — and its host remounted — only when the structure
// moves: a live `placeField` would show nothing until that rebuild, and the rebuild would destroy the row under the
// pointer and the grip that has focus. So here nothing is written while a question is moving. The drag only decides a
// TARGET — above a shown question, below one, or the end of a section or page — draws it, and on the drop the pane writes
// the move once and rebuilds at once (`commit`). The pointer core (threshold, window listeners, one pointer, Escape and
// pointercancel) is Structure's, copied rather than shared so that Structure's drag was not reworked days before testing;
// one helper for both is filed.
//
// ── TARGETS ARE READ OFF THE PAGE ────────────────────────────────────────────────────────────────────────────────────────
// Every place a question can go is an element: each shown question's row (`data-drop-row`, "above it"), and each zone
// (`data-drop-zone`) — a section's "Add a question" area, an empty section, and while a move is on, each page in the strip
// (`D105`). Each carries `data-drop-id` (`before:key`, `after:key`, `end:sectionKey`) and the words it is announced by. The
// keyboard walks the same elements in page order, so it reaches exactly what the pointer reaches (WCAG 2.5.7).

import { computed, nextTick, ref } from 'vue';
import type { PreviewDropTarget } from './preview-model';
import { DRAG_THRESHOLD } from './useCanvasReorder';

/** A target as the page names it. */
export function dropIdOf(target: PreviewDropTarget): string {
    return target.kind === 'end' ? `end:${target.sectionKey ?? ''}` : `${target.kind}:${target.key}`;
}

export function dropTargetOf(id: string): PreviewDropTarget | null {
    const at = id.indexOf(':');
    const kind = id.slice(0, at);
    const key = id.slice(at + 1);
    if (kind === 'end') return { kind, sectionKey: key === '' ? null : key };
    if (kind === 'before' || kind === 'after') return { kind, key };
    return null;
}

export interface PreviewReorderHost {
    getRoot: () => HTMLElement | null;
    /** How a question is named in an announcement. */
    labelOf: (key: string) => string;
    /** The store move a drop means, or null when it moves nothing. */
    place: (key: string, target: PreviewDropTarget) => { group: string | null; index: number } | null;
    /** Write the move once and rebuild the preview at once. */
    commit: (key: string, placement: { group: string | null; index: number }) => void;
    /** A drag is beginning: the pane ends an open label edit, whose input a cancelled pointerdown never blurs. */
    onDragStart?: () => void;
}

interface Slot {
    id: string;
    label: string;
    el: HTMLElement;
}

export function usePreviewReorder(host: PreviewReorderHost) {
    const draggingKey = ref<string | null>(null);
    const grabbedKey = ref<string | null>(null);
    /** The target drawn on the page, or null while the question would stay where it is. */
    const dropId = ref<string | null>(null);
    const announcement = ref('');

    const isReordering = computed(() => draggingKey.value !== null || grabbedKey.value !== null);

    function announce(message: string): void {
        announcement.value = message;
    }

    function labelAt(id: string): string {
        return host.getRoot()?.querySelector<HTMLElement>(`[data-drop-id="${id}"]`)?.dataset.dropLabel ?? '';
    }

    /**
     * Write the drop, say where it went and, after a keyboard drop, put focus on the moved question's grip in the rebuilt
     * preview — or, when it went to another page, on the page's heading, so the next key does not land on nothing.
     */
    function drop(key: string, id: string | null, refocus: boolean): void {
        const name = host.labelOf(key);
        const target = id === null ? null : dropTargetOf(id);
        const placement = target === null ? null : host.place(key, target);
        if (target === null || placement === null) {
            announce(`${name} was not moved.`);
            return;
        }

        const where = labelAt(id ?? '');
        host.commit(key, placement);
        announce(`Dropped ${name}${where === '' ? '' : `, ${where}`}.`);

        if (!refocus) return;
        void nextTick(() => {
            const root = host.getRoot();
            const grip = root?.querySelector<HTMLElement>(`[data-preview-field="${key}"] [data-preview-grip]`);
            (grip ?? root?.querySelector<HTMLElement>('[data-section-heading]'))?.focus();
        });
    }

    // ── Pointer drag: armed on press, begun past the threshold ───────────────────────────────────────────────────────────
    function targetAt(x: number, y: number, key: string): string | null {
        const el = document.elementFromPoint(x, y) as HTMLElement | null;
        const root = host.getRoot();
        if (!el || !root || !root.contains(el)) return null;

        const zone = el.closest<HTMLElement>('[data-drop-zone]');
        if (zone) return zone.dataset.dropId ?? null;

        // Over a page's questions: above the first whose middle is below the pointer, else below the last. The dragged
        // row is left out by its key, so it is never its own neighbour.
        const section = el.closest<HTMLElement>('[data-section]');
        if (!section) return null;
        const rows = Array.from(section.querySelectorAll<HTMLElement>('[data-drop-row]')).filter(
            (row) => row.dataset.dropId !== `before:${key}`,
        );
        for (const row of rows) {
            const rect = row.getBoundingClientRect();
            if (y < rect.top + rect.height / 2) return row.dataset.dropId ?? null;
        }
        const last = rows[rows.length - 1];
        return last === undefined ? null : `after:${(last.dataset.dropId ?? '').slice('before:'.length)}`;
    }

    function onGripPointerDown(event: PointerEvent, key: string): void {
        if (event.button !== 0 || isReordering.value) return;
        event.preventDefault();
        const pointerId = event.pointerId;
        const startX = event.clientX;
        const startY = event.clientY;
        let started = false;

        const finish = (): void => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', cancel);
            window.removeEventListener('keydown', escape, true);
            draggingKey.value = null;
        };
        const abort = (): void => {
            finish();
            dropId.value = null;
            if (started) announce('Reorder cancelled.');
        };
        const move = (e: PointerEvent): void => {
            if (e.pointerId !== pointerId) return;
            if (!started) {
                if (Math.hypot(e.clientX - startX, e.clientY - startY) < DRAG_THRESHOLD) return;
                started = true;
                host.onDragStart?.();
                draggingKey.value = key;
                announce(`Dragging ${host.labelOf(key)}. Move over a position and release to drop.`);
            }
            const next = targetAt(e.clientX, e.clientY, key);
            if (next !== dropId.value) dropId.value = next;
        };
        const up = (e: PointerEvent): void => {
            if (e.pointerId !== pointerId) return;
            const id = dropId.value;
            finish();
            dropId.value = null;
            if (started) drop(key, id, false);
        };
        const cancel = (e: PointerEvent): void => {
            if (e.pointerId === pointerId) abort();
        };
        const escape = (e: KeyboardEvent): void => {
            if (e.key !== 'Escape') return;
            if (started) e.preventDefault();
            abort();
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', cancel);
        window.addEventListener('keydown', escape, true);
    }

    // ── Keyboard grab-mode ───────────────────────────────────────────────────────────────────────────────────────────────
    /**
     * The places the grabbed question can step to, in page order: its own place, then every other place that would move
     * it, each once — "above the question below me" is where it already is, and the strip's entry for this page is the
     * same place as this page's own end.
     */
    function slots(key: string): Slot[] {
        const own = `before:${key}`;
        const seen = new Set<string>();
        const out: Slot[] = [];
        for (const el of host.getRoot()?.querySelectorAll<HTMLElement>('[data-drop-row], [data-drop-zone]') ?? []) {
            const id = el.dataset.dropId ?? '';
            const target = dropTargetOf(id);
            if (target === null) continue;
            const placement = id === own ? 'own' : host.place(key, target);
            if (placement === null) continue;
            const identity = JSON.stringify(placement);
            if (seen.has(identity)) continue;
            seen.add(identity);
            out.push({ id, label: el.dataset.dropLabel ?? '', el });
        }

        return out;
    }

    function step(key: string, direction: -1 | 1): void {
        const all = slots(key);
        const at = all.findIndex((slot) => slot.id === (dropId.value ?? `before:${key}`));
        const next = all[at + direction];
        if (at < 0 || next === undefined) {
            announce(`Already at the ${direction === 1 ? 'end' : 'start'}.`);
            return;
        }
        dropId.value = next.id === `before:${key}` ? null : next.id;
        next.el.scrollIntoView?.({ block: 'nearest' });
        announce(dropId.value === null ? 'Back where it was.' : `${next.label}.`);
    }

    function cancelGrab(): void {
        grabbedKey.value = null;
        dropId.value = null;
        announce('Reorder cancelled.');
    }

    function onGripKeydown(event: KeyboardEvent, key: string): void {
        if (grabbedKey.value === null) {
            if ((event.key === 'Enter' || event.key === ' ') && draggingKey.value === null) {
                event.preventDefault();
                host.onDragStart?.();
                grabbedKey.value = key;
                dropId.value = null;
                announce(`Grabbed ${host.labelOf(key)}. Use up and down arrows to move, Enter to drop, Escape to cancel.`);
            }
            return;
        }
        if (grabbedKey.value !== key) return;

        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            step(key, event.key === 'ArrowDown' ? 1 : -1);
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            const id = dropId.value;
            grabbedKey.value = null;
            dropId.value = null;
            drop(key, id, true);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            cancelGrab();
        }
    }

    /** A grab ends when its grip loses focus, so a key pressed elsewhere never moves it. */
    function onGripBlur(key: string): void {
        if (grabbedKey.value === key) cancelGrab();
    }

    return { draggingKey, grabbedKey, dropId, announcement, isReordering, onGripPointerDown, onGripKeydown, onGripBlur };
}
