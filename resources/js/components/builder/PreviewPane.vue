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
import { computed, h, onBeforeUnmount, ref, watch, type FunctionalComponent } from 'vue';
import PreviewRuntime from './PreviewRuntime.vue';
import { PRESET_PREVIEW_ATTR, presetScopeCss } from './preset-scope';
import { PREVIEW_REBUILD_DEBOUNCE_MS, buildPreviewModel, previewLimitations } from './preview-model';
import type { BuilderStore } from './useBuilderStore';
import type { BuilderPageProps } from './types';
import type { SchemaResponse } from '../../../public-runtime/lib/types';

const props = defineProps<{
    store: BuilderStore;
    form: BuilderPageProps['form'];
    draft: BuilderPageProps['draft'];
    /** True while Preview is the selected centre view. Gates the rebuild, never the mounting. */
    active: boolean;
}>();

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

let timer: ReturnType<typeof setTimeout> | null = null;

function clearPending(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

function rebuild(): void {
    clearPending();
    engine.value = { shape: model.value.projection.shape, schema: model.value.projection.schema };
}

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
        timer = setTimeout(rebuild, PREVIEW_REBUILD_DEBOUNCE_MS);
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

function onSelect(key: string): void {
    const uid = model.value.projection.uidByKey[key];

    if (uid !== undefined) {
        props.store.select({ kind: 'field', uid });
    }
}
</script>

<template>
    <div class="builder-preview" v-bind="presetCss === '' ? {} : { [PRESET_PREVIEW_ATTR]: '' }">
        <PresetStyle v-if="presetCss !== ''" :css="presetCss" />
        <PreviewRuntime
            v-if="engine"
            :key="engine.shape"
            :snapshot="engine.schema"
            :model="model.renderModel"
            :issues-by-key="model.issuesByKey"
            :selected-key="selectedKey"
            :initial-step-key="stepKey"
            @select="onSelect"
            @step="stepKey = $event"
        />

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
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 0;
    /* Each centre view owns its own scroll — `.builder__centre-body` sets none. */
    overflow-y: auto;
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
