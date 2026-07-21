<script setup lang="ts">
/**
 * The builder's right pane: the config editor for the selected field or section. The panel switcher uses
 * real tab semantics (role=tablist / tab / tabpanel + arrow-key roving) — deliberately NOT
 * MdsSegmentedControl, which is a radiogroup. Content edits update the local model immediately (so the
 * canvas reflects them live) and the store debounces one persist + one undo/redo entry per editing burst;
 * structural changes (move field to another section) go through their own reorder command.
 */
import { computed, ref, watch } from 'vue';
import {
    MdsButton,
    MdsCheckbox,
    MdsFormField,
    MdsNumberInput,
    MdsSegmentedControl,
    MdsSelect,
    MdsTextInput,
    MdsTextarea,
} from '@meridian/design-system';
import CascadingEditor from './CascadingEditor.vue';
import ChoicesEditor from './ChoicesEditor.vue';
import GeoEditor from './GeoEditor.vue';
import LikertMatrixEditor from './LikertMatrixEditor.vue';
import MatrixEditor from './MatrixEditor.vue';
import MediaEditor from './MediaEditor.vue';
import ValidationEditor from './ValidationEditor.vue';
import type { BuilderStore } from './useBuilderStore';
import type { BuilderValidation, EnumOption, LocalField, LocalSection } from './types';

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
const tabRefs = ref<HTMLButtonElement[]>([]);

watch(
    () => field.value?.uid ?? section.value?.uid ?? null,
    () => {
        activeTab.value = 'basics';
    },
);
watch(tabs, (list) => {
    if (!list.some((t) => t.key === activeTab.value)) activeTab.value = list[0]?.key ?? 'basics';
});

function selectTab(key: string): void {
    activeTab.value = key;
}
function onTabKeydown(event: KeyboardEvent, index: number): void {
    const list = tabs.value;
    let next = index;
    if (event.key === 'ArrowRight') next = (index + 1) % list.length;
    else if (event.key === 'ArrowLeft') next = (index - 1 + list.length) % list.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = list.length - 1;
    else return;
    event.preventDefault();
    activeTab.value = list[next].key;
    tabRefs.value[next]?.focus();
}

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
        <div v-if="!field && !section" class="config__empty">
            <p>Select a field or section to configure it.</p>
        </div>

        <template v-else>
            <div class="config__tabs" role="tablist" aria-label="Configuration sections">
                <button
                    v-for="(tab, i) in tabs"
                    :key="tab.key"
                    ref="tabRefs"
                    type="button"
                    role="tab"
                    :id="`config-tab-${tab.key}`"
                    :aria-selected="activeTab === tab.key"
                    :tabindex="activeTab === tab.key ? 0 : -1"
                    class="config__tab"
                    :class="{ 'is-active': activeTab === tab.key }"
                    @click="selectTab(tab.key)"
                    @keydown="onTabKeydown($event, i)"
                >
                    {{ tab.label }}
                </button>
            </div>

            <p v-if="saveError" class="config__error" role="alert">{{ saveError }}</p>

            <!-- ── Field editor ─────────────────────────────────────────────── -->
            <div
                v-if="field"
                :id="`config-panel-${activeTab}`"
                role="tabpanel"
                :aria-labelledby="`config-tab-${activeTab}`"
                tabindex="0"
                class="config__panel"
            >
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
                    <MdsFormField label="Relevant (display) expression" help="Shown only when this holds. Supports ${field} refs, and/or/not, = != > < >= <=, arithmetic, and selected()/count(). Checked when you publish." v-slot="{ id, describedby }">
                        <MdsTextarea
                            :id="id"
                            :describedby="describedby"
                            :model-value="field.relevant_expression ?? ''"
                            :rows="2"
                            @update:model-value="setField('relevant_expression', $event || null)"
                        />
                    </MdsFormField>
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
            </div>

            <!-- ── Section editor ───────────────────────────────────────────── -->
            <div
                v-else-if="section"
                :id="`config-panel-${activeTab}`"
                role="tabpanel"
                :aria-labelledby="`config-tab-${activeTab}`"
                tabindex="0"
                class="config__panel"
            >
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
                    <MdsFormField label="Relevant (display) expression" help="Shown only when this holds. Supports ${field} refs, and/or/not, = != > < >= <=, arithmetic, and selected()/count(). Checked when you publish." v-slot="{ id, describedby }">
                        <MdsTextarea
                            :id="id"
                            :describedby="describedby"
                            :model-value="section.relevant_expression ?? ''"
                            :rows="2"
                            @update:model-value="setSection('relevant_expression', $event || null)"
                        />
                    </MdsFormField>
                </template>
            </div>
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

.config__tabs {
    display: flex;
    gap: var(--mds-space-1);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.config__tab {
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 0;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    background: transparent;
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    cursor: pointer;
}

.config__tab.is-active {
    color: var(--mds-color-action-primary-fg);
    border-bottom-color: var(--mds-color-action-primary-bg);
}

.config__tab:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-radius: var(--mds-radius-sm);
}

.config__panel {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}
.config__panel:focus-visible {
    outline: none;
}

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
