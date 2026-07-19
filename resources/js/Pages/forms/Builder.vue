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
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { MdsButton, MdsModal } from '@meridian/design-system';
import FieldPalette from '@/components/builder/FieldPalette.vue';
import LibraryPicker from '@/components/builder/LibraryPicker.vue';
import BuilderCanvas from '@/components/builder/BuilderCanvas.vue';
import ConfigPanel from '@/components/builder/ConfigPanel.vue';
import ConflictDialog from '@/components/builder/ConflictDialog.vue';
import SaveAsTemplateModal from '@/components/forms/SaveAsTemplateModal.vue';
import { useBuilderStore } from '@/components/builder/useBuilderStore';
import type { BuilderPageProps } from '@/components/builder/types';

const props = defineProps<BuilderPageProps>();
const store = useBuilderStore(props);
const { selection, saving, canUndo, canRedo, conflict, library } = store;

// Left-pane view: add a fresh field type (palette) or insert a reusable question (library, Increment G9b).
const leftTab = ref<'fields' | 'library'>('fields');

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

function onInsertFromLibrary(itemId: string): void {
    void store.insertFromLibrary(itemId, targetSection());
}

function publish(): void {
    void store.whenIdle().then(() => {
        router.post(`/forms/${props.form.id}/publish`, {}, { preserveScroll: true });
    });
}

// ── Save as template (G9a) — flush queued builder writes first, so the server snapshots the draft the
// author sees (the modal traps focus, so no further canvas edits can race the POST while it is open). ──
const templateOpen = ref(false);

function openSaveAsTemplate(): void {
    void store.whenIdle().then(() => {
        templateOpen.value = true;
    });
}

function exportXlsform(): void {
    // Download the current draft version as an XLSForm .xlsx (Increment G7a). Plain browser navigation to
    // the streamed download, mirroring the submissions-export pattern.
    if (props.draft === null) return;
    window.location.href = `/forms/${props.form.id}/versions/${props.draft.id}/xlsform`;
}

// ── Import XLSForm (Increment G7b) — destructive draft-replace behind a confirm modal ─────────────
const page = usePage();
const importOpen = ref(false);
const importForm = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);

// Non-fatal warnings flashed by the import controller (lossy coercions the author reviews before publish).
const importWarnings = computed<string[]>(() => page.props.flash?.xlsformWarnings ?? []);
const warningsDismissed = ref(false);

function openImport(): void {
    importForm.reset();
    importForm.clearErrors();
    if (fileInput.value) fileInput.value.value = '';
    importOpen.value = true;
}

function onImportFile(event: Event): void {
    importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitImport(): void {
    // Replace the draft, then force a full remount (preserveState:false) so useBuilderStore re-hydrates
    // from the newly-imported draft — a plain reload would keep the stale store state.
    importForm.post(`/forms/${props.form.id}/draft/xlsform-import`, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            importOpen.value = false;
        },
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
                <MdsButton
                    variant="secondary"
                    icon-left="download"
                    :disabled="readOnly"
                    @click="exportXlsform"
                >
                    Export XLSForm
                </MdsButton>
                <MdsButton
                    variant="secondary"
                    icon-left="upload"
                    :disabled="readOnly"
                    @click="openImport"
                >
                    Import XLSForm
                </MdsButton>
                <MdsButton
                    variant="secondary"
                    icon-left="copy"
                    :disabled="readOnly"
                    @click="openSaveAsTemplate"
                >
                    Save as template
                </MdsButton>
                <MdsButton variant="primary" icon-left="check" :disabled="readOnly" @click="publish">
                    Publish
                </MdsButton>
            </div>
        </header>

        <div
            v-if="importWarnings.length > 0 && !warningsDismissed"
            class="builder__warnings"
            role="status"
            aria-live="polite"
        >
            <div class="builder__warnings-body">
                <strong class="builder__warnings-title">
                    Imported with {{ importWarnings.length }}
                    {{ importWarnings.length === 1 ? 'warning' : 'warnings' }}
                </strong>
                <ul class="builder__warnings-list">
                    <li v-for="(warning, i) in importWarnings" :key="i">{{ warning }}</li>
                </ul>
            </div>
            <MdsButton variant="tertiary" icon-left="close" @click="warningsDismissed = true">
                Dismiss
            </MdsButton>
        </div>

        <div v-if="readOnly" class="builder__blocked">
            This form has no editable draft. Restore or publish a version from the
            <Link href="/forms">forms list</Link> first.
        </div>

        <div v-else class="builder__panes">
            <div class="builder__pane builder__pane--left">
                <div class="builder__left-tabs" role="group" aria-label="Add fields or insert from the library">
                    <button
                        type="button"
                        :aria-pressed="leftTab === 'fields'"
                        class="builder__left-tab"
                        :class="{ 'builder__left-tab--active': leftTab === 'fields' }"
                        @click="leftTab = 'fields'"
                    >
                        Fields
                    </button>
                    <button
                        type="button"
                        :aria-pressed="leftTab === 'library'"
                        class="builder__left-tab"
                        :class="{ 'builder__left-tab--active': leftTab === 'library' }"
                        @click="leftTab = 'library'"
                    >
                        Library
                    </button>
                </div>
                <div class="builder__left-body">
                    <FieldPalette
                        v-if="leftTab === 'fields'"
                        :palette="store.palette"
                        :disabled="saving"
                        @add="onAdd"
                    />
                    <LibraryPicker
                        v-else
                        :items="library"
                        :field-type-labels="fieldTypeLabels"
                        :disabled="saving"
                        @insert="onInsertFromLibrary"
                    />
                </div>
            </div>
            <div class="builder__pane builder__pane--canvas">
                <BuilderCanvas :store="store" :field-type-labels="fieldTypeLabels" />
            </div>
            <div class="builder__pane builder__pane--config">
                <ConfigPanel :store="store" />
            </div>
        </div>

        <ConflictDialog :conflict="conflict" @resolve="store.resolveConflict" />

        <!-- Save as template (G9a) — snapshots the current draft into a tenant-owned private template. -->
        <SaveAsTemplateModal v-model:open="templateOpen" :form-id="form.id" :default-name="form.title" />


        <MdsModal :open="importOpen" title="Import XLSForm" @close="importOpen = false">
            <p class="builder__prose">
                Importing an XLSForm <strong>replaces this draft's current content</strong>. Published
                versions are untouched — review the imported draft, then publish it as a new version.
            </p>
            <div class="builder__upload">
                <input
                    ref="fileInput"
                    type="file"
                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    aria-label="XLSForm .xlsx file"
                    @change="onImportFile"
                />
                <p v-if="importForm.errors.file" class="builder__upload-error" role="alert">
                    {{ importForm.errors.file }}
                </p>
            </div>
            <template #actions>
                <MdsButton variant="tertiary" @click="importOpen = false">Cancel</MdsButton>
                <MdsButton
                    variant="primary"
                    icon-left="upload"
                    :loading="importForm.processing"
                    :disabled="importForm.file === null"
                    @click="submitImport"
                >
                    Replace draft
                </MdsButton>
            </template>
        </MdsModal>
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
    /* inline-flex + min-height keeps the link a ≥24px touch target (WCAG 2.2 AA 2.5.8) at every viewport. */
    display: inline-flex;
    align-items: center;
    min-height: 24px;
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

/* Post-import warnings banner (Increment G7b) — dismissible, above the panes. */
.builder__warnings {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-4);
    padding: var(--mds-space-3) var(--mds-space-6);
    border-bottom: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-sunken);
}

.builder__warnings-title {
    display: block;
    margin-bottom: var(--mds-space-1);
    color: var(--mds-color-text-heading);
    font-size: var(--mds-type-body-sm-font-size);
}

.builder__warnings-list {
    margin: 0;
    padding-left: var(--mds-space-5);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.builder__prose {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-body);
}

.builder__upload {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.builder__upload-error {
    margin: 0;
    color: var(--mds-color-text-danger, #b42318);
    font-size: var(--mds-type-body-sm-font-size);
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
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-surface);
}

/* Fields ⇄ Library segmented toggle (Increment G9b) — a fixed header over the scrolling picker body. */
.builder__left-tabs {
    display: flex;
    gap: var(--mds-space-1);
    flex-shrink: 0;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.builder__left-tab {
    flex: 1;
    min-height: 32px;
    padding: 0 var(--mds-space-2);
    border: 1px solid transparent;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    cursor: pointer;
    transition:
        background-color var(--mds-duration-fast) var(--mds-ease-standard),
        color var(--mds-duration-fast) var(--mds-ease-standard);
}

.builder__left-tab:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}

.builder__left-tab:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

.builder__left-tab--active {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-heading);
    border-color: var(--mds-color-border-default);
}

.builder__left-body {
    flex: 1;
    min-height: 0;
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
