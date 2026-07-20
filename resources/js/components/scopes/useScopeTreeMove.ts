// Re-parenting a scope node (Increment G10b2) — a deliberate SIBLING of components/builder/useCanvasReorder,
// not a reuse of it. That file is typed to BuilderStore and drives store.placeField/stepFieldAcross, none of
// which exist outside the builder; what carries over is the shape and the decisions behind it:
//
//   - NO DnD LIBRARY. They inject aria-hidden drag-mirror DOM and steal focus, failing the merge-blocking
//     axe gate. Hand-rolled pointer capture on a real <button> grip, same as the canvas.
//   - window pointermove/pointerup, plus a CAPTURE-phase keydown so Escape wins over anything below.
//   - document.elementFromPoint + closest('[data-node-id]') hit-testing.
//   - one `announcement` ref feeding an assertive live region.
//
// Two things differ, both forced by the domain:
//
//   1. IT MUTATES NOTHING AND NEVER LIVE-PREVIEWS. A canvas reorder is a local array move that the server
//      then accepts. A tree move is a server round-trip that can be REFUSED — a cycle, or a subtree that
//      would breach the depth cap — so this moves a drop-target cursor and asks for confirmation. That is
//      also an accessibility property, not just a correctness one: because the DOM never reorders mid-grab,
//      the focused grip can never be unmounted out from under the user.
//   2. IT PICKS A PARENT, NOT A POSITION. ScopeNodeService::move(node, ?newParent) takes no sibling index,
//      so there is no before/after — only "inside". ArrowUp/ArrowDown walk the candidate parents; the row
//      above the first one is the synthetic "top level" target, which is how move(node, null) is reachable.

import { computed, ref } from 'vue';
import type { ScopeNodeRow } from './types';

type Options = {
    rows: () => ScopeNodeRow[];
    onCommit: (nodeId: string, parentId: string | null) => void;
};

export function useScopeTreeMove(options: Options) {
    const grabbedId = ref<string | null>(null);
    const dropTargetId = ref<string | null>(null);
    const confirming = ref(false);
    const announcement = ref('');
    /** True while the grab was entered from the keyboard, so Escape/Enter semantics apply. */
    const keyboardMode = ref(false);

    function announce(message: string): void {
        announcement.value = message;
    }

    function nameOf(id: string | null): string | null {
        if (id === null) return null;
        return options.rows().find((r) => r.id === id)?.name ?? null;
    }

    const movingName = computed(() => nameOf(grabbedId.value) ?? 'scope');
    const targetName = computed(() => nameOf(dropTargetId.value));

    /**
     * Candidate parents: every node except the moving one and its own descendants — grafting a node onto its
     * own subtree is the cycle the server refuses, so it is never offered. The rows are path-ordered, so the
     * subtree is the contiguous run of deeper nodes following it.
     */
    function candidates(nodeId: string): ScopeNodeRow[] {
        const rows = options.rows();
        const index = rows.findIndex((r) => r.id === nodeId);
        if (index < 0) return rows;

        const depth = rows[index].depth;
        let end = index + 1;
        while (end < rows.length && rows[end].depth > depth) end++;

        return rows.filter((_, i) => i < index || i >= end);
    }

    function reset(): void {
        grabbedId.value = null;
        dropTargetId.value = null;
        confirming.value = false;
        keyboardMode.value = false;
    }

    // ── Pointer drag ─────────────────────────────────────────────────────────
    function onGripPointerDown(event: PointerEvent, id: string): void {
        if (event.button !== 0 || grabbedId.value !== null) return;
        event.preventDefault();
        grabbedId.value = id;
        dropTargetId.value = null;
        announce(`Moving ${nameOf(id) ?? 'scope'}. Drag over the scope it should sit under and release.`);

        const allowed = new Set(candidates(id).map((r) => r.id));

        const move = (e: PointerEvent): void => {
            const el = document.elementFromPoint(e.clientX, e.clientY) as HTMLElement | null;
            const row = el?.closest<HTMLElement>('[data-node-id]');
            const target = row?.getAttribute('data-node-id') ?? null;
            dropTargetId.value = target !== null && allowed.has(target) ? target : null;
        };
        const finish = (): void => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('keydown', key, true);
        };
        const up = (): void => {
            finish();
            // Nothing is committed on release — a move can be refused server-side, so it is confirmed first.
            confirming.value = true;
        };
        const key = (e: KeyboardEvent): void => {
            if (e.key !== 'Escape') return;
            e.preventDefault();
            finish();
            reset();
            announce('Move cancelled.');
        };

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('keydown', key, true);
    }

    // ── Keyboard grab-mode ───────────────────────────────────────────────────
    function grabViaKeyboard(id: string): void {
        grabbedId.value = id;
        keyboardMode.value = true;
        dropTargetId.value = null;
        announce(
            `Grabbed ${nameOf(id) ?? 'scope'}. Use up and down arrows to choose a new parent, ` +
                'Enter to drop, Escape to cancel.',
        );
    }

    function onGripKeydown(event: KeyboardEvent, id: string): void {
        if (grabbedId.value === null) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                grabViaKeyboard(id);
            }
            return;
        }

        const list = candidates(grabbedId.value);

        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            const current = list.findIndex((r) => r.id === dropTargetId.value);
            // Index -1 is the synthetic "top level" target, which is what makes re-rooting reachable.
            const next = Math.max(-1, Math.min(list.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1)));
            dropTargetId.value = next < 0 ? null : list[next].id;
            announce(dropTargetId.value ? `Under ${nameOf(dropTargetId.value)}.` : 'At the top level.');
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            confirming.value = true;
        } else if (event.key === 'Escape') {
            event.preventDefault();
            reset();
            announce('Move cancelled.');
        }
    }

    /** The move awaiting a server answer, so the outcome can be read off the refreshed rows. */
    let pending: { nodeId: string; parentId: string | null } | null = null;

    function confirm(): void {
        const nodeId = grabbedId.value;
        if (nodeId === null) return;
        confirming.value = false;
        pending = { nodeId, parentId: dropTargetId.value };
        options.onCommit(nodeId, dropTargetId.value);
    }

    function cancel(): void {
        reset();
        announce('Move cancelled.');
    }

    /**
     * Announce the outcome of a committed move, driven by the REFRESHED rows arriving as props.
     *
     * Deliberately not the visit's onSuccess callback. A refusal (cycle, depth cap) comes back as
     * `back()->with('toast')` — a redirect to a 2xx page response — so onError never fires and success
     * cannot be assumed from the callback alone; the outcome has to be read off what the server actually
     * returned. Driving it from the props change gets that for free and does not depend on which Inertia
     * lifecycle hook runs, which is exactly the sort of coupling that made the first cut of this silently
     * announce nothing.
     */
    function settleIfPending(rows: ScopeNodeRow[]): void {
        if (pending === null) return;
        const { nodeId, parentId } = pending;
        pending = null;

        const landed = rows.find((r) => r.id === nodeId);
        const moved = landed !== undefined && (landed.parent_id ?? null) === parentId;
        announce(
            moved
                ? `Moved ${landed.name} ${parentId ? `under ${nameOf(parentId)}` : 'to the top level'}.`
                : 'That move was refused. The scope stayed where it was.',
        );
        reset();
    }

    return {
        grabbedId,
        dropTargetId,
        confirming,
        announcement,
        movingName,
        targetName,
        onGripPointerDown,
        onGripKeydown,
        grabViaKeyboard,
        confirm,
        cancel,
        settleIfPending,
    };
}
