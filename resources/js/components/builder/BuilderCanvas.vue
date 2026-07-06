<script setup lang="ts">
/**
 * The builder's centre pane: the form's structure as an ordered list of sections + fields. Selection
 * drives the config pane. Ordering is via explicit Move-up / Move-down controls (pointer drag lands in
 * D4b); a field can also be reparented to another section from its config panel. Every control is a real
 * button with an accessible name — the whole surface is keyboard-operable, no drag required.
 */
import { MdsBadge, MdsButton, MdsEmptyState, MdsIcon, MdsIconButton, statusVariant } from '@meridian/design-system';
import type { CanvasGroup, LocalField, Selection } from './types';

const props = defineProps<{
    groups: CanvasGroup[];
    selection: Selection;
    fieldTypeLabels: Record<string, string>;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    selectField: [uid: string];
    selectSection: [uid: string];
    moveField: [uid: string, direction: -1 | 1];
    moveSection: [uid: string, direction: -1 | 1];
    duplicateField: [uid: string];
    deleteField: [uid: string];
    deleteSection: [uid: string];
    addSection: [];
}>();

function isSelectedField(uid: string): boolean {
    return props.selection?.kind === 'field' && props.selection.uid === uid;
}
function isSelectedSection(uid: string): boolean {
    return props.selection?.kind === 'section' && props.selection.uid === uid;
}
function typeLabel(field: LocalField): string {
    return props.fieldTypeLabels[field.field_type] ?? field.field_type;
}
function requiredBadge(field: LocalField): string | null {
    return field.is_required === 'required' ? 'Required' : field.is_required === 'conditional' ? 'Conditional' : null;
}

const hasContent = (): boolean => props.groups.some((g) => g.fields.length > 0) || props.groups.length > 1;
</script>

<template>
    <div class="canvas">
        <div v-if="!hasContent()" class="canvas__empty">
            <MdsEmptyState
                headline="An empty form"
                description="Add a field from the left, or start with a section to group related questions."
            >
                <template #action>
                    <MdsButton variant="secondary" icon-left="layout" :disabled="disabled" @click="emit('addSection')">
                        Add a section
                    </MdsButton>
                </template>
            </MdsEmptyState>
        </div>

        <template v-else>
            <div v-for="(group, gi) in groups" :key="group.section?.uid ?? 'ungrouped'" class="canvas__group">
                <!-- Section header (skip the implicit ungrouped bucket) -->
                <div
                    v-if="group.section"
                    class="canvas__section"
                    :class="{ 'is-selected': isSelectedSection(group.section.uid) }"
                >
                    <button
                        type="button"
                        class="canvas__section-head"
                        :aria-pressed="isSelectedSection(group.section.uid)"
                        @click="emit('selectSection', group.section.uid)"
                    >
                        <MdsIcon name="layout" size="sm" />
                        <span class="canvas__section-label">{{ group.section.label }}</span>
                        <MdsBadge v-if="group.section.is_repeatable" v-bind="statusVariant('draft')" label="Repeatable" />
                    </button>
                    <div class="canvas__section-actions">
                        <MdsIconButton
                            icon="chevron-up"
                            label="Move section up"
                            size="sm"
                            :disabled="disabled || gi <= 1"
                            @click="emit('moveSection', group.section.uid, -1)"
                        />
                        <MdsIconButton
                            icon="chevron-down"
                            label="Move section down"
                            size="sm"
                            :disabled="disabled || gi >= groups.length - 1"
                            @click="emit('moveSection', group.section.uid, 1)"
                        />
                        <MdsIconButton
                            icon="trash"
                            label="Delete section"
                            variant="danger"
                            size="sm"
                            :disabled="disabled"
                            @click="emit('deleteSection', group.section.uid)"
                        />
                    </div>
                </div>

                <!-- Fields in this group -->
                <ul class="canvas__fields">
                    <li v-for="(field, fi) in group.fields" :key="field.uid">
                        <div class="canvas__field" :class="{ 'is-selected': isSelectedField(field.uid) }">
                            <button
                                type="button"
                                class="canvas__field-main"
                                :aria-pressed="isSelectedField(field.uid)"
                                @click="emit('selectField', field.uid)"
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
                                    icon="chevron-up"
                                    label="Move field up"
                                    size="sm"
                                    :disabled="disabled || fi === 0"
                                    @click="emit('moveField', field.uid, -1)"
                                />
                                <MdsIconButton
                                    icon="chevron-down"
                                    label="Move field down"
                                    size="sm"
                                    :disabled="disabled || fi === group.fields.length - 1"
                                    @click="emit('moveField', field.uid, 1)"
                                />
                                <MdsIconButton
                                    icon="copy"
                                    label="Duplicate field"
                                    size="sm"
                                    :disabled="disabled"
                                    @click="emit('duplicateField', field.uid)"
                                />
                                <MdsIconButton
                                    icon="trash"
                                    label="Delete field"
                                    variant="danger"
                                    size="sm"
                                    :disabled="disabled"
                                    @click="emit('deleteField', field.uid)"
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
                <MdsButton variant="tertiary" icon-left="layout" :disabled="disabled" @click="emit('addSection')">
                    Add section
                </MdsButton>
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
    margin: 0;
    padding: 0 0 0 var(--mds-space-4);
    list-style: none;
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
    min-width: 96px;
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
