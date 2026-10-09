<script setup lang="ts">
/**
 * The builder's Preview view (Increment M118, `B7` / `R-14ce6e05`) — the centre pane's third option.
 *
 * Until this shipped, `BuilderCanvas` rendered a structural outline with no `<input>`, no `<select>` and no
 * `FieldInput` anywhere in the file, so an author could not see their form until they published it. Both
 * fillable renderers already existed and already crossed the bundle boundary in production, so this is not a
 * third renderer: it mounts the SAME engine the guest SPA and the encode page mount, and the same control
 * set, and adds only the draft-to-snapshot projection between the builder's model and them.
 *
 * ── THE TWO CHANNELS, AND WHY THE SPLIT IS NOT AN OPTIMISATION ─────────────────────────────────────────
 * `preview-model.ts`'s docblock holds the full argument. In one line: the RENDER model is rebuilt on every
 * store change and drives every visible string, while the ENGINE is rebuilt only when `shape` moves — and
 * `shape` deliberately excludes labels, hints, option labels and all of `config`, so reading presentation
 * from the engine would leave the preview stale in exactly the places an author edits most.
 *
 * ⛔ THE ENGINE IS ONLY REBUILT WHILE THIS VIEW IS THE SELECTED ONE, on `LogicRail`'s `:active` precedent.
 * Both sibling centre views stay mounted so they keep their state across a toggle, which is right — but a
 * mounted preview that rebuilds on every structural edit makes the author pay for an engine nobody is
 * looking at. A shape change that lands while the author is in Structure is collapsed into ONE rebuild on
 * re-entry.
 *
 * ⚠️ THE ENGINE SNAPSHOT AND ITS `shape` ARE HELD TOGETHER IN ONE REF, deliberately. Keying the child on a
 * shape while handing it a separately-computed live projection would let the two disagree for one tick after
 * a label edit — the child would mount against a schema newer than the shape that keyed it, which is
 * precisely the class of drift this whole design exists to avoid.
 */
import { computed, h, nextTick, onBeforeUnmount, provide, ref, watch, type FunctionalComponent } from 'vue';
import { ContentImageRetryKey } from '../submissions/note-content';
import PreviewRuntime from './PreviewRuntime.vue';
import { PRESET_PREVIEW_ATTR, presetScopeCss } from './preset-scope';
import { PREVIEW_REBUILD_DEBOUNCE_MS, buildPreviewModel, carryAnswers, previewLimitations, previewPlacement } from './preview-model';
import type { BuilderStore } from './useBuilderStore';
import type { BuilderPageProps } from './types';
import { useFlipReorder } from './useFlipReorder';
import { usePreviewReorder } from './usePreviewReorder';
import type { AnswerMap, SchemaResponse } from '../../../public-runtime/lib/types';

const props = defineProps<{
    store: BuilderStore;
    form: BuilderPageProps['form'];
    draft: BuilderPageProps['draft'];
    /** True while Preview is the selected centre view. Gates the rebuild, never the mounting. */
    active: boolean;
}>();

// M137 (`R-ddb4fc26`): an image the author has just added answers 409 until its virus check passes, so the preview
// asks again on the content editor's own schedule (`ContentBlocksEditor.vue`, 3 s, ten tries) instead of keeping
// the first failure until a reload. Only this surface opts in; see `ContentImageRetry`.
provide(ContentImageRetryKey, { delayMs: 3000, maxAttempts: 10 });

const model = computed(() =>
    buildPreviewModel({
        form: {
            id: props.form.id,
            title: props.form.title,
            description: props.form.description,
            default_locale: props.form.default_locale,
            supported_locales: props.form.supported_locales,
            // `R-f1332829` — the flag reaches the projection HERE, which is what lets the preview render the
            // mode the author chose instead of always stepping. It travels on the LIVE channel deliberately:
            // `buildRenderModel` passes `form` through verbatim and `shapeOf()` never reads `form` at all, so
            // the engine cannot be the source of it. `PreviewRuntime` carries the full argument.
            single_page_mode: props.form.single_page_mode,
        },
        version: props.draft ?? { id: 'preview', version_number: 0 },
        sections: props.store.sections.value,
        fields: props.store.fields.value,
    }),
);

/** The snapshot the live engine was built from, paired with the shape that keyed it. */
const engine = ref<{ shape: string; schema: SchemaResponse } | null>(null);

const root = ref<HTMLElement | null>(null);
const runtimeHost = ref<InstanceType<typeof PreviewRuntime> | null>(null);

/** The answers handed to the next engine, so a rebuild does not wipe what the author typed to try the form out (M151). */
const carried = ref<AnswerMap>({});

let timer: ReturnType<typeof setTimeout> | null = null;

/**
 * M151 (`R-2baef8ea`): a question moved in the preview. Nothing is written while it moves; the drop writes it once — one
 * request, one undo entry, as Structure's drag does — and rebuilds AT ONCE rather than after the debounce, so the
 * question is where it was dropped on the next frame, gliding there (`useFlipReorder`, below).
 */
const reorder = usePreviewReorder({
    getRoot: () => root.value,
    labelOf: (key) => model.value.renderModel.fields.find((field) => field.key === key)?.label ?? 'question',
    place: (key, target) =>
        previewPlacement(target, key, {
            fields: props.store.fields.value,
            uidByKey: model.value.projection.uidByKey,
            sectionIdByKey: model.value.projection.sectionIdByKey,
        }),
    commit: (key, placement) => {
        const uid = model.value.projection.uidByKey[key];
        if (uid === undefined) return;
        props.store.beginReorder();
        props.store.placeField(uid, placement.group, placement.index);
        void props.store.commitReorder('Move field');
        rebuild();
    },
    onDragStart: () => runtimeHost.value?.commitEdit(),
});

/**
 * M151 (`R-74c3cf35`): the question whose label is being edited in place, held HERE because `PreviewRuntime` remounts.
 *
 * ⛔ NO REBUILD LANDS UNDER AN OPEN EDITOR. A remount would destroy the input mid-word and reopen it on the stored label,
 * discarding the draft; so a rebuild that falls due while an edit is open waits for it to end (`held`).
 */
const editingKey = ref<string | null>(null);
// And none lands under a moving question either: it would destroy the row under the pointer, or the grip with focus.
const held = computed(() => editingKey.value !== null || reorder.isReordering.value);
let heldRebuild = false;

function clearPending(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

function rebuild(): void {
    clearPending();
    heldRebuild = false;
    const next = { shape: model.value.projection.shape, schema: model.value.projection.schema };
    const previous = engine.value;
    carried.value = previous === null ? {} : carryAnswers(runtimeHost.value?.answersSnapshot() ?? {}, previous.schema, next.schema);
    engine.value = next;
}

// The glide: rows and sections measured before the rebuilt engine host replaces them, matched by key after.
useFlipReorder(() => root.value, () => engine.value?.shape ?? '', { rowAttr: 'data-preview-field', groupAttr: 'data-section-key' });

/** The debounced rebuild, unless an edit is open — then it waits for the edit to end. */
function settle(): void {
    if (held.value) {
        timer = null;
        heldRebuild = true;

        return;
    }

    rebuild();
}

watch(held, (isHeld) => {
    if (!isHeld && heldRebuild && props.active) {
        rebuild();
    }
});

// The watch SOURCE is the shape, so this fires only when the shape has actually moved. It carried an
// additional `engine.value?.shape === shape` guard until a mutation proved the two redundant: deleting
// either one left every case green, because the source already filters what the guard re-checked. The
// source is kept because it states the intent — rebuild when the STRUCTURE moves — and the guard is gone
// rather than left as a clause nothing can kill. Its counterpart in the activation watcher below is a
// different comparison and is live.
watch(
    () => model.value.projection.shape,
    () => {
        if (! props.active) {
            return;
        }

        clearPending();
        timer = setTimeout(settle, PREVIEW_REBUILD_DEBOUNCE_MS);
    },
);

// First activation builds immediately — an author who has just switched to Preview should not watch an empty
// pane for 300ms — and a re-entry catches up on whatever moved while they were away.
//
// ⛔ CALLING `rebuild()` UNCONDITIONALLY IS SAFE, AND THE REASON IS THE `:key` RATHER THAN A COMPARISON.
// This read `if (engine.value?.shape !== model.value.projection.shape)` until a mutation proved the branch
// unkillable: `PreviewRuntime` is keyed on `engine.shape`, so re-assigning an IDENTICAL shape changes no key,
// Vue reuses the instance, setup never re-runs and no second engine is built. The idempotence is therefore
// structural — a property of the key — instead of a condition that could drift out of step with it. The test
// asserting that toggling away and back builds nothing now pins the key, which is the thing actually doing
// the work.
watch(
    () => props.active,
    (active) => {
        if (! active) {
            // A hidden input never blurs, so leaving the view ends an open edit as a blur would.
            runtimeHost.value?.commitEdit();
            clearPending();

            return;
        }

        rebuild();
    },
    { immediate: true },
);

onBeforeUnmount(clearPending);

/**
 * The step the preview is showing, held HERE because this component does not remount.
 *
 * ⛔ `PreviewRuntime` IS KEYED ON THE ENGINE SHAPE, so it is torn down and rebuilt on every structural
 * edit and cannot remember anything itself. `createFormRuntime` then seeds the first visible step, which
 * is why the preview has snapped back to page 1 mid-edit ever since it shipped. Parking the key one level
 * up is the whole fix; the child reports back the key it RESOLVED to, never the one it was handed.
 */
const stepKey = ref<string | null>(null);

const selectedKey = computed(() => props.store.selectedField.value?.key ?? null);

/**
 * The form's preset theme on the preview (M131, `R-6017d6d8`), scoped to this pane — see `preset-scope.ts` for
 * why it is three selector blocks rather than inline properties, and why a member's dyslexia font still wins.
 * Read off the LIVE `form` prop, so choosing a theme in the settings repaints the preview on the next render.
 */
const presetCss = computed(() =>
    presetScopeCss(props.form.theme_presets.find((preset) => preset.value === props.form.theme_preset) ?? null),
);

/** A `<style>` element from a render function: a template may not contain one. */
const PresetStyle: FunctionalComponent<{ css: string }> = (p) => h('style', p.css);

// M139 (`R-598b9100`): the author adds a section or a question from here. A question goes to the section through the
// builder's own path — select the section, open the palette — so there is no second way to add one.
const emit = defineEmits<{ 'add-question': [sectionUid: string | null] }>();

/**
 * Sections with no question yet, in order — read off the LIVE store, because the engine never makes them a step. Keyed by
 * the PROJECTED key (M151): a section with no key yet has a temporary one there, which is what a drop into it names.
 */
const emptySections = computed(() =>
    props.store.sections.value
        .slice()
        .sort((a, b) => a.sequence - b.sequence)
        .filter((section) => !props.store.fields.value.some((field) => field.form_section_id === section.id))
        .map((section) => ({
            key: model.value.projection.sectionKeyById[section.id] ?? section.key,
            label: section.label || section.key,
        })),
);

function onAddQuestion(sectionKey: string | null): void {
    const id = sectionKey === null ? undefined : model.value.projection.sectionIdByKey[sectionKey];
    const section = id === undefined ? undefined : props.store.sections.value.find((s) => s.id === id);
    emit('add-question', section?.uid ?? null);
}

function onAddSection(): void {
    void props.store.addSection();
}

function onSelect(key: string): void {
    const uid = model.value.projection.uidByKey[key];

    if (uid !== undefined) {
        props.store.select({ kind: 'field', uid });
    }
}

/**
 * The label the editor opens with: the STORED one, never the rendered one. What the preview shows is piped (`${key}`
 * holes filled from the preview's answers), localised, and "Untitled question" when the label is empty — none of which
 * is the author's text.
 */
const editingValue = computed(() => {
    const uid = editingKey.value === null ? undefined : model.value.projection.uidByKey[editingKey.value];

    return props.store.fields.value.find((field) => field.uid === uid)?.label ?? '';
});

// No select here: the click that asks for the edit bubbles to the row, whose own click selects the question — a mutant
// that deleted a second select survived every case, because there was nothing left for it to do.
function onEdit(key: string): void {
    if (reorder.isReordering.value) return;
    editingKey.value = key;
}

function endEdit(key: string, refocus: boolean): void {
    editingKey.value = null;
    if (!refocus) return;
    // After the next render — which is also when a rebuild the edit held has remounted the rows.
    void nextTick(() => root.value?.querySelector<HTMLElement>(`[data-preview-field="${key}"] [data-preview-edit-label]`)?.focus());
}

function onRename(key: string, value: string, via: 'key' | 'blur'): void {
    const uid = model.value.projection.uidByKey[key];

    if (uid !== undefined) {
        // One PATCH and one undo entry, through the settings pane's own commit path (`renameField`).
        props.store.renameField(uid, value);
    }

    // Enter keeps the keyboard on the row; a blur went somewhere the author chose, and focus stays there.
    endEdit(key, via === 'key');
}
</script>

<template>
    <div ref="root" class="builder-preview" v-bind="presetCss === '' ? {} : { [PRESET_PREVIEW_ATTR]: '' }">
        <PresetStyle v-if="presetCss !== ''" :css="presetCss" />
        <PreviewRuntime
            v-if="engine"
            ref="runtimeHost"
            :key="engine.shape"
            :snapshot="engine.schema"
            :model="model.renderModel"
            :issues-by-key="model.issuesByKey"
            :selected-key="selectedKey"
            :initial-step-key="stepKey"
            :empty-sections="emptySections"
            :editing-key="editingKey"
            :editing-value="editingValue"
            :initial-answers="carried"
            :reordering="reorder.isReordering.value"
            :dragging-key="reorder.draggingKey.value ?? reorder.grabbedKey.value"
            :drop-id="reorder.dropId.value"
            @select="onSelect"
            @step="stepKey = $event"
            @add-question="onAddQuestion"
            @add-section="onAddSection"
            @edit="onEdit"
            @rename="onRename"
            @edit-cancel="endEdit($event, true)"
            @grip-pointerdown="reorder.onGripPointerDown"
            @grip-keydown="reorder.onGripKeydown"
            @grip-blur="reorder.onGripBlur"
        />
        <div class="builder-preview__sr" role="status" aria-live="assertive">{{ reorder.announcement.value }}</div>

        <footer class="builder-preview__limits">
            <h3 class="builder-preview__limits-title">What this preview does not do</h3>
            <ul class="builder-preview__limits-list">
                <li v-for="limit in previewLimitations()" :key="limit">{{ limit }}</li>
            </ul>
        </footer>
    </div>
</template>

<style scoped>
.builder-preview {
    /* M151: the containing block of the announcement region below, so it is clipped inside this pane rather than adding
       to the page's scroll (`clipped-node-containment.test.ts`). */
    position: relative;
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 0;
    /* M137 (`R-ef4334b1`): `flex: 1` alone sized nothing — `.builder__centre-body` is not a flex container — so the
       pane grew to its content and `.builder__pane` clipped the rest with no way down. The height is what makes this
       the scroll container, as `.canvas` and `.rail` beside it already are. */
    height: 100%;
    /* Each centre view owns its own scroll — `.builder__centre-body` sets none. */
    overflow-y: auto;
}

.builder-preview__sr {
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

.builder-preview__limits {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin-top: auto;
    padding: var(--mds-space-4) var(--mds-space-5);
    border-top: 1px solid var(--mds-color-border-default);
    background-color: var(--mds-color-bg-sunken);
}

.builder-preview__limits-title {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.builder-preview__limits-list {
    margin: 0;
    padding-left: var(--mds-space-4);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
