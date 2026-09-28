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
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import PreviewRuntime from './PreviewRuntime.vue';
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
            // `BuilderPresenter` emits no `single_page_mode` and `BuilderPageProps.form` does not declare it,
            // so the projection's own `?? false` decides and the preview is always stepped. That is
            // `R-f1332829`'s territory — the column has no write path anywhere outside the seeders — and is
            // named in `previewLimitations()` rather than papered over with a guess here.
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

watch(
    () => model.value.projection.shape,
    (shape) => {
        if (! props.active || engine.value?.shape === shape) {
            return;
        }

        clearPending();
        timer = setTimeout(rebuild, PREVIEW_REBUILD_DEBOUNCE_MS);
    },
);

// First activation builds immediately — an author who has just switched to Preview should not watch an empty
// pane for 300ms. A later re-entry catches up in one rebuild if the shape moved while they were away.
watch(
    () => props.active,
    (active) => {
        if (! active) {
            clearPending();

            return;
        }

        if (engine.value?.shape !== model.value.projection.shape) {
            rebuild();
        }
    },
    { immediate: true },
);

onBeforeUnmount(clearPending);

const selectedKey = computed(() => props.store.selectedField.value?.key ?? null);

function onSelect(key: string): void {
    const uid = model.value.projection.uidByKey[key];

    if (uid !== undefined) {
        props.store.select({ kind: 'field', uid });
    }
}
</script>

<template>
    <div class="builder-preview">
        <PreviewRuntime
            v-if="engine"
            :key="engine.shape"
            :snapshot="engine.schema"
            :model="model.renderModel"
            :issues-by-key="model.issuesByKey"
            :selected-key="selectedKey"
            @select="onSelect"
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
