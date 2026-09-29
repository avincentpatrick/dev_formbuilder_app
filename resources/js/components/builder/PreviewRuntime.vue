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
import { computed, provide, watch } from 'vue';
import { MdsButton } from '@meridian/design-system';
import FieldRow from '../../../public-runtime/components/FieldRow.vue';
import RepeatGroup from '../../../public-runtime/components/RepeatGroup.vue';
import { AnnouncerKey, RuntimeKey } from '../../../public-runtime/composables/context';
import { createFormRuntime } from '../../../public-runtime/composables/useFormRuntime';
import type { RuntimeStep } from '../../../public-runtime/composables/useFormRuntime';
import type { RenderField, RenderModel, RenderSection, SchemaResponse } from '../../../public-runtime/lib/types';
import type { ProjectionIssue } from './draft-snapshot';
import { engineKnows, isCaptureField, previewFieldsFor, previewPendingFields, previewRenderedSteps, previewSectionFor, previewStepLabels } from './preview-model';
import PreviewStepStrip from './PreviewStepStrip.vue';

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
}>();

const emit = defineEmits<{ select: [key: string]; step: [key: string] }>();

const runtime = createFormRuntime(props.snapshot, {
    initialLocale: props.snapshot.form.default_locale,
    // No query string and no frozen clock: a preview has no URL prefill to honour, and `now`/`today` staying
    // unavailable matches the pre-H21a behaviour rather than inventing an authoring clock.
    search: '',
});

provide(RuntimeKey, runtime);
provide(AnnouncerKey, { message: computed(() => ''), announce: () => {} });

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

        return {
            step: s,
            section,
            title: section === null ? null : runtime.sectionTitleFor(section),
            description: section === null ? null : runtime.sectionDescriptionFor(section),
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
                        :class="{ 'preview__row--selected': field.key === selectedKey }"
                        :data-preview-field="field.key"
                        @click="emit('select', field.key)"
                        @focusin="emit('select', field.key)"
                    >
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
