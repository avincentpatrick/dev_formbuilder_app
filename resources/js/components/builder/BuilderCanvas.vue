<script setup lang="ts">
/**
 * The builder's centre pane: the form's structure as an ordered list of sections + fields. Selection
 * drives the config pane. **Reordering (Increment D4b)** is via the left drag grip on each row —
 * hand-rolled pointer drag AND a keyboard grab-mode (Enter to grab → arrow keys move up/down across
 * section boundaries → Enter to drop, Escape to cancel), both announced through an assertive aria-live
 * region (see useCanvasReorder). A field can also be reparented from its config panel's Section select
 * (covers empty sections). No third-party DnD library — those inject aria-hidden mirror DOM and fail axe.
 */
import { ref } from 'vue';
import { MdsBadge, MdsButton, MdsEmptyState, MdsIcon, MdsIconButton, statusVariant } from '@meridian/design-system';
import type { LocalField } from './types';
import type { BuilderStore } from './useBuilderStore';
import { useCanvasReorder } from './useCanvasReorder';

const props = defineProps<{ store: BuilderStore; fieldTypeLabels: Record<string, string> }>();

const store = props.store;
const groups = store.groups;
const selection = store.selection;

const canvasRoot = ref<HTMLElement | null>(null);
const reorder = useCanvasReorder(store, () => canvasRoot.value);
const { draggingUid, grabbedUid, announcement, onFieldPointerDown, onFieldKeydown, onSectionPointerDown, onSectionKeydown } =
    reorder;

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
            <div v-for="group in groups" :key="group.section?.uid ?? 'ungrouped'" class="canvas__group">
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
                        :aria-label="`Reorder section ${group.section.label}. Press Enter to grab, then arrow keys; or drag.`"
                        @pointerdown="onSectionPointerDown($event, group.section.uid)"
                        @keydown="onSectionKeydown($event, group.section.uid)"
                    >
                        <MdsIcon name="grip" size="sm" />
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
                                :aria-label="`Reorder ${field.label || 'field'}. Press Enter to grab, then arrow keys; or drag.`"
                                @pointerdown="onFieldPointerDown($event, field.uid)"
                                @keydown="onFieldKeydown($event, field.uid)"
                            >
                                <MdsIcon name="grip" size="sm" />
                            </button>
                            <button
                                type="button"
                                class="canvas__field-main"
                                :aria-pressed="isSelectedField(field.uid)"
                                @click="store.select({ kind: 'field', uid: field.uid })"
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
                    <li v-if="group.section && group.fields.length === 0" class="canvas__section-empty">
                        No fields in this section yet.
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
.canvas__grip:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
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
    padding: var(--mds-space-2) var(--mds-space-3);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
}

.canvas__foot {
    padding-top: var(--mds-space-2);
}
</style>
