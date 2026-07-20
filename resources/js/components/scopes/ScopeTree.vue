<script setup lang="ts">
/**
 * The scoping hierarchy tree (Increment G10b2) — the app's first tree widget. No design-system primitive
 * exists for this, and there is not one other `role="tree"` in the repo, so every decision here becomes the
 * precedent for the next one. The load-bearing ones, with their reasons:
 *
 * FLAT DOM. One <li role="treeitem"> per visible node, no role="group" nesting. `tree`'s required owned
 * elements are ['group','treeitem'], so a treeitem is a valid DIRECT child; the hierarchy travels in
 * aria-level/aria-setsize/aria-posinset, which user agents expose in preference to structural values. The
 * real reason to prefer it is that visibility, sibling sets and ancestor walks all become one linear pass
 * over a path-ordered list. NOTE the roles must be explicit on BOTH ul and li — a generic ul/li role left
 * between tree and treeitem fails axe's aria-required-parent.
 *
 * NOTHING ROLE-FUL, aria-*-CARRYING OR FOCUSABLE MAY BE A DIRECT CHILD OF THE TREE. axe's getOwnedRoles
 * pushes any such child as an OWNED child instead of recursing past it, and anything outside
 * ['group','treeitem'] then fails aria-required-children — critical, wcag2a, merge-blocking. That is why
 * the aria-live announcer and the empty state are siblings of the <ul>, not inside it. (BuilderCanvas gets
 * away with an announcer inside .canvas only because .canvas carries no role.)
 *
 * SELECTION DOES NOT FOLLOW FOCUS. Arrow-stepping must not fire the detail panel's sidecar fetches; Enter
 * or Space selects. Two separate pieces of state: `activeId` is the local DOM focus cursor, `selectedId` is
 * what the panel shows. Conflating them is the usual bug.
 *
 * ROW ACTIONS LIVE IN THE DETAIL PANEL. The grip is the row's only focusable descendant. A treeitem holding
 * four buttons is not an axe failure (nested-interactive cannot fire on a treeitem — its matcher keys on
 * childrenPresentational, which treeitem lacks) but it does explode the tab order and pollute every row's
 * accessible name via name-from-contents. Hence the explicit aria-labelledby, and roving tabindex extended
 * to cover the grip.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { MdsBadge, MdsIcon } from '@meridian/design-system';
import type { ScopeNodeRow } from './types';

const props = defineProps<{
    nodes: ScopeNodeRow[];
    selectedId: string | null;
    /** Set while a move is being aimed — the row the moved node would land under. */
    dropTargetId?: string | null;
    /** The node currently grabbed for a move, if any. */
    grabbedId?: string | null;
}>();

const emit = defineEmits<{
    select: [id: string];
    gripPointerDown: [event: PointerEvent, id: string];
    gripKeydown: [event: KeyboardEvent, id: string];
}>();

const treeRoot = ref<HTMLElement | null>(null);
const activeId = ref<string | null>(null);
const expanded = ref(new Set<string>());

const byId = computed(() => new Map(props.nodes.map((n) => [n.id, n])));

/**
 * props.nodes is path-ordered (depth-first pre-order), so a collapsed node's descendants are exactly the
 * contiguous run that follows it at a greater depth. One pass, no recursion, no tree building.
 *
 * Read off a computed over props — never a `ref([...props.nodes])`. Every mutation on this page is an
 * Inertia visit with preserveState, so the component is NEVER remounted and anything seeded in setup()
 * would be stale forever after the first change.
 */
const visibleRows = computed<ScopeNodeRow[]>(() => {
    const out: ScopeNodeRow[] = [];
    let skipBelowDepth: number | null = null;
    for (const node of props.nodes) {
        if (skipBelowDepth !== null && node.depth > skipBelowDepth) continue;
        skipBelowDepth = null;
        out.push(node);
        if (node.has_children && !expanded.value.has(node.id)) skipBelowDepth = node.depth;
    }
    return out;
});

function ancestorChain(id: string | null): string[] {
    const chain: string[] = [];
    let node = id ? byId.value.get(id) : undefined;
    while (node) {
        chain.push(node.id);
        node = node.parent_id ? byId.value.get(node.parent_id) : undefined;
    }
    return chain;
}

/**
 * The ancestor chain of the focus cursor, captured from the CURRENT props before a mutation is dispatched.
 *
 * It has to be a snapshot: after a delete the node is already gone from props.nodes, so resolving its
 * ancestors from the refreshed map returns nothing and focus would silently snap to row 0 — the single
 * outcome the restore logic exists to prevent.
 */
let fallbackChain: string[] = [];

function rememberFocusAnchor(): void {
    fallbackChain = ancestorChain(activeId.value);
}

function focusRow(id: string): void {
    activeId.value = id;
    void nextTick(() => {
        treeRoot.value?.querySelector<HTMLElement>(`[data-node-id="${id}"]`)?.focus();
    });
}

// Keep the focus cursor on something that still exists after a server round-trip. Lands on the nearest
// surviving ancestor rather than row 0, and only pulls DOM focus if focus was already inside the tree —
// otherwise a background prop refresh would yank the caret out of whatever the user was typing.
watch(
    visibleRows,
    (rows) => {
        if (rows.length === 0) {
            activeId.value = null;
            return;
        }
        if (activeId.value !== null && rows.some((r) => r.id === activeId.value)) return;

        const held = treeRoot.value?.contains(document.activeElement) ?? false;
        const surviving = fallbackChain.find((id) => rows.some((r) => r.id === id));
        activeId.value = surviving ?? props.selectedId ?? rows[0].id;
        if (!rows.some((r) => r.id === activeId.value)) activeId.value = rows[0].id;
        if (held) focusRow(activeId.value);
    },
    { immediate: true },
);

// Deep-link/refresh seeding: open every ancestor of the selected node so its branch renders expanded.
watch(
    () => props.selectedId,
    (id) => {
        for (const ancestor of ancestorChain(id)) expanded.value.add(ancestor);
    },
    { immediate: true },
);

function toggle(row: ScopeNodeRow): void {
    if (!row.has_children) return;
    if (expanded.value.has(row.id)) expanded.value.delete(row.id);
    else expanded.value.add(row.id);
}

let typeBuffer = '';
let typeTimer: ReturnType<typeof setTimeout> | undefined;

/**
 * APG authoring practice, NOT a WCAG requirement — no success criterion mandates type-ahead and axe cannot
 * test an interaction model at all. Kept because a real hierarchy runs to hundreds of nodes and arrow-
 * stepping is punishing; safe to cut if this file ever needs trimming.
 */
function typeahead(event: KeyboardEvent, index: number): boolean {
    if (event.key.length !== 1 || event.key === ' ' || event.ctrlKey || event.metaKey || event.altKey) return false;
    clearTimeout(typeTimer);
    typeBuffer += event.key.toLowerCase();
    typeTimer = setTimeout(() => { typeBuffer = ''; }, 500);

    const rows = visibleRows.value;
    for (let k = 1; k <= rows.length; k++) {
        const row = rows[(index + k) % rows.length];
        if (row.name.toLowerCase().startsWith(typeBuffer)) {
            focusRow(row.id);
            return true;
        }
    }
    return false;
}

function onRowKeydown(event: KeyboardEvent, node: ScopeNodeRow, index: number): void {
    // Keys pressed on a control INSIDE the row belong to that control. This one guard is what stops the
    // grip's Enter/Space grab from ALSO selecting the node, and its arrow keys from ALSO walking tree focus
    // while a node is grabbed. Total, and needs no coordination with the grip's own handler.
    if (event.target !== event.currentTarget) return;

    // While a move is in flight the ROW owns the keys too, not just the grip. Grab-mode can be entered from
    // the detail panel's "Move…" button, which focuses the row rather than the grip — without this the arrow
    // keys would walk tree focus and Enter would re-select, and the move could never be aimed or dropped.
    const grabbed = props.grabbedId;
    if (grabbed) {
        emit('gripKeydown', event, grabbed);
        return;
    }

    const rows = visibleRows.value;
    switch (event.key) {
        case 'ArrowDown':
            // APG trees CLAMP, they do not wrap. (ConfigPanel's tab strip uses a modulo — do not import it.)
            if (index >= rows.length - 1) return;
            focusRow(rows[index + 1].id);
            break;
        case 'ArrowUp':
            if (index <= 0) return;
            focusRow(rows[index - 1].id);
            break;
        case 'ArrowRight':
            // Closed parent → open it, focus stays. Open parent → descend to the first child. Leaf → nothing.
            if (!node.has_children) return;
            if (!expanded.value.has(node.id)) expanded.value.add(node.id);
            else if (rows[index + 1]?.parent_id === node.id) focusRow(rows[index + 1].id);
            break;
        case 'ArrowLeft':
            // Open parent → close it, focus stays. Otherwise → ascend to the parent row.
            if (node.has_children && expanded.value.has(node.id)) expanded.value.delete(node.id);
            else if (node.parent_id) focusRow(node.parent_id);
            else return;
            break;
        case 'Home':
            focusRow(rows[0].id);
            break;
        case 'End':
            focusRow(rows[rows.length - 1].id);
            break;
        case 'Enter':
        case ' ':
            emit('select', node.id);
            break;
        default:
            if (!typeahead(event, index)) return;
            break;
    }
    event.preventDefault();
}

defineExpose({ rememberFocusAnchor, focusRow, expand: (id: string) => expanded.value.add(id) });
</script>

<template>
    <ul
        v-if="visibleRows.length"
        ref="treeRoot"
        class="scopes__tree"
        role="tree"
        aria-label="Scope hierarchy"
    >
        <li
            v-for="(row, index) in visibleRows"
            :key="row.id"
            role="treeitem"
            class="scopes__row"
            :class="{
                'is-selected': row.id === selectedId,
                'is-inactive': !row.is_active,
                'is-grabbed': row.id === grabbedId,
                'is-drop-target': row.id === dropTargetId,
            }"
            :data-node-id="row.id"
            :aria-level="row.depth + 1"
            :aria-setsize="row.setsize"
            :aria-posinset="row.posinset"
            :aria-expanded="!row.has_children ? undefined : expanded.has(row.id) ? 'true' : 'false'"
            :aria-selected="row.id === selectedId ? 'true' : undefined"
            :aria-labelledby="`scope-name-${row.id}`"
            :tabindex="row.id === activeId ? 0 : -1"
            :style="{ '--depth': String(row.depth) }"
            @keydown="onRowKeydown($event, row, index)"
            @click="emit('select', row.id)"
        >
            <span class="scopes__row-inner">
                <!--
                    A non-focusable span, not a button: expansion state already lives on the treeitem as
                    aria-expanded, so a second control would duplicate it and double every row's tab stop.
                    Keyboard users expand with ArrowRight/ArrowLeft (2.1.1 satisfied); this exists purely as
                    a mouse target, and still carries a >=24x24 hit area for WCAG 2.2 SC 2.5.8.
                -->
                <span
                    class="scopes__twisty"
                    :class="{ 'is-leaf': !row.has_children }"
                    aria-hidden="true"
                    @click.stop="toggle(row)"
                >
                    <MdsIcon v-if="row.has_children" :name="expanded.has(row.id) ? 'chevron-down' : 'chevron-right'" size="sm" />
                </span>

                <span :id="`scope-name-${row.id}`" class="scopes__name">{{ row.name }}</span>

                <MdsBadge v-if="!row.is_active" variant="neutral" label="Inactive" />
                <span v-if="row.form_count > 0" class="scopes__count">{{ row.form_count }} forms</span>
                <span v-if="row.grant_count > 0" class="scopes__count">{{ row.grant_count }} granted</span>

                <button
                    v-if="row.can.update"
                    type="button"
                    class="scopes__grip"
                    :tabindex="row.id === activeId ? 0 : -1"
                    :aria-label="`Move ${row.name}. Press Enter to grab, then arrow keys; or drag.`"
                    @click.stop
                    @pointerdown="emit('gripPointerDown', $event, row.id)"
                    @keydown.stop="emit('gripKeydown', $event, row.id)"
                >
                    <MdsIcon name="grip" size="sm" />
                </button>
            </span>
        </li>
    </ul>
</template>

<style scoped>
.scopes__tree {
    list-style: none;
    margin: 0;
    padding: 0;
}

.scopes__row {
    border-radius: var(--mds-radius-sm);
    color: var(--mds-color-text-body);
    cursor: pointer;
}

.scopes__row:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.scopes__row.is-selected {
    background-color: var(--mds-color-action-primary-tint);
}

.scopes__row.is-inactive .scopes__name {
    color: var(--mds-color-text-secondary);
}

.scopes__row.is-grabbed {
    opacity: 0.5;
}

.scopes__row.is-drop-target {
    outline: 2px dashed var(--mds-color-action-primary-bg);
    outline-offset: -2px;
}

/*
    Depth indent as a per-row custom property rather than nested DOM (the tree is flat) or an
    .is-depth-0..12 class ladder, which would copy ScopeNodeService::MAX_DEPTH into a stylesheet with no way
    to keep the two in sync — raise the cap server-side and depth 13 would silently render flush-left. The
    binding carries one unitless integer; every LENGTH stays a token and scoped CSS owns all of it.
    Padding, not margin, so the full-width row keeps its hit area and the focus ring wraps the whole row.
*/
.scopes__row-inner {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    min-height: 36px;
    padding-block: var(--mds-space-1);
    padding-inline-end: var(--mds-space-2);
    padding-inline-start: calc(var(--mds-space-2) + var(--depth, 0) * var(--indent-step, 20px));
}

.scopes__twisty {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /* >=24x24: SC 2.5.8 applies to a pointer target whether or not axe evaluates it, and axe cannot — its
       target-size rule only runs on focusable nodes, and this one deliberately is not. */
    inline-size: 24px;
    block-size: 24px;
    flex: 0 0 auto;
    color: var(--mds-color-text-secondary);
}

.scopes__name {
    /*
        min-width:0 is the ACTUAL overflow fix, not the indent cap. A flex item defaults to
        min-width:auto and refuses to shrink below its min-content width, so a long name at depth 12 pushes
        the row wider than the viewport and fails responsive-axe's horizontal-overflow assertion at 375px.
    */
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.scopes__count {
    flex: 0 0 auto;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.scopes__grip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    inline-size: 28px;
    block-size: 28px;
    flex: 0 0 auto;
    margin-inline-start: auto;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-secondary);
    cursor: grab;
    /* Keeps a touch-drag from scrolling the pane. MdsIconButton cannot set this (scoped styles), which is
       why the grip is hand-rolled — same reason .canvas__grip is. */
    touch-action: none;
}

.scopes__grip:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

@media (max-width: 640px) {
    .scopes__row-inner {
        --indent-step: 12px;
    }
}
</style>
