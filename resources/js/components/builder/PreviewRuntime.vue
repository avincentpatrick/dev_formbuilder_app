<script setup lang="ts">
/**
 * The builder preview's engine host (Increment M118, `B7`). One instance == one `createFormRuntime()`.
 *
 * ⛔ THIS COMPONENT IS REMOUNTED, NEVER UPDATED, AND THAT IS A REQUIREMENT RATHER THAN A STYLE.
 * `createFormRuntime()` registers a `watch` on whatever effect scope is active when it is called and ships
 * no disposer, so reassigning a module-level runtime inside a watcher leaks one step watcher per rebuild.
 * `PreviewPane` therefore keys this component on the engine `shape`; Vue tears the old scope down and the
 * watcher goes with it. That is the same device the guest SPA uses on a version-drift reschema.
 *
 * ⛔ IT RENDERS FROM `model`, NOT FROM `runtime.renderModel`, WHICH IS THE POINT OF THE WHOLE DESIGN.
 * `SectionView` is deliberately NOT reused for flat steps: it resolves its fields out of
 * `runtime.renderModel`, the FROZEN snapshot, so an author's label edit would not appear until some
 * unrelated structural change rebuilt the engine. `FieldRow` and `FieldControl` are prop-driven and
 * `runtime.labelFor(field)` reads the field it is PASSED, so a live `RenderField` renders live text through
 * the frozen piping seam. The engine is asked only for relevance, the required marker and step membership.
 *
 * ⛔ A SILENT ANNOUNCER IS PROVIDED, AND OMITTING ONE IS NOT AN OPTION. `FieldRow` calls `useAnnouncer()`,
 * which THROWS when nothing provided it. But its announcements are respondent-session behaviour — "New
 * question: …" on every relevance change — and an author editing conditions would drive a screen-reader
 * firehose describing their own keystrokes. So the announcer is real in shape and writes nowhere, and the
 * reason lives here rather than in the template: a comment in a template is rendered into `wrapper.html()`
 * by Vue, which is how M117 made a test fail on the comment explaining it.
 *
 * ⚠️ NO LIVE REGION IS RENDERED EITHER, and that is load-bearing for a gate: an sr-only region would bring
 * the visually-hidden clip idiom into a new `.vue`, and `clipped-node-containment.test.ts` asserts its
 * unguarded list with `toEqual` and forbids adding to it.
 *
 * ⛔ AND THIS PARAGRAPH USED TO SPELL THAT IDIOM OUT, WHICH PUT THIS FILE ON THE LIST IT WAS DESCRIBING.
 * That gate matches its pattern against the whole source file, comments included, so the sentence promising
 * the component does not clip anything WAS the clip it was scanned for. Exactly M117's lesson on the other
 * side of the glass — there, Vue rendered a template comment into `wrapper.html()` and broke the substring
 * assertion the comment explained. A rule about a forbidden string cannot be documented by quoting it.
 */
import { computed, provide, ref, watch } from 'vue';
import { MdsButton, MdsIcon, MdsIconButton } from '@meridian/design-system';
import FieldRow from '../../../public-runtime/components/FieldRow.vue';
import RepeatGroup from '../../../public-runtime/components/RepeatGroup.vue';
import { AnnouncerKey, RuntimeKey } from '../../../public-runtime/composables/context';
import { createFormRuntime } from '../../../public-runtime/composables/useFormRuntime';
import type { RuntimeStep } from '../../../public-runtime/composables/useFormRuntime';
import type { AnswerMap, RenderField, RenderModel, RenderSection, SchemaResponse } from '../../../public-runtime/lib/types';
import { ContentHeadingBaseKey } from '../submissions/note-content';
import type { ProjectionIssue } from './draft-snapshot';
import InlineLabelEdit from './InlineLabelEdit.vue';
import {
    engineKnows,
    isCaptureField,
    previewFieldsFor,
    previewPendingFields,
    previewRenderedSteps,
    previewSectionFor,
    previewShowsLabel,
    previewStepEndTarget,
    previewStepLabels,
} from './preview-model';
import PreviewStepStrip from './PreviewStepStrip.vue';
import { dropIdOf } from './usePreviewReorder';

const props = defineProps<{
    /** The frozen snapshot this engine was built from. Never mutated; a new one arrives as a remount. */
    snapshot: SchemaResponse;
    /** The LIVE render model. Updates in place on every store change, with no remount. */
    model: RenderModel;
    issuesByKey: Record<string, ProjectionIssue[]>;
    /** The `uid` of the field currently selected in the config panel, so the preview can mark it. */
    selectedKey: string | null;
    /**
     * The step to open on, carried across remounts by the parent.
     *
     * This component is REMOUNTED on every engine rebuild, so anything it holds itself is lost each time
     * the author makes a structural edit. `PreviewPane` does not remount, so the selected step lives there
     * and arrives here as a prop.
     */
    initialStepKey: string | null;
    /**
     * M139 (`R-598b9100`): sections that hold no question yet, from the LIVE store. The engine drops them — a section
     * with nothing to answer is no step, for the respondent too — so the preview shows them to the author only.
     */
    emptySections?: Array<{ key: string; label: string }>;
    /**
     * M151 (`R-74c3cf35`): the question whose label is being edited, and the stored label it opens with. Both live in the
     * pane, which does not remount, so an edit outlives a rebuild of this component.
     */
    editingKey?: string | null;
    editingValue?: string;
    /**
     * M151 (`R-2baef8ea`): the answers the previous engine held, carried across the remount a move causes — the author
     * was trying their conditions out — and the move in progress, which `usePreviewReorder` in the pane owns.
     */
    initialAnswers?: AnswerMap;
    reordering?: boolean;
    draggingKey?: string | null;
    dropId?: string | null;
}>();

// M139: the author's own controls in the preview. The pane turns them into a selection and an opened palette.
// M151: and a label edited on its row, which the pane writes through the store.
const emit = defineEmits<{
    select: [key: string];
    step: [key: string];
    'add-question': [sectionKey: string | null];
    'add-section': [];
    edit: [key: string];
    rename: [key: string, value: string, via: 'key' | 'blur'];
    'edit-cancel': [key: string];
    'grip-pointerdown': [event: PointerEvent, key: string];
    'grip-keydown': [event: KeyboardEvent, key: string];
    'grip-blur': [key: string];
}>();

const runtime = createFormRuntime(props.snapshot, {
    initialLocale: props.snapshot.form.default_locale,
    // No query string and no frozen clock: a preview has no URL prefill to honour, and `now`/`today` staying
    // unavailable matches the pre-H21a behaviour rather than inventing an authoring clock.
    search: '',
    // Increment M124 — page breaks paginate a stepped form here exactly as for the respondent. A COMPUTED off
    // the live model, never a getter: `props.model` is a fresh object on every store change, so a getter would
    // re-run the step list on every keystroke, while this invalidates only when the mode itself flips — and it
    // follows that flip with no remount (the same live channel `singlePage` below reads, for the same reason).
    paginateAtPageBreaks: computed(() => !props.model.form.single_page_mode),
    initialAnswers: props.initialAnswers,
});

provide(RuntimeKey, runtime);
provide(AnnouncerKey, { message: computed(() => ''), announce: () => {} });
// M130 — this pane titles each section with an h3 (the guest page and the encode page use an h2), so a note's
// relative content headings start one level deeper here. Images read through the staff route, the default.
provide(ContentHeadingBaseKey, 3);

const steps = computed(() => runtime.visibleSteps.value);
const step = computed(() => runtime.currentStep.value);
const pending = computed(() => previewPendingFields(steps.value, props.model));

/**
 * ⛔ THE MODE COMES FROM THE LIVE RENDER MODEL AND NEVER FROM `runtime.singlePageMode`, AND THAT IS A
 * CORRECTNESS CLAIM RATHER THAN A STYLE ONE. `FormRuntime.singlePageMode` is a plain boolean captured once
 * inside `createFormRuntime`, and this component is REMOUNTED only when `shape` moves — but `shapeOf()`
 * reads `sections` and `fields` and never `form`. So an author toggling the setting changes the schema,
 * moves no shape, remounts nothing, and an engine-sourced read would sit on the old mode until some
 * unrelated structural edit happened. `buildRenderModel` passes `form` through verbatim, so the live model
 * has the answer with no rebuild at all — which is the same two-channel split the strip's own labels take,
 * arriving from a new direction. `preview-model.ts`'s `previewRenderedSteps` states it once more, because
 * it is the one mutation that separates a working setting from a decorative one.
 */
const singlePage = computed(() => props.model.form.single_page_mode);

/** The steps actually rendered below: every visible one on one page, or just the current one. */
const rendered = computed(() => previewRenderedSteps(steps.value, step.value, singlePage.value));

/**
 * One rendered section — exactly the per-step shape the four single-step computeds used to hold, resolved
 * in the LIVE model so wording stays current between rebuilds.
 */
interface PreviewBlock {
    step: RuntimeStep;
    section: RenderSection | null;
    title: string | null;
    description: string | null;
    fields: RenderField[];
}

const blocks = computed<PreviewBlock[]>(() =>
    rendered.value.map((s) => {
        const section = previewSectionFor(s, props.model);

        // Increment M124 — a later page of a paginated section keeps its heading, marked as continuing, and drops
        // the description, exactly as the respondent's `SectionView` does.
        const title = section === null ? null : runtime.sectionTitleFor(section);

        return {
            step: s,
            section,
            title: title !== null && s.continuation ? `${title} (continued)` : title,
            description: section === null || s.continuation ? null : runtime.sectionDescriptionFor(section),
            fields: previewFieldsFor(s, props.model),
        };
    }),
);

const position = computed(() => {
    const index = steps.value.findIndex((s) => s.key === step.value?.key);

    return index < 0 ? null : { index, total: steps.value.length };
});

const stripOptions = computed(() => previewStepLabels(steps.value, props.model, runtime.sectionTitleFor));

/**
 * Reopen the step the author was on before this rebuild.
 *
 * ⛔ WITHOUT THIS THE PREVIEW SNAPS BACK TO PAGE 1 ON EVERY STRUCTURAL EDIT, and it does so today.
 * `createFormRuntime` seeds `currentStepKey` to the first visible step, and this component is keyed on the
 * engine `shape` — so adding a question to section four returns the author to section one, 300ms later,
 * with no indication why. The strip is what makes that plainly broken rather than merely disorienting.
 *
 * `goToStep` is the right primitive and already exists: a key that no longer resolves degrades to the
 * nearest surviving predecessor, or to the first incomplete step, instead of throwing. So a section
 * deleted while it was on screen lands somewhere sensible rather than nowhere.
 */
if (props.initialStepKey !== null) {
    runtime.goToStep(props.initialStepKey);
}

// `immediate` so the parent learns the key the seed or the `goToStep` above actually RESOLVED to, which is
// not necessarily the one it asked for. Reporting the resolved key is what stops a stale request being
// replayed into every later rebuild.
watch(
    () => runtime.currentStepKey.value,
    (key) => {
        if (key !== '') {
            emit('step', key);
        }
    },
    { immediate: true },
);

function issuesFor(field: RenderField): ProjectionIssue[] {
    return props.issuesByKey[field.key] ?? [];
}

/**
 * Whether a row carries the author's tools (M151, `R-74c3cf35`). They sit ABOVE the respondent's control and never inside
 * its label, which the shared `FieldInput` draws (as a label, a legend or a note's paragraph) and this pane may not edit;
 * and the label editor exists only while it is open. A question hidden by its condition renders an empty row — `FieldRow`
 * keeps its wrapper and drops the control — so it gets none: there is nothing on screen to edit beside. A capture field
 * is drawn inert whatever its condition, and a question the engine has not met yet errs toward being shown.
 */
function showsTools(field: RenderField): boolean {
    return isCaptureField(field) || !engineKnows(runtime, field.key) || runtime.fieldRelevance.value[field.key] === true;
}

/**
 * A double-click on the question's own name opens its label editor, as on Structure's row. Only on the FIRST naming
 * element in the row — the single control's label or the group's legend, both drawn by `FieldInput` ahead of any choice,
 * or the inert row's label — so a double-click into an input, on a choice's label or on a cascade level's label keeps
 * doing what it does for a respondent. Being first is the whole test: narrowing it to `label[for]` was a mutant no case
 * could kill, because no layout draws a choice before the name. A note without blocks draws its label as a paragraph
 * and has no such element; its Edit button covers it.
 */
function onRowDblclick(event: MouseEvent, field: RenderField): void {
    if (props.editingKey === field.key || !showsTools(field) || !previewShowsLabel(field)) return;

    const name = (event.currentTarget as HTMLElement).querySelector('legend, label, .preview__inert-label');
    if (name !== null && event.target instanceof Node && name.contains(event.target)) {
        emit('edit', field.key);
    }
}

const editor = ref<InstanceType<typeof InlineLabelEdit> | null>(null);

function setEditor(instance: unknown): void {
    editor.value = (instance as InstanceType<typeof InlineLabelEdit> | null) ?? null;
}

// The pane ends an open edit itself when the view is left — a hidden input never blurs. And it reads the answers before a
// rebuild replaces this engine, to hand them to the next one (M151).
defineExpose({
    commitEdit: () => editor.value?.commit('blur'),
    answersSnapshot: (): AnswerMap => JSON.parse(JSON.stringify(runtime.answers)) as AnswerMap,
});

/** Where a drop on a page's own "Add a question" area — or on the page in the strip — puts a question: its end (D105). */
function pageEndId(s: RuntimeStep): string {
    return dropIdOf(previewStepEndTarget(s));
}

/** While a question is moving, the strip offers every page as a place to drop it, under the page's own label. */
const stripDropZones = computed(() =>
    props.reordering ? steps.value.map((s, i) => ({ id: pageEndId(s), label: stripOptions.value[i]?.label ?? '' })) : null,
);

/**
 * The required marker, or null when the engine has not caught up.
 *
 * A field in the current step is by construction known to the engine, so this matters for the pending block
 * only — but asking through {@link engineKnows} rather than trusting position keeps the two blocks honest if
 * the pending list ever feeds a step.
 */
function marker(field: RenderField): string | null {
    if (! engineKnows(runtime, field.key)) {
        return null;
    }

    return runtime.requiredMarkerFor(field) === 'required' ? 'required' : null;
}

/**
 * Step movement uses `goToStep`, NEVER `attemptNext`.
 *
 * `attemptNext()` refuses to advance past a step holding validation errors — right for a respondent
 * answering about themselves, and wrong here: it would trap an author inside their own half-built form with
 * no way to look at the rest of it.
 */
function go(delta: number): void {
    const at = position.value;
    const next = at === null ? null : steps.value[at.index + delta];

    if (next) {
        runtime.goToStep(next.key);
    }
}
</script>

<template>
    <div class="preview" data-builder-preview>
        <PreviewStepStrip
            v-if="!singlePage && stripOptions.length > 1"
            :options="stripOptions"
            :current-key="step?.key ?? null"
            :drop-zones="stripDropZones"
            :drop-id="dropId ?? null"
            @go="runtime.goToStep($event)"
        />

        <div v-if="!singlePage && position && position.total > 1" class="preview__nav">
            <MdsButton
                v-if="position.index > 0"
                type="button"
                variant="secondary"
                size="sm"
                @click="go(-1)"
            >
                Back
            </MdsButton>
            <span class="preview__position">Page {{ position.index + 1 }} of {{ position.total }}</span>
            <MdsButton
                v-if="position.index < position.total - 1"
                type="button"
                variant="secondary"
                size="sm"
                @click="go(1)"
            >
                Next
            </MdsButton>
        </div>

        <p v-if="steps.length === 0" class="preview__empty">
            Nothing to show yet. Add a question, and it appears here as a respondent will see it.
        </p>

        <!-- ⛔ `v-else` AND `v-for` MAY NOT SHARE AN ELEMENT — Vue 3 gives `v-if` priority over `v-for`, so
             the single-step `v-else` this replaced could not simply grow a `v-for`. The `<template>` adds no
             DOM node and keeps the empty/non-empty branch exactly as it was. -->
        <template v-else>
            <section
                v-for="block in blocks"
                :key="block.step.key"
                class="preview__step"
                data-section
                :data-section-key="block.step.key"
            >
                <header v-if="block.title" class="preview__head">
                    <h3 class="preview__title" tabindex="-1" data-section-heading>{{ block.title }}</h3>
                    <p v-if="block.description" class="preview__desc">{{ block.description }}</p>
                </header>

                <RepeatGroup v-if="block.step.isRepeat && block.section" :section="block.section" />

                <div v-else class="preview__fields">
                    <div
                        v-for="field in block.fields"
                        :key="field.key"
                        class="preview__row"
                        :class="{
                            'preview__row--selected': field.key === selectedKey,
                            'preview__row--moving': field.key === draggingKey,
                            'preview__row--drop-before': dropId === `before:${field.key}`,
                            'preview__row--drop-after': dropId === `after:${field.key}`,
                        }"
                        :data-preview-field="field.key"
                        v-bind="showsTools(field) ? { 'data-drop-row': '', 'data-drop-id': `before:${field.key}`, 'data-drop-label': `above ${field.label}` } : {}"
                        @click="emit('select', field.key)"
                        @focusin="emit('select', field.key)"
                        @dblclick="onRowDblclick($event, field)"
                    >
                        <div v-if="showsTools(field)" class="preview__tools" data-preview-tools>
                            <button
                                type="button"
                                class="preview__grip"
                                data-preview-grip
                                :aria-label="`Reorder ${field.label}. Press Enter or Space to grab, then arrow keys; or drag.`"
                                @pointerdown="emit('grip-pointerdown', $event, field.key)"
                                @keydown="emit('grip-keydown', $event, field.key)"
                                @blur="emit('grip-blur', field.key)"
                            >
                                <MdsIcon name="grip" size="md" />
                            </button>
                            <InlineLabelEdit
                                v-if="editingKey === field.key"
                                :ref="setEditor"
                                :value="editingValue ?? ''"
                                label="Question label"
                                @commit="(value, via) => emit('rename', field.key, value, via)"
                                @cancel="emit('edit-cancel', field.key)"
                            />
                            <MdsIconButton
                                v-else-if="previewShowsLabel(field)"
                                icon="edit"
                                :label="`Edit label of ${field.label}`"
                                size="sm"
                                :disabled="reordering"
                                data-preview-edit-label
                                @click="emit('edit', field.key)"
                            />
                        </div>
                        <div v-if="isCaptureField(field)" class="preview__inert" :data-preview-inert="field.key">
                            <p class="preview__inert-label">
                                {{ runtime.labelFor(field) }}
                                <span v-if="marker(field)" class="preview__req" aria-hidden="true">*</span>
                            </p>
                            <p v-if="runtime.hintFor(field)" class="preview__inert-hint">{{ runtime.hintFor(field) }}</p>
                            <p class="preview__inert-note">Shown to respondents, but not interactive while you edit.</p>
                        </div>
                        <FieldRow v-else :field="field" />

                        <ul v-if="issuesFor(field).length > 0" class="preview__issues">
                            <li v-for="issue in issuesFor(field)" :key="issue.code" class="preview__issue">
                                {{ issue.message }}
                            </li>
                        </ul>
                    </div>
                </div>

                <div
                    class="preview__author"
                    :class="{ 'preview__drop--active': dropId === pageEndId(block.step) }"
                    data-preview-author
                    data-drop-zone
                    :data-drop-id="pageEndId(block.step)"
                    :data-drop-label="`at the end of ${block.title ?? 'the first questions'}`"
                >
                    <MdsButton
                        type="button"
                        variant="tertiary"
                        size="sm"
                        icon-left="plus"
                        data-preview-add-question
                        @click="emit('add-question', block.step.sectionKey)"
                    >
                        {{ block.title ? `Add a question to ${block.title}` : 'Add a question here' }}
                    </MdsButton>
                </div>
            </section>
        </template>

        <section v-if="pending.length > 0" class="preview__pending" data-preview-pending>
            <h3 class="preview__pending-title">Just added</h3>
            <p class="preview__pending-note">
                These appear on a page as soon as the preview catches up with your edit.
            </p>
            <ul class="preview__pending-list">
                <li v-for="field in pending" :key="field.key" :data-preview-pending-field="field.key">
                    {{ runtime.labelFor(field) }}
                </li>
            </ul>
        </section>

        <section
            v-for="empty in emptySections ?? []"
            :key="`empty-${empty.key}`"
            class="preview__step preview__step--empty"
            :class="{ 'preview__drop--active': dropId === `end:${empty.key}` }"
            data-preview-empty-section
            :data-empty-section-key="empty.key"
            data-drop-zone
            :data-drop-id="`end:${empty.key}`"
            :data-drop-label="`into ${empty.label}`"
        >
            <header class="preview__head">
                <h3 class="preview__title">{{ empty.label }}</h3>
            </header>
            <p class="preview__empty">No questions yet. Respondents do not see this section until it has one.</p>
            <div class="preview__author">
                <MdsButton type="button" variant="tertiary" size="sm" icon-left="plus" @click="emit('add-question', empty.key)">
                    Add a question to {{ empty.label }}
                </MdsButton>
            </div>
        </section>

        <div class="preview__author preview__author--end" data-preview-author-end>
            <MdsButton v-if="steps.length === 0" type="button" variant="tertiary" size="sm" icon-left="plus" @click="emit('add-question', null)">
                Add a question
            </MdsButton>
            <MdsButton type="button" variant="secondary" size="sm" icon-left="layout" data-preview-add-section @click="emit('add-section')">
                Add section
            </MdsButton>
        </div>
    </div>
</template>

<style scoped>
.preview {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    padding: var(--mds-space-5);
}

.preview__nav {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.preview__position {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    color: var(--mds-color-text-secondary);
}

/* M139 (`R-598b9100`): the author's controls, set off from what a respondent sees by a dashed rule. */
.preview__author {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    padding-top: var(--mds-space-2);
    border-top: 1px dashed var(--mds-color-border-default);
}

.preview__author--end {
    border-top: none;
}

.preview__step--empty {
    padding: var(--mds-space-3);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

.preview__empty {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-secondary);
}

.preview__step,
.preview__fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.preview__head {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.preview__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.preview__title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 4px;
}

.preview__desc {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-secondary);
}

/* M151: the author's tools above a question, set off from the respondent's control by sitting outside it. */
.preview__grip {
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

/* Structure's grip, drawn the same heavier way (M150, `R-91c1792e`). */
.preview__grip :deep(svg) {
    stroke-width: 3;
}

.preview__grip:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}

.preview__grip:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

/* Where a moving question would land: a rule above or below a row — a shadow, so nothing shifts under the pointer — or
   an outlined zone. The question itself stays in place, dimmed, until the drop. */
.preview__row--moving {
    opacity: 0.5;
}

.preview__row--drop-before {
    box-shadow: 0 -3px 0 0 var(--mds-color-action-primary-bg);
}

.preview__row--drop-after {
    box-shadow: 0 3px 0 0 var(--mds-color-action-primary-bg);
}

.preview__drop--active {
    outline: 2px dashed var(--mds-color-action-primary-bg);
    outline-offset: 2px;
}

.preview__tools {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    margin-bottom: var(--mds-space-1);
}

/* The selected row echoes the config panel's subject. A left rule rather than a fill, so it never competes
   with a control's own focus ring or with an error state. */
.preview__row {
    border-left: 3px solid transparent;
    padding-left: var(--mds-space-3);
}

.preview__row--selected {
    border-left-color: var(--mds-color-action-primary-bg);
}

.preview__inert {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding: var(--mds-space-3);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.preview__inert-label {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.preview__req {
    color: var(--mds-color-danger-text);
}

.preview__inert-hint,
.preview__inert-note {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.preview__issues {
    margin: var(--mds-space-2) 0 0;
    padding-left: var(--mds-space-4);
}

.preview__issue {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-status-warning-fg);
}

.preview__pending {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

.preview__pending-title {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.preview__pending-note {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.preview__pending-list {
    margin: var(--mds-space-1) 0 0;
    padding-left: var(--mds-space-4);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}
</style>
