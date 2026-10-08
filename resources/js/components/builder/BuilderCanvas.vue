<script setup lang="ts">
/**
 * The builder's centre pane: the form's structure as an ordered list of sections + fields. Selection
 * drives the config pane. **Reordering (Increment D4b)** is via the left drag grip on each row —
 * hand-rolled pointer drag AND a keyboard grab-mode (Enter to grab → arrow keys move up/down across
 * section boundaries → Enter to drop, Escape to cancel), both announced through an assertive aria-live
 * region (see useCanvasReorder). A field can also be reparented from its config panel's Section select
 * (covers empty sections). No third-party DnD library — those inject aria-hidden mirror DOM and fail axe.
 *
 * M150 (`D103`, the user's Round 2 notes): the grip is drawn heavier, rows GLIDE to their new places
 * (useFlipReorder), and a question's label is edited in place — "Edit label" or a double-click swaps the row's
 * main button for an InlineLabelEdit, since an input cannot live inside a button.
 */
import { computed, nextTick, ref } from 'vue';
import { MdsBadge, MdsButton, MdsEmptyState, MdsIcon, MdsIconButton, statusVariant } from '@meridian/design-system';
import InlineLabelEdit from './InlineLabelEdit.vue';
import type { LocalField } from './types';
import type { BuilderStore } from './useBuilderStore';
import { useCanvasReorder } from './useCanvasReorder';
import { useFlipReorder } from './useFlipReorder';

const props = defineProps<{ store: BuilderStore; fieldTypeLabels: Record<string, string> }>();
// M139 (`R-598b9100`): "Add a question here" on an empty section — the builder selects it and opens the palette.
const emit = defineEmits<{ 'add-question': [sectionUid: string | null] }>();

const store = props.store;
const groups = store.groups;
const selection = store.selection;

const canvasRoot = ref<HTMLElement | null>(null);

// ── A label edited in place (M150, `R-34edf1f5`) ────────────────────────────────────────────────────────────────────
const editingUid = ref<string | null>(null);
const editor = ref<InstanceType<typeof InlineLabelEdit> | null>(null);

function setEditor(instance: unknown): void {
    editor.value = (instance as InstanceType<typeof InlineLabelEdit> | null) ?? null;
}

const reorder = useCanvasReorder(store, () => canvasRoot.value, {
    // A drag's pointerdown is cancelled, so it never blurs an open editor: end the edit as a blur would.
    onDragStart: () => editor.value?.commit('blur'),
});
const {
    draggingUid,
    grabbedUid,
    announcement,
    isReordering,
    onFieldPointerDown,
    onFieldKeydown,
    onSectionPointerDown,
    onSectionKeydown,
} = reorder;

function startEdit(uid: string): void {
    if (isReordering.value) return;
    // Selecting first flushes a pending settings-pane edit, and puts a refused save where its message shows.
    store.select({ kind: 'field', uid });
    editingUid.value = uid;
}

function endEdit(uid: string, refocus: boolean): void {
    editingUid.value = null;
    if (!refocus) return;
    void nextTick(() => canvasRoot.value?.querySelector<HTMLElement>(`[data-field-uid="${uid}"] .canvas__field-main`)?.focus());
}

function commitLabel(uid: string, value: string, via: 'key' | 'blur'): void {
    store.renameField(uid, value);
    // Enter keeps the keyboard on the row; a blur went somewhere the user chose, and focus stays there.
    endEdit(uid, via === 'key');
}

// ── The glide (M150, `R-adce6e14`) ──────────────────────────────────────────────────────────────────────────────────
const order = computed(() =>
    groups.value.map((group) => `${group.section?.uid ?? '-'}:${group.fields.map((field) => field.uid).join(',')}`).join('|'),
);
useFlipReorder(() => canvasRoot.value, order);

function isSelectedField(uid: string): boolean {
    return selection.value?.kind === 'field' && selection.value.uid === uid;
}
function isSelectedSection(uid: string): boolean {
    return selection.value?.kind === 'section' && selection.value.uid === uid;
}
function isActive(uid: string): boolean {
    return grabbedUid.value === uid || draggingUid.value === uid;
}
function typeLabel(field: LocalField): string {
    return props.fieldTypeLabels[field.field_type] ?? field.field_type;
}
function requiredBadge(field: LocalField): string | null {
    return field.is_required === 'required' ? 'Required' : field.is_required === 'conditional' ? 'Conditional' : null;
}

const hasContent = (): boolean => groups.value.some((g) => g.fields.length > 0) || groups.value.length > 1;
</script>

<template>
    <div ref="canvasRoot" class="canvas" :class="{ 'canvas--dragging': draggingUid !== null }">
        <!-- Assertive status region for drag/keyboard-reorder announcements (visually hidden). -->
        <div class="canvas__sr" role="status" aria-live="assertive">{{ announcement }}</div>

        <div v-if="!hasContent()" class="canvas__empty">
            <MdsEmptyState
                headline="An empty form"
                description="Add a field from the left, or start with a section to group related questions."
            >
                <template #action>
                    <MdsButton variant="secondary" icon-left="layout" @click="store.addSection()">
                        Add a section
                    </MdsButton>
                </template>
            </MdsEmptyState>
        </div>

        <template v-else>
            <div
                v-for="group in groups"
                :key="group.section?.uid ?? 'ungrouped'"
                class="canvas__group"
                :data-group-key="group.section?.uid ?? 'ungrouped'"
            >
                <!-- Section header (skip the implicit ungrouped bucket) -->
                <div
                    v-if="group.section"
                    class="canvas__section"
                    :class="{ 'is-selected': isSelectedSection(group.section.uid), 'is-active': isActive(group.section.uid) }"
                    :data-section-uid="group.section.uid"
                >
                    <button
                        type="button"
                        class="canvas__grip"
                        :aria-label="`Reorder section ${group.section.label}. Press Enter or Space to grab, then arrow keys; or drag.`"
                        @pointerdown="onSectionPointerDown($event, group.section.uid)"
                        @keydown="onSectionKeydown($event, group.section.uid)"
                    >
                        <MdsIcon name="grip" size="md" />
                    </button>
                    <button
                        type="button"
                        class="canvas__section-head"
                        :aria-pressed="isSelectedSection(group.section.uid)"
                        @click="store.select({ kind: 'section', uid: group.section.uid })"
                    >
                        <MdsIcon name="layout" size="sm" />
                        <span class="canvas__section-label">{{ group.section.label }}</span>
                        <MdsBadge v-if="group.section.is_repeatable" v-bind="statusVariant('draft')" label="Repeatable" />
                    </button>
                    <div class="canvas__section-actions">
                        <MdsIconButton
                            icon="trash"
                            label="Delete section"
                            variant="danger"
                            size="sm"
                            @click="store.deleteSection(group.section.uid)"
                        />
                    </div>
                </div>

                <!-- Fields in this group (a drop zone; empty groups keep a droppable min-height) -->
                <ul class="canvas__fields" :data-drop-group="group.section ? group.section.id : 'ungrouped'">
                    <li
                        v-for="field in group.fields"
                        :key="field.uid"
                        :data-field-uid="field.uid"
                        :data-dragging="draggingUid === field.uid ? 'true' : undefined"
                    >
                        <div class="canvas__field" :class="{ 'is-selected': isSelectedField(field.uid), 'is-active': isActive(field.uid) }">
                            <button
                                type="button"
                                class="canvas__grip"
                                :aria-label="`Reorder ${field.label || 'field'}. Press Enter or Space to grab, then arrow keys; or drag.`"
                                @pointerdown="onFieldPointerDown($event, field.uid)"
                                @keydown="onFieldKeydown($event, field.uid)"
                            >
                                <MdsIcon name="grip" size="md" />
                            </button>
                            <!-- No type caption while editing: at 375px its 84px floor left the input about 50px wide. -->
                            <div v-if="editingUid === field.uid" class="canvas__field-editing">
                                <InlineLabelEdit
                                    :ref="setEditor"
                                    :value="field.label"
                                    label="Question label"
                                    @commit="(value, via) => commitLabel(field.uid, value, via)"
                                    @cancel="endEdit(field.uid, true)"
                                />
                            </div>
                            <button
                                v-else
                                type="button"
                                class="canvas__field-main"
                                :aria-pressed="isSelectedField(field.uid)"
                                @click="store.select({ kind: 'field', uid: field.uid })"
                                @dblclick="startEdit(field.uid)"
                            >
                                <span class="canvas__field-type">{{ typeLabel(field) }}</span>
                                <span class="canvas__field-label">{{ field.label || '(untitled)' }}</span>
                                <MdsBadge
                                    v-if="requiredBadge(field)"
                                    v-bind="statusVariant('published')"
                                    :label="requiredBadge(field) ?? ''"
                                />
                            </button>
                            <div class="canvas__field-actions">
                                <MdsIconButton
                                    v-if="editingUid !== field.uid"
                                    icon="edit"
                                    :label="`Edit label of ${field.label || 'this question'}`"
                                    size="sm"
                                    :disabled="isReordering"
                                    @click="startEdit(field.uid)"
                                />
                                <MdsIconButton icon="copy" label="Duplicate field" size="sm" @click="store.duplicateField(field.uid)" />
                                <MdsIconButton
                                    icon="trash"
                                    label="Delete field"
                                    variant="danger"
                                    size="sm"
                                    @click="store.deleteField(field.uid)"
                                />
                            </div>
                        </div>
                    </li>
                    <!-- M139 (R-598b9100): the drag into an empty section always worked; the words never said so. -->
                    <li v-if="group.section && group.fields.length === 0" class="canvas__section-empty" data-section-empty>
                        <span>No questions in this section yet. Drag one in by its handle, or add one.</span>
                        <MdsButton variant="tertiary" size="sm" icon-left="plus" @click="emit('add-question', group.section.uid)">
                            Add a question here
                        </MdsButton>
                    </li>
                </ul>
            </div>

            <div class="canvas__foot">
                <MdsButton variant="tertiary" icon-left="layout" @click="store.addSection()">Add section</MdsButton>
            </div>
        </template>
    </div>
</template>

<style scoped>
.canvas {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    padding: var(--mds-space-6);
    height: 100%;
    overflow-y: auto;
}

.canvas__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}

.canvas__empty {
    margin: auto;
    max-width: 420px;
}

.canvas__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.canvas__section {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    padding: var(--mds-space-1) var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.canvas__section.is-selected,
.canvas__field.is-selected {
    border-color: var(--mds-color-action-primary-bg);
    box-shadow: 0 0 0 1px var(--mds-color-action-primary-bg);
}

.canvas__section.is-active,
.canvas__field.is-active {
    border-color: var(--mds-color-action-primary-bg);
    box-shadow: 0 0 0 2px var(--mds-color-action-primary-bg);
}

/* Drag grip — the reorder affordance for both pointer and keyboard. touch-action:none keeps a
   touch-drag from scrolling the canvas. */
/* M150 (`R-91c1792e`, "the icon for the draggable … is not noticeable"): the colour was never the problem
   (text-secondary is about 6:1 on the surface) — the design system's grip is six zero-length strokes at 1.5,
   dots about a pixel across. The builder draws it at md with its own stroke of 3, about 2.5px dots. The glyph
   itself is shared (ScopeTree, the scopes page) and is filed against the design system rather than changed here. */
.canvas__grip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-secondary);
    cursor: grab;
    touch-action: none;
}
.canvas__grip :deep(svg) {
    stroke-width: 3;
}
.canvas__grip:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}
.canvas--dragging,
.canvas--dragging .canvas__grip {
    cursor: grabbing;
}
.canvas__grip:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

.canvas__section-head {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    flex: 1;
    min-width: 0;
    padding: var(--mds-space-1) 0;
    border: 0;
    background: transparent;
    color: var(--mds-color-text-heading);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-lg-font-size);
    font-weight: var(--mds-font-weight-semibold);
    text-align: left;
    cursor: pointer;
}

.canvas__section-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.canvas__section-head:focus-visible,
.canvas__field-main:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.canvas__section-actions,
.canvas__field-actions {
    display: inline-flex;
    gap: var(--mds-space-0-5);
    flex-shrink: 0;
}

.canvas__fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-height: var(--mds-space-6);
    margin: 0;
    padding: 0 0 0 var(--mds-space-4);
    list-style: none;
}

/* While a pointer drag is active, outline every drop zone so empty groups are visibly droppable. */
.canvas--dragging .canvas__fields {
    outline: 1px dashed var(--mds-color-border-default);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.canvas__field {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

/* The row being pointer-dragged: dimmed + click-through so elementFromPoint sees the drop zone. */
li[data-dragging='true'] {
    pointer-events: none;
}
li[data-dragging='true'] .canvas__field {
    opacity: 0.5;
}

.canvas__field-main {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    flex: 1;
    min-width: 0;
    padding: var(--mds-space-1) 0;
    border: 0;
    background: transparent;
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    text-align: left;
    cursor: pointer;
}

/* The row while its label is edited: the main button's footprint, all of it given to the input. */
.canvas__field-editing {
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 0;
}

.canvas__field-type {
    flex-shrink: 0;
    min-width: 84px;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.canvas__field-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--mds-type-body-lg-font-size);
}

.canvas__section-empty {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2) var(--mds-space-3);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
}

.canvas__foot {
    padding-top: var(--mds-space-2);
}
</style>
