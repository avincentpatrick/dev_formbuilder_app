<script setup lang="ts">
/**
 * The builder's right pane: the config editor for the selected field or section. Content edits update the
 * local model immediately (so the canvas reflects them live) and the store debounces one persist + one
 * undo/redo entry per editing burst; structural changes (move field to another section) go through their
 * own reorder command.
 *
 * ── THE SWITCHER IS `MdsTabs` AS OF J4c, AND IT USED TO BE HAND-ROLLED HERE ─────────────────────────────
 * Real tab semantics — deliberately NOT MdsSegmentedControl, which is a radiogroup — but the markup now
 * lives in the package. It was the product's ONLY in-page tablist, so it was both the component's sole
 * possible consumer and the reason the primitive was owed.
 *
 * ⚠️ THE MOVE BOUGHT SCRUTINY RATHER THAN REUSE, WHICH IS THE SAME JUSTIFICATION J4b RECORDED FOR MdsMenu,
 * AND IT FOUND THREE DEFECTS THE SAME WAY. An application-tree component gets no Storybook story and
 * therefore no accessibility scan; the builder's end-to-end specs click every tab on this page and have
 * never once asked what any of them points at. What that hid: the selected tab's underline drawn with a
 * FILL token on a non-text indicator (the WCAG 1.4.11 substitution J2a measured on MdsTabNav and J4a on the
 * personalization accent bar); a panel carrying a tabindex of 0 while full of form controls, which the APG
 * reserves for panels holding nothing focusable; and that panel then suppressing its own focus ring, so the
 * redundant stop it minted was invisible to whoever landed on it. None of the three is visible in a diff,
 * a screenshot, or a passing spec.
 *
 * ⚠️ THIS PAGE MAY HOLD EXACTLY ONE TABLIST, NOW AND PERMANENTLY. Thirteen end-to-end locators walk the tab
 * role on the builder — four of them loops that CLICK every match — so a second one would have its tabs
 * clicked mid-scan and every settle locator would resolve to whichever strip came first in the DOM. The
 * compact pane switcher and the Structure-versus-Logic toggle stay radiogroups; DSR §3.4 carries the
 * prohibition and Builder.vue restates it at the call site.
 */
import { computed, ref, watch } from 'vue';
import {
    MdsButton,
    MdsCheckbox,
    MdsFormField,
    MdsNumberInput,
    MdsSegmentedControl,
    MdsSelect,
    MdsTabs,
    MdsTextInput,
    MdsTextarea,
} from '@meridian/design-system';
import CascadingEditor from './CascadingEditor.vue';
import ChoicesEditor from './ChoicesEditor.vue';
import ConditionEditor from './ConditionEditor.vue';
import GeoEditor from './GeoEditor.vue';
import LikertMatrixEditor from './LikertMatrixEditor.vue';
import MatrixEditor from './MatrixEditor.vue';
import MediaEditor from './MediaEditor.vue';
import PrefillEditor from './PrefillEditor.vue';
import ValidationEditor from './ValidationEditor.vue';
import type { BuilderStore } from './useBuilderStore';
import type { BuilderValidation, ConditionCatalogue, EnumOption, LocalField, LocalSection } from './types';

interface Choice {
    value: string;
    label: string;
}
interface CascadeLevel {
    key: string;
    label: string;
}
interface CascadeOption {
    value: string;
    label: string;
    level: string;
    parent: string | null;
}

const props = defineProps<{ store: BuilderStore }>();

const field = props.store.selectedField;
const section = props.store.selectedSection;
const saveError = props.store.saveError;
const enums = props.store.enums;
const saving = props.store.saving;
// Transient "Saved to library" confirmation (Increment G9b), cleared after a beat.
const librarySaved = props.store.librarySaved;

const optionTypes = new Set<string>();
const advancedTypes = new Set<string>();
const configEditorByType = new Map<string, string | null>();
props.store.palette.forEach((group) =>
    group.types.forEach((type) => {
        if (type.has_options) optionTypes.add(type.value);
        if (type.advanced) advancedTypes.add(type.value);
        configEditorByType.set(type.value, type.config_editor);
    }),
);

const requiredOptions = enums.required_modes;
const sectionOptions = computed<EnumOption[]>(() => [
    { value: '', label: 'No section (top level)' },
    ...props.store.sections.value.map((s) => ({ value: s.id, label: s.label })),
]);

const configEditor = computed<string | null>(() =>
    field.value ? configEditorByType.get(field.value.field_type) ?? null : null,
);

const tabs = computed<{ key: string; label: string }[]>(() => {
    if (field.value) {
        const list = [{ key: 'basics', label: 'Basics' }];
        if (optionTypes.has(field.value.field_type)) list.push({ key: 'options', label: 'Options' });
        if (configEditor.value === 'cascading') list.push({ key: 'cascading', label: 'Levels' });
        if (configEditor.value === 'matrix' || configEditor.value === 'likert_matrix') list.push({ key: 'grid', label: 'Grid' });
        if (configEditor.value === 'geo') list.push({ key: 'geo', label: 'Map' });
        if (configEditor.value === 'media') list.push({ key: 'media', label: 'Media' });
        if (configEditor.value === 'prefill') list.push({ key: 'prefill', label: 'Prefill' });
        list.push({ key: 'validation', label: 'Validation' }, { key: 'advanced', label: 'Advanced' });
        return list;
    }
    if (section.value) {
        return [
            { key: 'basics', label: 'Basics' },
            { key: 'advanced', label: 'Advanced' },
        ];
    }
    return [];
});

const activeTab = ref('basics');

watch(
    () => field.value?.uid ?? section.value?.uid ?? null,
    () => {
        activeTab.value = 'basics';
    },
);
watch(tabs, (list) => {
    if (!list.some((t) => t.key === activeTab.value)) activeTab.value = list[0]?.key ?? 'basics';
});

const advanced = computed(() => (field.value ? advancedTypes.has(field.value.field_type) : false));
const isCalculated = computed(() => field.value?.field_type === 'calculated');
const calculatedFormula = computed<string>(() => (field.value?.config.calculated_formula as string | undefined) ?? '');
const choices = computed<Choice[]>(() => (field.value?.config.options as Choice[] | undefined) ?? []);
// A cascading field keeps its LEVELS + parented OPTIONS under distinct config keys (Increment G4a).
const cascadeLevels = computed<CascadeLevel[]>(() => (field.value?.config.levels as CascadeLevel[] | undefined) ?? []);
const cascadeOptions = computed<CascadeOption[]>(() => (field.value?.config.options as CascadeOption[] | undefined) ?? []);
// A composite grid (Increment G4b) keeps its ROWS/COLUMNS (+ matrix CELLS) under distinct config keys.
const gridRows = computed<Choice[]>(() => (field.value?.config.rows as Choice[] | undefined) ?? []);
const gridColumns = computed<Choice[]>(() => (field.value?.config.columns as Choice[] | undefined) ?? []);
const gridCells = computed<Choice[]>(() => (field.value?.config.cells as Choice[] | undefined) ?? []);
// A geospatial field (Increment G5b2b) carries capture + default-map-view options (all optional).
const geoCaptureAltitude = computed<boolean>(() => (field.value?.config.capture_altitude as boolean | undefined) ?? false);
const geoAccuracyThreshold = computed<number | null>(() => (field.value?.config.accuracy_threshold as number | undefined) ?? null);
const geoDefaultCenter = computed<{ lat: number; lon: number } | null>(() => (field.value?.config.default_center as { lat: number; lon: number } | undefined) ?? null);
const geoDefaultZoom = computed<number | null>(() => (field.value?.config.default_zoom as number | undefined) ?? null);
// A media field (Increment G6) carries upload constraints + capture options (all optional).
const mediaAcceptedTypes = computed<string[]>(() => {
    const raw = field.value?.config.accepted_types;
    return Array.isArray(raw) ? raw.filter((t): t is string => typeof t === 'string') : [];
});
const mediaMaxFileSizeBytes = computed<number | null>(() => (field.value?.config.max_file_size_bytes as number | undefined) ?? null);
const mediaMaxCount = computed<number | null>(() => (field.value?.config.max_count as number | undefined) ?? null);
const mediaMinCount = computed<number | null>(() => (field.value?.config.min_count as number | undefined) ?? null);
const mediaCaptureSource = computed<string | null>(() => (field.value?.config.capture_source as string | undefined) ?? null);
// A hidden field (Increment H7) carries only where its value comes from; the fixed literal reuses the
// existing `default_value` column rather than a second config key.
const prefillSource = computed<string | null>(() => (field.value?.config.prefill_source as string | undefined) ?? null);
const prefillUrlParam = computed<string | null>(() => (field.value?.config.url_param as string | undefined) ?? null);

// What the structured condition editor may offer (Increment H21d2). Assembled here because the editor takes
// no store — the sub-editor house contract — and because only the panel knows which row is being edited.
//
// Every key is offered, including keys that come LATER in the form: a forward reference is legal at publish
// and warned rather than refused (Doc #27 §3.1), so hiding them would enforce a rule the engine does not
// have. What IS excluded is the row's own key, which can only ever be a self-cycle — and excluding it from
// the PICKER never rewrites an expression that already names it.
const NUMERIC_TYPES = new Set(['integer', 'decimal', 'calculated', 'likert_scale']);

function choicesOf(target: LocalField): EnumOption[] {
    const options = target.config.options;
    if (!Array.isArray(options)) return [];

    return options
        .filter((option): option is Choice => typeof option === 'object' && option !== null && 'value' in option)
        .map((option) => ({ value: String(option.value), label: String(option.label ?? option.value) }));
}

const catalogue = computed<ConditionCatalogue>(() => {
    const ownKey = field.value?.key ?? section.value?.key ?? null;

    return {
        fields: props.store.fields.value
            .filter((f) => f.key !== '' && f.key !== ownKey)
            .map((f) => ({
                key: f.key,
                label: f.label.trim() === '' ? f.key : f.label,
                numeric: NUMERIC_TYPES.has(f.field_type),
                options: choicesOf(f),
            })),
        // `count()` is only meaningful against a repeatable section — a non-repeating one has no instances
        // to count, and H21a seeds the reference at both scopes for repeatables only.
        repeatables: props.store.sections.value
            .filter((s) => s.is_repeatable && s.key !== '' && s.key !== ownKey)
            .map((s) => ({ key: s.key, label: s.label.trim() === '' ? s.key : s.label })),
    };
});

function setField<K extends keyof LocalField>(key: K, value: LocalField[K]): void {
    const target = field.value;
    if (!target) return;
    target[key] = value;
    props.store.touch(target.uid, 'field');
}
function setConfig(key: string, value: unknown): void {
    const target = field.value;
    if (!target) return;
    target.config = { ...target.config, [key]: value };
    props.store.touch(target.uid, 'field');
}
function setValidations(value: BuilderValidation[]): void {
    setField('validations', value);
}
function setSection<K extends keyof LocalSection>(key: K, value: LocalSection[K]): void {
    const target = section.value;
    if (!target) return;
    target[key] = value;
    props.store.touch(target.uid, 'section');
}
function reparent(sectionId: string): void {
    if (field.value) props.store.moveFieldToSection(field.value.uid, sectionId || null);
}

// One-click save to the question library (Increment G9b): the server names the item from the field label.
function saveToLibrary(): void {
    if (field.value) void props.store.saveFieldToLibrary(field.value.uid);
}

watch(librarySaved, (value) => {
    if (value !== null) {
        setTimeout(() => {
            librarySaved.value = null;
        }, 2500);
    }
});
</script>

<template>
    <div class="config">
        <!-- ⚠️ A SIBLING OF BOTH BRANCHES, NOT A CHILD OF THE EDITOR ONE (J7). This alert used to live inside
             the `v-else` below, so it rendered ONLY when a field or section was selected — and selection goes
             null on exactly the paths most likely to fail: a delete that succeeds clears it, and a form with
             no fields starts with nothing selected. A failed write in that state was reported NOWHERE in the
             client. Hoisted, it is the assertive half of the pair whose polite half is the toolbar's status
             line; both now read one source of truth in the store.

             Above the strip, not between the strip and its own panel. A save failure is about the pane
             rather than about the selected tab, and wedging it into the tab-to-panel gap was the one
             place it could not belong. -->
        <p v-if="saveError" class="config__error" role="alert">{{ saveError }}</p>

        <div v-if="!field && !section" class="config__empty">
            <p>Select a field or section to configure it.</p>
        </div>

        <template v-else>
            <MdsTabs
                :items="tabs"
                :model-value="activeTab"
                ariaLabel="Configuration sections"
                @update:model-value="activeTab = $event"
            >
                <!-- ── Field editor ─────────────────────────────────────────── -->
                <template v-if="field">
                    <p v-if="advanced && configEditor === null" class="config__note">
                        Baseline settings for this advanced field type. Its full editor and runtime arrive with the
                        form engine.
                    </p>

                    <template v-if="activeTab === 'basics'">
                        <MdsFormField label="Label" v-slot="{ id }">
                            <MdsTextInput :id="id" :model-value="field.label" @update:model-value="setField('label', $event)" />
                        </MdsFormField>
                        <MdsFormField
                            v-if="isCalculated"
                            label="Calculation formula"
                            help="Evaluated on the server. Use ${field} references, arithmetic (+ - * /), comparisons, and if()/count()/int()/today()/now()."
                            v-slot="{ id, describedby }"
                        >
                            <MdsTextarea
                                :id="id"
                                :describedby="describedby"
                                :model-value="calculatedFormula"
                                :rows="2"
                                placeholder="e.g. ${quantity} * ${unit_price}"
                                @update:model-value="setConfig('calculated_formula', $event || null)"
                            />
                        </MdsFormField>
                        <MdsFormField label="Help text" v-slot="{ id }">
                            <MdsTextarea
                                :id="id"
                                :model-value="field.hint ?? ''"
                                :rows="2"
                                @update:model-value="setField('hint', $event || null)"
                            />
                        </MdsFormField>
                        <MdsFormField v-if="!isCalculated" label="Placeholder" v-slot="{ id }">
                            <MdsTextInput
                                :id="id"
                                :model-value="field.placeholder ?? ''"
                                @update:model-value="setField('placeholder', $event || null)"
                            />
                        </MdsFormField>
                        <div v-if="!isCalculated" class="config__group">
                            <span class="config__group-label">Requiredness</span>
                            <MdsSegmentedControl
                                :model-value="field.is_required"
                                :options="requiredOptions"
                                ariaLabel="Requiredness"
                                @update:model-value="setField('is_required', $event)"
                            />
                        </div>
                    </template>

                    <template v-else-if="activeTab === 'options'">
                        <ChoicesEditor :options="choices" @update:options="setConfig('options', $event)" />
                    </template>

                    <template v-else-if="activeTab === 'cascading'">
                        <CascadingEditor
                            :levels="cascadeLevels"
                            :options="cascadeOptions"
                            @update:levels="setConfig('levels', $event)"
                            @update:options="setConfig('options', $event)"
                        />
                    </template>

                    <template v-else-if="activeTab === 'grid' && configEditor === 'matrix'">
                        <MatrixEditor
                            :rows="gridRows"
                            :columns="gridColumns"
                            :cells="gridCells"
                            @update:rows="setConfig('rows', $event)"
                            @update:columns="setConfig('columns', $event)"
                            @update:cells="setConfig('cells', $event)"
                        />
                    </template>

                    <template v-else-if="activeTab === 'grid'">
                        <LikertMatrixEditor
                            :rows="gridRows"
                            :columns="gridColumns"
                            @update:rows="setConfig('rows', $event)"
                            @update:columns="setConfig('columns', $event)"
                        />
                    </template>

                    <template v-else-if="activeTab === 'geo'">
                        <GeoEditor
                            :field-type="field.field_type"
                            :capture-altitude="geoCaptureAltitude"
                            :accuracy-threshold="geoAccuracyThreshold"
                            :default-center="geoDefaultCenter"
                            :default-zoom="geoDefaultZoom"
                            @update:captureAltitude="setConfig('capture_altitude', $event)"
                            @update:accuracyThreshold="setConfig('accuracy_threshold', $event)"
                            @update:defaultCenter="setConfig('default_center', $event)"
                            @update:defaultZoom="setConfig('default_zoom', $event)"
                        />
                    </template>

                    <template v-else-if="activeTab === 'media'">
                        <MediaEditor
                            :field-type="field.field_type"
                            :accepted-types="mediaAcceptedTypes"
                            :max-file-size-bytes="mediaMaxFileSizeBytes"
                            :max-count="mediaMaxCount"
                            :min-count="mediaMinCount"
                            :capture-source="mediaCaptureSource"
                            @update:acceptedTypes="setConfig('accepted_types', $event)"
                            @update:maxFileSizeBytes="setConfig('max_file_size_bytes', $event)"
                            @update:maxCount="setConfig('max_count', $event)"
                            @update:minCount="setConfig('min_count', $event)"
                            @update:captureSource="setConfig('capture_source', $event)"
                        />
                    </template>

                    <template v-else-if="activeTab === 'prefill'">
                        <PrefillEditor
                            :field-key="field.key"
                            :source="prefillSource"
                            :url-param="prefillUrlParam"
                            :default-value="field.default_value"
                            @update:source="setConfig('prefill_source', $event)"
                            @update:urlParam="setConfig('url_param', $event)"
                            @update:defaultValue="setField('default_value', $event)"
                        />
                    </template>

                    <template v-else-if="activeTab === 'validation'">
                        <ValidationEditor
                            :validations="field.validations"
                            :rule-types="enums.validation_rule_types"
                            :operators="enums.comparison_operators"
                            @update:validations="setValidations"
                        />
                    </template>

                    <template v-else-if="activeTab === 'advanced'">
                        <MdsFormField label="Field key" help="Referenced in expressions and exports. Lowercase, unique." v-slot="{ id, describedby }">
                            <MdsTextInput
                                :id="id"
                                :describedby="describedby"
                                :model-value="field.key"
                                @update:model-value="setField('key', $event)"
                            />
                        </MdsFormField>
                        <MdsFormField label="Section" v-slot="{ id }">
                            <MdsSelect
                                :id="id"
                                :model-value="field.form_section_id ?? ''"
                                :options="sectionOptions"
                                @update:model-value="reparent($event)"
                            />
                        </MdsFormField>
                        <ConditionEditor
                            :key="`field-${field.uid}`"
                            :expression="field.relevant_expression"
                            :catalogue="catalogue"
                            legend="Show this question only when…"
                            @update:expression="setField('relevant_expression', $event)"
                        />
                        <MdsFormField label="Appearance hint" v-slot="{ id }">
                            <MdsTextInput
                                :id="id"
                                :model-value="field.appearance ?? ''"
                                @update:model-value="setField('appearance', $event || null)"
                            />
                        </MdsFormField>
                        <MdsFormField label="Default value" v-slot="{ id }">
                            <MdsTextInput
                                :id="id"
                                :model-value="field.default_value ?? ''"
                                @update:model-value="setField('default_value', $event || null)"
                            />
                        </MdsFormField>
                        <div class="config__checks">
                            <MdsCheckbox
                                :model-value="field.is_pii"
                                label="Contains personal data (PII)"
                                @update:model-value="setField('is_pii', $event)"
                            />
                            <MdsCheckbox
                                :model-value="field.is_sensitive"
                                label="Sensitive"
                                @update:model-value="setField('is_sensitive', $event)"
                            />
                            <MdsCheckbox
                                :model-value="field.is_queryable"
                                label="Indexed for reporting"
                                @update:model-value="setField('is_queryable', $event)"
                            />
                        </div>
                        <MdsFormField v-if="field.is_queryable" label="Indexed data type" v-slot="{ id }">
                            <MdsSelect
                                :id="id"
                                :model-value="field.indexed_data_type ?? ''"
                                :options="enums.indexed_data_types"
                                placeholder="Choose a type"
                                @update:model-value="setField('indexed_data_type', $event || null)"
                            />
                        </MdsFormField>

                        <!-- Save this field to the reusable question library (Increment G9b) — one click; the item
                             is named from the label and appears in the left-pane Library. -->
                        <div class="config__library">
                            <MdsButton
                                variant="secondary"
                                icon-left="plus"
                                :disabled="saving"
                                @click="saveToLibrary"
                            >
                                Save to library
                            </MdsButton>
                            <p v-if="librarySaved" class="config__library-note" role="status" aria-live="polite">
                                Saved “{{ librarySaved }}” to your library.
                            </p>
                        </div>
                    </template>
                </template>

                <!-- ── Section editor ───────────────────────────────────────── -->
                <template v-else-if="section">
                    <template v-if="activeTab === 'basics'">
                        <MdsFormField label="Section title" v-slot="{ id }">
                            <MdsTextInput :id="id" :model-value="section.label" @update:model-value="setSection('label', $event)" />
                        </MdsFormField>
                        <MdsFormField label="Section key" help="Lowercase, unique within the form." v-slot="{ id, describedby }">
                            <MdsTextInput
                                :id="id"
                                :describedby="describedby"
                                :model-value="section.key"
                                @update:model-value="setSection('key', $event)"
                            />
                        </MdsFormField>
                        <MdsFormField label="Description" v-slot="{ id }">
                            <MdsTextarea
                                :id="id"
                                :model-value="section.description ?? ''"
                                :rows="2"
                                @update:model-value="setSection('description', $event || null)"
                            />
                        </MdsFormField>
                    </template>

                    <template v-else-if="activeTab === 'advanced'">
                        <MdsCheckbox
                            :model-value="section.is_repeatable"
                            label="Repeatable group"
                            @update:model-value="setSection('is_repeatable', $event)"
                        />
                        <div v-if="section.is_repeatable" class="config__row">
                            <MdsFormField label="Min instances" v-slot="{ id }">
                                <MdsNumberInput
                                    :id="id"
                                    :model-value="section.min_instances"
                                    :min="0"
                                    @update:model-value="setSection('min_instances', $event)"
                                />
                            </MdsFormField>
                            <MdsFormField label="Max instances" v-slot="{ id }">
                                <MdsNumberInput
                                    :id="id"
                                    :model-value="section.max_instances"
                                    :min="0"
                                    @update:model-value="setSection('max_instances', $event)"
                                />
                            </MdsFormField>
                        </div>
                        <ConditionEditor
                            :key="`section-${section.uid}`"
                            :expression="section.relevant_expression"
                            :catalogue="catalogue"
                            legend="Show this section only when…"
                            @update:expression="setSection('relevant_expression', $event)"
                        />
                    </template>
                </template>
            </MdsTabs>
        </template>
    </div>
</template>

<style scoped>
.config {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    padding: var(--mds-space-4);
    height: 100%;
    overflow-y: auto;
}

.config__empty {
    margin: auto;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-md-font-size);
    text-align: center;
}

/*
 * `.config__tabs`, `.config__tab` and `.config__panel` are DELETED, not left behind — a migration removes
 * the old scoped CSS with the markup, or dead rules for an element that is no longer on the page read to
 * the next author as a component still in use (DSR §3.4's own as-built rule, learned twice on breadcrumbs).
 *
 * Three defects went with them, and none was cosmetic. The selected tab's 2px underline was
 * `action-primary-bg` — a FILL, guaranteed only against the text printed on it, on a non-text indicator
 * that owes 3:1 against the surface behind it; the same substitution J2a measured on MdsTabNav and J4a
 * measured on the personalization accent bar. The panel carried a tabindex of 0 while being full of form
 * controls, which the APG reserves for panels holding nothing focusable, so it was a redundant stop in the
 * app's tightest pane. And it then suppressed its own focus ring, so the stop it had just minted was
 * invisible to whoever landed on it.
 */

.config__error {
    margin: 0;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-danger-text);
    font-size: var(--mds-type-body-sm-font-size);
}

.config__note {
    margin: 0;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.config__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.config__group-label {
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.config__checks {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

/* Save-to-library affordance (Increment G9b) — set off from the field settings above it. */
.config__library {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-3);
    padding-top: var(--mds-space-3);
    border-top: 1px solid var(--mds-color-border-default);
}

.config__library-note {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.config__row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--mds-space-3);
}
</style>
