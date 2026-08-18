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
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    MdsAlert,
    MdsBreadcrumb,
    MdsButton,
    MdsCheckbox,
    MdsEmptyState,
    MdsModal,
    MdsSegmentedControl,
} from '@meridian/design-system';
import FieldPalette from '@/components/builder/FieldPalette.vue';
import LibraryPicker from '@/components/builder/LibraryPicker.vue';
import BuilderCanvas from '@/components/builder/BuilderCanvas.vue';
import LogicRail from '@/components/builder/LogicRail.vue';
import ConfigPanel from '@/components/builder/ConfigPanel.vue';
import ConflictDialog from '@/components/builder/ConflictDialog.vue';
import ConfirmationModal from '@/components/builder/ConfirmationModal.vue';
import ScheduleModal from '@/components/builder/ScheduleModal.vue';
import SaveAsTemplateModal from '@/components/forms/SaveAsTemplateModal.vue';
import ShareModal from '@/components/forms/ShareModal.vue';
import { useBuilderStore } from '@/components/builder/useBuilderStore';
import { saveLabel } from '@/components/builder/save-label';
import type { BuilderPageProps } from '@/components/builder/types';
import { useEntitlements } from '@/composables/useEntitlements';

const props = defineProps<BuilderPageProps>();
const store = useBuilderStore(props);
const { selection, saving, saveState, canUndo, canRedo, conflict, library, saveError } = store;

// The toolbar's polite live region. Reads the store's EXPLICIT verdict rather than inferring success
// from an idle queue -- see `save-label.ts` for why the failed state renders a string at all.
const saveStatusLabel = computed(() => saveLabel(saveState.value));


// Hide the plan-gated builder affordances (H5c) — XLSForm import/export, the field library, save-as-template.
// Each is server-gated on its route; this only spares a 402 click.
const { feature } = useEntitlements();

// Left-pane view: add a fresh field type (palette) or insert a reusable question (library, Increment G9b).
const leftTab = ref<'fields' | 'library'>('fields');

// Centre-pane view (Increment H21d1): the structure the author edits, or the branching it produces. The
// Logic view is READ-DERIVED — it writes nothing, and selecting a node there drives the same config panel,
// so an author reads the rail and edits in place rather than moving between two editors. Both views share
// one store, which is why the rail updates as conditions are typed.
const centreView = ref<'structure' | 'logic'>('structure');
const centreViews = [
    { value: 'structure', label: 'Structure', icon: 'layout' as const },
    { value: 'logic', label: 'Logic', icon: 'filter' as const },
];

// JR5 — which pane is on screen when the builder is COMPACT (`@container (max-width: 60em)` of the page's
// own box). The state exists at EVERY width and NOTHING IN JS EVER READS A WIDTH: above the threshold the
// three `--show-*` rules do not exist, all three panes keep their unconditional display, and this ref is
// inert. That is what lets `personalization-axe` pick a pane at 1440px and still have it selected after
// `extra_large` pushes the same viewport below the threshold — with no watcher, no matchMedia and no
// ResizeObserver.
//
// ⚠️ NEVER GATE A PANE'S RENDERING ON THIS. The panes are hidden with `display: none`, never `v-if` —
// see the @container block's own warning for the three gates that depend on it.
//
// Labels are `Add` · `Form` · `Settings` rather than the panes' own names: "Fields" already labels the
// LEFT PANE'S INNER toggle below, so at 375px the word would appear twice, six pixels apart, meaning two
// different things. None of these three collides with that toggle (Fields/Library), the centre control
// (Structure/Logic) or the config tablist. `layout` and `settings` are avoided as icons for the same
// reason — the centre control's Structure option and the sidebar's nav item already use them.
const pane = ref<'fields' | 'canvas' | 'settings'>('canvas');
const paneOptions = [
    { value: 'fields', label: 'Add', icon: 'plus' as const },
    { value: 'canvas', label: 'Form', icon: 'forms' as const },
    { value: 'settings', label: 'Settings', icon: 'sliders' as const },
];
function onPaneChange(value: string): void {
    if (value === 'fields' || value === 'canvas' || value === 'settings') pane.value = value;
}

// ⚠️ THE ONE THING ALLOWED TO OVERRIDE THE AUTHOR'S PANE CHOICE, AND IT IS A CORRECTNESS FIX RATHER THAN
// A CONVENIENCE. `saveError` is rendered in exactly ONE place in the entire client — ConfigPanel's
// `<p v-if="saveError" role="alert">` — which lives inside the CONFIG pane. Without this watcher, a write
// that fails while the author is on Add or Form mounts that alert inside a `display: none` subtree, where
// it is neither painted nor placed in the accessibility tree, so it is never announced and never seen. The
// rule this increment replaced only linearized the panes, so the alert was reachable at every width before
// it: the silence would be new, and it would be new on exactly the paths most likely to fail — deleting or
// duplicating a field, adding a section, inserting from the library, and undo/redo from the toolbar.
// (WCAG 3.3.1 Error Identification, 4.1.3 Status Messages.)
//
// ⚠️ WHY THIS WATCHER IS ACCEPTABLE WHERE A `watch(selection, …)` WAS NOT. That one was rejected because
// `onMounted` below auto-selects the first field, so it would fire on load and override the default pane.
// `saveError` starts null and only becomes truthy on a real failure, so nothing fires on mount. And above
// the threshold all three panes are on screen and `pane` is inert, so this is a no-op on desktop rather
// than a layout surprise.
//
// ✅ FIXED IN J7, and this watcher is why the fix had to reach the store rather than the template. The
// toolbar's status line now reads `saveState`, and `saveError` is a COMPUTED mirror of the same field the
// verdict tests — so the polite region cannot say "All changes saved" while this watcher is dragging the
// assertive alert on screen. The two channels are one source of truth by construction. (WCAG 4.1.3.)
watch(saveError, (message) => {
    if (message) pane.value = 'settings';
});

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

// Per-form "Save and finish later" opt-in (Increment H10). A form-level setting (not a version edit), so it
// PATCHes its own guarded route directly; gated `feature('save_and_resume')` client-side + at the route.
const saveResume = ref(props.form.save_and_resume);
function onToggleSaveResume(value: boolean): void {
    saveResume.value = value;
    router.patch(
        `/forms/${props.form.id}/save-resume`,
        { save_and_resume: value },
        {
            preserveScroll: true,
            preserveState: true,
            // Revert the optimistic toggle if the write is rejected (e.g. the plan no longer includes it).
            onError: () => {
                saveResume.value = !value;
            },
        },
    );
}

// ── Schedule (Increment H12b) — a form-level open/close window + response cap. A modal over its own guarded
// PATCH route (ungated, `can:update,form`), independent of the draft/version edits. ──
const scheduleOpen = ref(false);

// ── Confirmation message (Increment H6a) — the thank-you copy shown after a submit, and the first
// author-editable text that may carry `${key}` piping holes. Same shape as Schedule: a modal over its own
// guarded PATCH route, ungated by plan. ──
const confirmationOpen = ref(false);

// ── Share (Increment I1, PRD Feature #3) — the public link, its QR and its embed snippet. Same shape again:
// a modal over its own guarded PATCH route, ungated by plan. It lives on the toolbar rather than beside
// Publish because sharing is not a step in publishing — an author revisits the link long after. ──
const shareOpen = ref(false);

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

// Increment H21a (Doc #27 §6) — branching notices flashed after a publish that SUCCEEDED: a forward
// reference, a circular condition, or a form that shows nothing until something is answered. Deliberately
// its own banner rather than a reuse of the import one: the import copy says "Imported with N warnings",
// which would be a lie here, and the two can legitimately be on screen together.
const publishWarnings = computed<string[]>(() => page.props.flash?.publishWarnings ?? []);
const publishWarningsDismissed = ref(false);

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
            <!--
                J2b. The hand-rolled "← Forms" link became a real trail so the builder can reach the form's
                HUB and not only the list — one of the six pages that hand-rolled the same back link before
                `MdsBreadcrumb` existed.

                ⚠️ THREE CRUMBS, and the middle one is the load-bearing addition: `MdsBreadcrumb` renders the
                LAST item as text whatever it carries, so a two-crumb trail ending in the form's title would
                print the hub's name with no way to click it.

                The builder deliberately does NOT get the tab strip (user decision, J2b), unlike the hub and
                the analytics page. This is a three-pane workspace on a `height: 100%` grid; a second ~44px
                header row costs the one screen in the product that cannot spare the vertical space, and the
                trail already provides the way out. DSR §3.4 records it.
            -->
            <div class="builder__title-group">
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
                <div class="builder__title-row">
                    <h1 class="builder__title">{{ form.title }}</h1>
                    <span v-if="draft" class="builder__version">Draft v{{ draft.version_number }}</span>
                </div>
            </div>
            <!--
                JR5. The eight SECONDARY actions carry a permanent `aria-label` + `title` and wrap their
                word in `.builder__label`, which the @container block hides below 60em — nine buttons that
                wrapped to ~4 rows / ~250px at 375px become ~1 row of glyphs. Publish is deliberately NOT
                one of them: it keeps its word at every width, and `templates-axe.spec.ts` asserts its name
                comes from that slot text.

                ⚠️ ONE ELEMENT, NOT A TEXT/ICON PAIR TOGGLED BY `display: none`. A pair would duplicate
                sixteen `v-if="feature(…)"` / `:disabled` / `@click` declarations (the J1e "second caller
                lies" shape), let the two label strings drift invisibly, and drop five outlined buttons to
                MdsIconButton's tertiary-only 32×32 ghost. One element is exactly-one-in-the-a11y-tree BY
                CONSTRUCTION rather than by a CSS rule continuing to hold.

                ⚠️ THE `aria-label` AND THE SPAN TEXT MUST STAY IDENTICAL. Below the threshold the label is
                the ONLY name left, `aria-label` outranks `title` in name computation (so axe and
                `getByRole` see exactly "Share" — `builder-axe.spec.ts` depends on that), and WCAG 2.5.3
                Label-in-Name holds only while the two strings match. `builder-layout.test.ts` pins all
                three spellings equal — keep these one attribute per line or its regex stops matching.
            -->
            <div class="builder__actions">
                <!-- Always PRESENT and never empty: a live region inserted with its content already in it is
                     not reliably announced, and an empty span collapses a row that already wraps at 375px. -->
                <span class="builder__save" role="status" aria-live="polite">{{ saveStatusLabel }}</span>
                <MdsButton
                    variant="tertiary"
                    icon-left="undo"
                    aria-label="Undo"
                    title="Undo"
                    :disabled="!canUndo || readOnly"
                    @click="store.undo()"
                >
                    <span class="builder__label">Undo</span>
                </MdsButton>
                <MdsButton
                    variant="tertiary"
                    icon-left="redo"
                    aria-label="Redo"
                    title="Redo"
                    :disabled="!canRedo || readOnly"
                    @click="store.redo()"
                >
                    <span class="builder__label">Redo</span>
                </MdsButton>
                <MdsButton
                    v-if="feature('xlsform_export')"
                    variant="secondary"
                    icon-left="download"
                    aria-label="Export XLSForm"
                    title="Export XLSForm"
                    :disabled="readOnly"
                    @click="exportXlsform"
                >
                    <span class="builder__label">Export XLSForm</span>
                </MdsButton>
                <MdsButton
                    v-if="feature('xlsform_export')"
                    variant="secondary"
                    icon-left="upload"
                    aria-label="Import XLSForm"
                    title="Import XLSForm"
                    :disabled="readOnly"
                    @click="openImport"
                >
                    <span class="builder__label">Import XLSForm</span>
                </MdsButton>
                <MdsButton
                    v-if="feature('form_templates')"
                    variant="secondary"
                    icon-left="copy"
                    aria-label="Save as template"
                    title="Save as template"
                    :disabled="readOnly"
                    @click="openSaveAsTemplate"
                >
                    <span class="builder__label">Save as template</span>
                </MdsButton>
                <!-- Per-form save-and-resume opt-in (H10) — only when the tenant plan includes it (Starter+). -->
                <MdsCheckbox
                    v-if="feature('save_and_resume')"
                    :model-value="saveResume"
                    label="Save &amp; resume"
                    class="builder__toggle"
                    @update:model-value="onToggleSaveResume"
                />
                <!-- Scheduled-form config (H12b) — ungated, all tiers; enforcement is server-side. -->
                <MdsButton
                    variant="secondary"
                    icon-left="calendar"
                    aria-label="Schedule"
                    title="Schedule"
                    @click="scheduleOpen = true"
                >
                    <span class="builder__label">Schedule</span>
                </MdsButton>
                <!-- Confirmation message (H6a) — ungated, all tiers; references are checked at publish. -->
                <MdsButton
                    variant="secondary"
                    icon-left="message-check"
                    aria-label="Confirmation"
                    title="Confirmation"
                    @click="confirmationOpen = true"
                >
                    <span class="builder__label">Confirmation</span>
                </MdsButton>
                <!-- Share (I1) — ungated, all tiers. Not disabled on `readOnly`: a form with no editable
                     draft still has a live link worth copying, and the panel is where an author goes to
                     turn responses OFF, which matters most exactly when they cannot edit. -->
                <MdsButton
                    variant="secondary"
                    icon-left="share"
                    aria-label="Share"
                    title="Share"
                    @click="shareOpen = true"
                >
                    <span class="builder__label">Share</span>
                </MdsButton>
                <MdsButton variant="primary" icon-left="check" :disabled="readOnly" @click="publish">
                    Publish
                </MdsButton>
            </div>
        </header>

        <!--
            J4a — TWO NEAR-IDENTICAL BANNERS BECOME ONE COMPONENT USED TWICE. The markup below was
            duplicated verbatim in this file apart from its copy and its dismiss ref: the same wrapper, the
            same title/list body, the same hand-rolled Dismiss button. That is what MdsAlert is for.

            ⚠️ THE TONE CHANGES WHAT THE BUILDER LOOKS LIKE, DELIBERATELY. `.builder__warnings` painted
            `--mds-color-bg-sunken` with a bottom border — a NEUTRAL GREY strip announcing a warning, which
            is one of the thirty individually-safe choices the re-skin diagnosis names. It is amber now.

            ⚠️ `flex-shrink: 0` ON THE CALL-SITE CLASS IS LOAD-BEARING AND MUST SURVIVE. JR5 records this as
            the child that used to steal the panes' flexible row and fill the page while the panes collapsed
            into an implicit one. The class stays on the MdsAlert root, where Vue puts this component's
            scope-id, together with the full-bleed geometry the chrome needs (square corners, the wider
            horizontal padding, the bottom rule) — the component's own radius and padding are for an in-flow
            panel, and this is a strip spanning the workspace.

            Not `assertive`: an import warning is true from the moment the page renders. `role="status"` and
            `aria-live="polite"` came free — MdsAlert's default IS `role="status"`, which is what the
            hand-rolled version had spelled out.
        -->
        <MdsAlert
            v-if="importWarnings.length > 0 && !warningsDismissed"
            class="builder__warnings"
            tone="warning"
            :title="`Imported with ${importWarnings.length} ${importWarnings.length === 1 ? 'warning' : 'warnings'}`"
            dismissible
            dismiss-label="Dismiss import warnings"
            @dismiss="warningsDismissed = true"
        >
            <ul class="builder__warnings-list">
                <li v-for="(warning, i) in importWarnings" :key="i">{{ warning }}</li>
            </ul>
        </MdsAlert>

        <MdsAlert
            v-if="publishWarnings.length > 0 && !publishWarningsDismissed"
            class="builder__warnings"
            tone="warning"
            :title="`Published, with ${publishWarnings.length} ${publishWarnings.length === 1 ? 'note' : 'notes'} about your conditions`"
            dismissible
            dismiss-label="Dismiss publish notes"
            @dismiss="publishWarningsDismissed = true"
        >
            <ul class="builder__warnings-list">
                <li v-for="(warning, i) in publishWarnings" :key="i">{{ warning }}</li>
            </ul>
        </MdsAlert>

        <!-- J4a: this replaces all three panes rather than sitting beside them, so it is a page STATE and
             `MdsEmptyState` is the shared pattern for one — not an alert. It also had no `role` and no
             illustration: a centred grey paragraph was the entire read-only experience of this page. -->
        <MdsEmptyState
            v-if="readOnly"
            class="builder__blocked"
            illustration="default"
            headline="This form has no editable draft"
            description="Restore or publish a version from the forms list to start editing again."
        >
            <template #action>
                <MdsButton as="a" href="/forms" variant="primary" icon-left="forms">Go to forms</MdsButton>
            </template>
        </MdsEmptyState>

        <div v-else class="builder__panes" :class="`builder__panes--show-${pane}`">
            <!--
                JR5 — THE COMPACT PANE SWITCHER. In the DOM at every width; DISPLAYED only below 60em of
                the builder's own container. Above that, all three panes are on screen and this control is
                `display: none`, so it costs exactly zero vertical pixels on the desktop workspace the J2b
                decision refused a header row from (DSR §3.4's as-built note, which JR5 explains rather
                than contradicts: that was a NAVIGATION strip of links with routes and gates; this is a
                radiogroup switching already-mounted panes of the same page).

                ⚠️ `MdsSegmentedControl`, i.e. a RADIOGROUP — DELIBERATELY NOT A TABLIST. **`MdsTabs` NOW
                EXISTS (J4c) AND THIS IS STILL NOT A CANDIDATE FOR IT — the prohibition survived the
                component's arrival rather than expiring with it**, and J4c adopted the primitive into
                `ConfigPanel` on this same page without touching this control. Thirteen locators across
                builder-axe,
                responsive-axe and personalization-axe walk `[role="tab"]` on this page — four of them
                loops that CLICK every match — and a second tablist would join ConfigPanel's in all of
                them, clicking pane tabs mid-scan. It is also the wrong ARIA: the panes are this control's
                SIBLINGS, not its `tabpanel` children, so there is no aria-controls relationship to state.

                ⚠️ THE `ariaLabel` MUST CONTAIN NO OPTION WORD. It renders as the fieldset's visually
                hidden <legend>, inside the element `showBuilderPane()` scopes its getByText to — a legend
                containing "Form" resolves two nodes and fails strict mode on every call at once.
            -->
            <div class="builder__pane-switch">
                <MdsSegmentedControl
                    :model-value="pane"
                    :options="paneOptions"
                    ariaLabel="Builder pane"
                    compact
                    @update:model-value="onPaneChange"
                />
            </div>
            <div class="builder__pane builder__pane--left">
                <!-- The Fields⇄Library toggle only appears when field_library is in the plan (H5c). Without it
                     the left pane is the field palette alone (leftTab stays 'fields', its default). -->
                <div
                    v-if="feature('field_library')"
                    class="builder__left-tabs"
                    role="group"
                    aria-label="Add fields or insert from the library"
                >
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
                <div class="builder__centre-tabs">
                    <MdsSegmentedControl
                        :model-value="centreView"
                        :options="centreViews"
                        ariaLabel="Centre pane view"
                        compact
                        @update:model-value="centreView = $event === 'logic' ? 'logic' : 'structure'"
                    />
                </div>
                <div class="builder__centre-body">
                    <!-- Both views stay MOUNTED: the Logic rail keeps its fetched notices across a toggle,
                         and the structure canvas keeps any in-progress keyboard reorder. -->
                    <BuilderCanvas
                        v-show="centreView === 'structure'"
                        :store="store"
                        :field-type-labels="fieldTypeLabels"
                    />
                    <LogicRail
                        v-show="centreView === 'logic'"
                        :store="store"
                        :form-id="form.id"
                        :active="centreView === 'logic'"
                    />
                </div>
            </div>
            <div class="builder__pane builder__pane--config">
                <ConfigPanel :store="store" />
            </div>
        </div>

        <ConflictDialog :conflict="conflict" @resolve="store.resolveConflict" />

        <!-- Save as template (G9a) — snapshots the current draft into a tenant-owned private template. -->
        <SaveAsTemplateModal v-model:open="templateOpen" :form-id="form.id" :default-name="form.title" />

        <!-- Scheduled-form config (H12b) — open/close window + response cap over PATCH /forms/{form}/schedule. -->
        <ScheduleModal v-model:open="scheduleOpen" :form-id="form.id" :form="form" :timezones="timezones" />

        <!-- Confirmation message (H6a) — the post-submit thank-you copy, over PATCH /forms/{form}/confirmation. -->
        <ConfirmationModal v-model:open="confirmationOpen" :form-id="form.id" :form="form" />

        <!-- Share (I1) — the public link, QR and embed snippet, over PATCH /forms/{form}/share. -->
        <ShareModal
            v-model:open="shareOpen"
            :form-id="form.id"
            :form-title="form.title"
            :share="share"
        />

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
/* JR5 — THE PAGE IS ITS OWN QUERY CONTAINER, and it is `.builder` rather than `.builder__panes` for the
   reason DataTable.vue:324-330 states: a container query never matches the container ITSELF, and the
   compact layout has to rewrite `.builder__panes`'s own `grid-template-columns`. Declare it there and that
   declaration is unreachable. The toolbar is keyed on the same threshold and is a SIBLING of the panes, so
   `.builder` is also the only element that contains both.

   ⚠️ THE `font-size` IS NOT DECORATION — IT PINS WHAT `em` MEANS BELOW. A container query's font-relative
   units resolve against the CONTAINER's own computed font size. `app.css` already puts this exact token on
   `body` and nothing between body and `.builder` sets one, so this is a no-op today and a guarantee
   afterwards: 60em resolves to 960 / 1080 / 1200px across §2.9's three type scales. It reaches no painted
   text — the toolbar, the panes and every child set their own size.

   ⚠️ `container-type: inline-size` COMPUTES TO `contain: layout style inline-size`, which makes this
   element a containing block for `position: fixed` AND `position: absolute` descendants and opens a new
   stacking context. Audited before it was declared: there is no `position: fixed` anywhere in this page or
   in components/builder/; the one `position: absolute` (BuilderCanvas.vue's `.canvas__sr`) is the sr-only
   clip pattern with every offset `auto` and cannot move; and all six dialogs below are MdsModal, which
   `<Teleport to="body">`s outside `.builder` entirely. Block size is NOT contained, so `height: 100%`
   still resolves.

   ⚠️ FLEX, NOT `grid-template-rows: auto 1fr` — THAT ASSUMED TWO CHILDREN AND THIS PAGE HAS THREE WHEN A
   WARNINGS BANNER IS UP. The banner then took the `1fr` row and, having a sunken background and a border,
   visibly filled the page while `.builder__panes` fell into an implicit `auto` row. Today's linearized
   panes are `height: auto` and survived it; JR5's single-pane mode needs that row to be the flexible one,
   so it is fixed here rather than filed. A column flex container is correct for any number of banners. */
.builder {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
    background-color: var(--mds-color-bg-canvas);
    container-type: inline-size;
    font-size: var(--mds-type-body-lg-font-size);
}

.builder__toolbar {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
    flex-wrap: wrap;
    padding: var(--mds-space-3) var(--mds-space-6);
    border-bottom: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-surface);
}

/* A column since J2b: the trail sits above the title rather than inline beside it, which is where a
   breadcrumb belongs and is also the only arrangement that does not push the title off a narrow toolbar. */
.builder__title-group {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--mds-space-1);
    min-width: 0;
}

.builder__title-row {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    min-width: 0;
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
    /* Wrap the action buttons instead of overflowing — the toolbar now carries enough controls (incl. the H12b
       Schedule button) to exceed a narrow viewport or a large personalization font size (responsive contract). */
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--mds-space-2);
}

.builder__save {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    white-space: nowrap;
}

.builder__toggle {
    white-space: nowrap;
}

/* MdsEmptyState brings its own centring, medallion and type; only the breathing room around it in the
   builder's flex column is this page's business. */
.builder__blocked {
    padding: var(--mds-space-8);
}

/* Post-import warnings banner (Increment G7b) — dismissible, above the panes. `flex-shrink: 0` since JR5:
   this is the child that used to steal the panes' flexible row. */
/* ⚠️ `flex-shrink: 0` IS LOAD-BEARING AND PREDATES THE COMPONENT — JR5 records this as the child that
   used to steal the panes' flexible row, filling the page while the panes collapsed into an implicit one.
   The rest is full-bleed CHROME geometry the shared component correctly does not provide: a strip spanning
   the workspace has square corners and a rule under it, where an in-flow panel has neither. Tint, type and
   the dismiss control now come from MdsAlert. */
/*
 * ⚠️ THE DOUBLED CLASS IS NOT A TYPO — IT IS THE POINT, AND THE ADVERSARIAL PASS IS WHAT FOUND IT.
 *
 * Two of these declarations CONTRADICT the component's own: `MdsAlert` sets `border-radius: md` and a
 * uniform `space-3` padding, which are right for an in-flow panel and wrong for a strip spanning the
 * workspace. Vue puts the CHILD's scope-id and the PARENT's scope-id on the same root element, so the two
 * rules compile to `.mds-alert[data-v-child]` and `.builder__warnings[data-v-parent]` — **identical
 * specificity, (0,2,0) each**. Which one wins is then decided by injection order in the bundle, i.e. by
 * whichever module Vite happened to evaluate last. That is a coin flip, and it is invisible to every gate
 * here: this banner only renders after an import produces warnings, so the visual sweep never drew it.
 *
 * Repeating the class takes this to (0,3,0) and settles it. Cheaper than the alternative — a `--full-bleed`
 * variant on the shared component — for a shape exactly one page needs.
 */
.builder__warnings.builder__warnings {
    flex-shrink: 0;
    padding: var(--mds-space-3) var(--mds-space-6);
    border-radius: 0;
    border-bottom: 1px solid var(--mds-color-border-default);
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
    color: var(--mds-color-danger-text);
    font-size: var(--mds-type-body-sm-font-size);
}

.builder__panes {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr) 340px;
    flex: 1;
    min-height: 0;
}

/* JR5 — the compact pane switcher's bar. Same shape as `.builder__centre-tabs` below (a fixed header over
   a scrolling body), and every token here is copied from that rule rather than recalled. */
.builder__pane-switch {
    display: none;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-surface);
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

/* Structure ⇄ Logic (Increment H21d1) — a fixed header over the scrolling centre body, the same shape as
   the left pane's Fields ⇄ Library toggle. */
.builder__pane--canvas {
    display: flex;
    flex-direction: column;
}

.builder__centre-tabs {
    flex-shrink: 0;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-surface);
}

.builder__centre-body {
    flex: 1;
    min-height: 0;
}

/* ── JR5 — COMPACT: ONE PANE AT A TIME ────────────────────────────────────────────────────────────────
   REPLACES the `@media (max-width: 1024px)` linearization this increment deletes. That rule was not
   broken — nothing was hidden, nothing was off-screen, nothing scrolled sideways — it was wrong twice
   over. ERGONOMICALLY: all three panes stacked into one scrolling column, so reaching the canvas at 375px
   meant scrolling past ~31 palette buttons and reaching the config panel meant scrolling past the whole
   canvas. ARITHMETICALLY: 1024 is not a width this page ever has. The builder is the app's only fluid
   page, so its box is `viewport − sidebar`, and the sidebar is 240px above 1024px and a 64px rail at or
   below it — so the box is 785px at a 1025px VIEWPORT and 960px at a 1024px one, NARROWER ON THE WIDER
   SCREEN. The three-column grid therefore stayed on through 1025–1200 with a canvas track of
   785 − 260 − 340 = 185px, and Playwright's projects are 375/834/1440, so no gate in this repo has
   ever rendered that state.

   60em = 960px, and it is the answer to two independent questions that agree: 260 (palette) + 340 (config)
   + 360 (the smallest canvas worth having); and 1024 − 64, the widest box that can
   exist while the sidebar is still a rail. The second is what makes the transition CONTINUOUS across the
   sidebar swap — compact at 1024, compact at 1025, three panes from 1201 up.

   ⚠️ THE INCLUSIVITY OF `max-width` IS LOAD-BEARING. At 59.9375em a 1024px viewport (box exactly 960)
   would flip to the WIDE layout while 1025px (box 785) stayed compact — the inversion, reintroduced from
   the other side. Do not "tidy" this to an exclusive bound.

   ⚠️ THIS BLOCK MUST STAY LAST IN THE STYLESHEET. `display: none` on `.builder__pane` and `display: flex`
   on `.builder__pane--left` above are both single-class specificity; only source order makes the
   containment rule win. (Vue's scoped-CSS rewriting adds one attribute selector to both, so the tie
   survives compilation — but not a reordering.)

   ⚠️ `display: none`, NEVER `v-if`. `field-library-axe.spec.ts` runs axe with
   `.include('.builder__pane--left')`, which THROWS when the selector matches nothing, and
   `builder-axe.spec.ts` calls `.evaluate()` on that same element at all three viewports; nine
   `[role="tab"]` locators need ConfigPanel mounted at every width. All of them need the pane in the DOM;
   none of them needs it painted. JR4's rule arriving on a page instead of a component.

   ⚠️ AND NOTE WHAT IS *NOT* HERE: `.builder__pane`'s `height: 100%; overflow: hidden` is left alone,
   unlike the old media query which set `height: auto; overflow: visible`. Keeping it is what makes this a
   single-pane WORKSPACE rather than a stack — the shown pane fills the row and its child scrolls inside
   itself, which `.palette`, `.library`, `.canvas`, `.rail` and `.config` are all already built for. */
@container (max-width: 60em) {
    .builder__panes {
        grid-template-columns: minmax(0, 1fr);
        grid-template-rows: auto minmax(0, 1fr);
    }

    .builder__pane-switch {
        display: block;
    }

    .builder__pane {
        display: none;
        border-right: 0;
        border-left: 0;
    }

    .builder__panes--show-fields .builder__pane--left {
        display: flex;
    }

    .builder__panes--show-canvas .builder__pane--canvas {
        display: flex;
    }

    .builder__panes--show-settings .builder__pane--config {
        display: block;
    }

    /* The toolbar's eight secondary actions go icon-only — see the template's block comment. The `gap`
       does not close up around a hidden label: a `display: none` child is not a flex item at all. */
    .builder__label {
        display: none;
    }

    /* Flush-left once the row is glyphs rather than words; ragged-right reads as a mistake at 375px. */
    .builder__actions {
        justify-content: flex-start;
    }
}
</style>
