<script setup lang="ts">
/**
 * The interactive form builder (Increment D4a) — a three-pane workspace (field-type palette · canvas ·
 * config panel) over the form's current DRAFT version. State lives in useBuilderStore: every edit is a
 * reversible command on a client-side history stack (undo/redo) and is optimistically persisted through
 * the CSRF fetch sidecar, with a 409 ConflictDialog that preserves the author's in-flight input. Pointer
 * drag-and-drop is Increment D4b; ordering here is via explicit Move-up/down + the per-field Section
 * selector (a fully keyboard-operable path). Rendered full-bleed by AppLayout (forms/Builder is a fluid
 * page). Publishing/versioning stays on the existing Inertia endpoints.
 */
import { computed, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { MdsButton } from '@meridian/design-system';
import FieldPalette from '@/components/builder/FieldPalette.vue';
import BuilderCanvas from '@/components/builder/BuilderCanvas.vue';
import ConfigPanel from '@/components/builder/ConfigPanel.vue';
import ConflictDialog from '@/components/builder/ConflictDialog.vue';
import { useBuilderStore } from '@/components/builder/useBuilderStore';
import type { BuilderPageProps } from '@/components/builder/types';

const props = defineProps<BuilderPageProps>();
const store = useBuilderStore(props);
const { selection, saving, canUndo, canRedo, conflict } = store;

const readOnly = computed(() => props.draft === null);

const fieldTypeLabels: Record<string, string> = {};
props.palette.forEach((group) => group.types.forEach((type) => (fieldTypeLabels[type.value] = type.label)));

onMounted(() => {
    // Auto-select the first field (else the first section) so the config panel actually renders on load —
    // the interaction-driven axe scan (D4b) needs the panel mounted, and it's the right default for authors.
    const firstField = props.fields.slice().sort((a, b) => a.sequence - b.sequence)[0];
    if (firstField) {
        const local = store.fields.value.find((f) => f.id === firstField.id);
        if (local) store.select({ kind: 'field', uid: local.uid });
    } else if (store.sections.value.length > 0) {
        store.select({ kind: 'section', uid: store.sections.value[0].uid });
    }
});

function targetSection(): string | null {
    const current = selection.value;
    if (current?.kind === 'section') return store.sections.value.find((s) => s.uid === current.uid)?.id ?? null;
    if (current?.kind === 'field') return store.selectedField.value?.form_section_id ?? null;
    return null;
}

function onAdd(typeValue: string): void {
    void store.addField(typeValue, targetSection());
}

function publish(): void {
    void store.whenIdle().then(() => {
        router.post(`/forms/${props.form.id}/publish`, {}, { preserveScroll: true });
    });
}
</script>

<template>
    <div class="builder">
        <Head :title="`Edit · ${form.title}`" />

        <header class="builder__toolbar">
            <div class="builder__title-group">
                <Link href="/forms" class="builder__back" aria-label="Back to forms">
                    <span aria-hidden="true">←</span> Forms
                </Link>
                <h1 class="builder__title">{{ form.title }}</h1>
                <span v-if="draft" class="builder__version">Draft v{{ draft.version_number }}</span>
            </div>
            <div class="builder__actions">
                <span class="builder__save" role="status" aria-live="polite">
                    {{ saving ? 'Saving…' : 'All changes saved' }}
                </span>
                <MdsButton
                    variant="tertiary"
                    icon-left="undo"
                    :disabled="!canUndo || readOnly"
                    @click="store.undo()"
                >
                    Undo
                </MdsButton>
                <MdsButton
                    variant="tertiary"
                    icon-left="redo"
                    :disabled="!canRedo || readOnly"
                    @click="store.redo()"
                >
                    Redo
                </MdsButton>
                <MdsButton variant="primary" icon-left="check" :disabled="readOnly" @click="publish">
                    Publish
                </MdsButton>
            </div>
        </header>

        <div v-if="readOnly" class="builder__blocked">
            This form has no editable draft. Restore or publish a version from the
            <Link href="/forms">forms list</Link> first.
        </div>

        <div v-else class="builder__panes">
            <div class="builder__pane builder__pane--left">
                <FieldPalette :palette="store.palette" :disabled="saving" @add="onAdd" />
            </div>
            <div class="builder__pane builder__pane--canvas">
                <BuilderCanvas :store="store" :field-type-labels="fieldTypeLabels" />
            </div>
            <div class="builder__pane builder__pane--config">
                <ConfigPanel :store="store" />
            </div>
        </div>

        <ConflictDialog :conflict="conflict" @resolve="store.resolveConflict" />
    </div>
</template>

<style scoped>
.builder {
    display: grid;
    grid-template-rows: auto 1fr;
    height: 100%;
    min-height: 0;
    background-color: var(--mds-color-bg-canvas);
}

.builder__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
    flex-wrap: wrap;
    padding: var(--mds-space-3) var(--mds-space-6);
    border-bottom: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-surface);
}

.builder__title-group {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    min-width: 0;
}

.builder__back {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    text-decoration: none;
    white-space: nowrap;
}
.builder__back:hover {
    color: var(--mds-color-text-body);
    text-decoration: underline;
}
.builder__back:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.builder__title {
    margin: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.builder__version {
    flex-shrink: 0;
    padding: 0 var(--mds-space-2);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
}

.builder__actions {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
}

.builder__save {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    white-space: nowrap;
}

.builder__blocked {
    padding: var(--mds-space-8);
    color: var(--mds-color-text-secondary);
    text-align: center;
}

.builder__panes {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr) 340px;
    min-height: 0;
}

.builder__pane {
    min-height: 0;
    height: 100%;
    overflow: hidden;
}

.builder__pane--left {
    border-right: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-surface);
}

.builder__pane--config {
    border-left: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-surface);
}

/* Below the tablet ceiling the three panes linearize into one scrolling column (no horizontal
   overflow anywhere in the 481–1024px range). Each pane grows to its natural height. */
@media (max-width: 1024px) {
    .builder__panes {
        grid-template-columns: 1fr;
        overflow-y: auto;
    }
    .builder__pane {
        height: auto;
        overflow: visible;
        border-right: 0;
        border-left: 0;
        border-bottom: 1px solid var(--mds-color-border-default);
    }
}
</style>
