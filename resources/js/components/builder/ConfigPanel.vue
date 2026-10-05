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
import { useEntitlements } from '@/composables/useEntitlements';
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
import ContentBlocksEditor from './ContentBlocksEditor.vue';
import FieldTypeControl from './FieldTypeControl.vue';
import GeoEditor from './GeoEditor.vue';
import LikertMatrixEditor from './LikertMatrixEditor.vue';
import LinkedChoicesEditor from './LinkedChoicesEditor.vue';
import MatrixEditor from './MatrixEditor.vue';
import MediaEditor from './MediaEditor.vue';
import PrefillEditor from './PrefillEditor.vue';
import ValidationEditor from './ValidationEditor.vue';
import type { ContentBlock } from './content-blocks';
import { sortFieldSaveErrors } from './field-save-errors';
import type { BuilderStore } from './useBuilderStore';
import type { BuilderValidation, ComparableField, ConditionCatalogue, EnumOption, LocalField, LocalSection } from './types';
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
const saveFieldErrors = props.store.saveFieldErrors;
// M128 (`R-d001de0c`): a refused save, marked ON the selected field's controls; every other key in words, and a line per OTHER refused field.
const fieldSaveErrors = computed(() => sortFieldSaveErrors(field.value ? saveFieldErrors.value[field.value.uid] : undefined));
const otherRefusals = computed<string[]>(() => props.store.fields.value.filter((f) => f.uid !== field.value?.uid && saveFieldErrors.value[f.uid] !== undefined).map((f) => `“${f.label || f.key}” has a change that was not saved. Select it to see why.`));
const saveIssues = computed<string[]>(() => [...fieldSaveErrors.value.listed.map((issue) => issue.text), ...otherRefusals.value]);
const enums = props.store.enums;
const saving = props.store.saving;
// Transient "Saved to library" confirmation (Increment G9b), cleared after a beat.
const librarySaved = props.store.librarySaved;

// The plan gate this pane was missing (M90, docs/feature-backlog.md:8919). Builder.vue hides the
// Fields⇄Library toggle behind exactly this check and states the rule at its own call site — "each is
// server-gated on its route; this only spares a 402 click" — but the Save-to-library button lives here and
// was never given one. It was the ONE route in the whole `feature:`/`module:` population a denied tenant
// could still reach, which is why the row that filed the server defect read as if it happened every time.
// The server arm is repaired too and is the load-bearing half; this spares the click.
const { feature } = useEntitlements();

const optionTypes = new Set<string>();
const advancedTypes = new Set<string>();
const configEditorByType = new Map<string, string | null>();
// M115 — `ValueShape::for()`'s answer per field type, arriving on the palette entry beside the three facts
// already harvested here. It decides what the Validation tab may offer and whether that tab exists at all.
const shapeByType = new Map<string, string>();
// M130 (`R-6c76bed2`) — the layouts an author may choose per type, `FieldAppearance::for()` transmitted on the palette.
const layoutsByType = new Map<string, EnumOption[]>();
props.store.palette.forEach((group) =>
    group.types.forEach((type) => {
        if (type.has_options) optionTypes.add(type.value);
        if (type.advanced) advancedTypes.add(type.value);
        configEditorByType.set(type.value, type.config_editor);
        shapeByType.set(type.value, type.value_shape);
        layoutsByType.set(type.value, type.appearances ?? []);
    }),
);

/** The shape whose rules the Validation tab may offer. `no_answer` is `note` and `page_break`. */
const SHAPE_NO_ANSWER = 'no_answer';

/** `RequiredMode::Conditional`. `ServerField.is_required` is a bare string here, so the literal needs a home. */
const REQUIRED_MODE_CONDITIONAL = 'conditional';

/** M130 (`D69`): once a note has content blocks, respondents see those instead, and the label is the author's alone. */
const NOTE_LABEL_HELP = 'Respondents see this label only while the note has no content. Once it has content, the label names the note here and in the PDF.';

const requiredOptions = enums.required_modes;
const sectionOptions = computed<EnumOption[]>(() => [
    { value: '', label: 'No section (top level)' },
    ...props.store.sections.value.map((s) => ({ value: s.id, label: s.label })),
]);

const configEditor = computed<string | null>(() =>
    field.value ? configEditorByType.get(field.value.field_type) ?? null : null,
);

const valueShape = computed<string>(() =>
    field.value ? shapeByType.get(field.value.field_type) ?? '' : '',
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
        if (configEditor.value === 'content') list.push({ key: 'content', label: 'Content' });
        // ⛔ M115 — NO VALIDATION TAB FOR A FIELD THAT CARRIES NO ANSWER. `ValueShape::allows()` refuses
        // every rule type for `no_answer` (`note`, `page_break`), and since M113 the publish gate refuses
        // them too — so this tab was a route to a form that could not be published, offered on the two
        // types where it can never do anything.
        if (valueShape.value !== SHAPE_NO_ANSWER) list.push({ key: 'validation', label: 'Validation' });
        list.push({ key: 'advanced', label: 'Advanced' });
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

// M130 (`R-6c76bed2`): the free-text "Appearance hint" became a choice of layouts. A stored value outside the
// type's list — an XLSForm import's `likert`, or a layout kept through a type change — is shown as it is and
// may be kept or replaced, never edited, because the save request accepts it only unchanged.
const layoutOptions = computed<EnumOption[]>(() => (field.value ? layoutsByType.get(field.value.field_type) ?? [] : []));
const keptAppearance = computed<string | null>(() => {
    const stored = field.value?.appearance ?? null;
    return stored === null || stored === '' || layoutOptions.value.some((option) => option.value === stored) ? null : stored;
});
const layoutSelectOptions = computed<EnumOption[]>(() => [
    { value: '', label: 'One per line (default)' },
    ...layoutOptions.value,
    ...(keptAppearance.value !== null ? [{ value: keptAppearance.value, label: `Kept from an earlier setting or an import: ${keptAppearance.value}` }] : []),
]);
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
// A note's content blocks (M129, `R-6dedc3a9`). The server's `ContentBlocks` rule is the authority on the shape.
const contentBlocks = computed<ContentBlock[]>(() => {
    const raw = field.value?.config.content;
    return Array.isArray(raw) ? (raw as ContentBlock[]) : [];
});

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
                numeric: NUMERIC_TYPES.has(f.field_type), operand_kind: props.store.palette.flatMap((g) => g.types).find((t) => t.value === f.field_type)?.operand_kind,
                options: choicesOf(f),
            })),
        kinds: enums.operand_kinds, // M134 (`R-910d2286`) — what each question may be compared with, transmitted
        // `count()` needs a repeatable section: a non-repeating one has no instances (H21a seeds repeatables only).
        repeatables: props.store.sections.value
            .filter((s) => s.is_repeatable && s.key !== '' && s.key !== ownKey)
            .map((s) => ({ key: s.key, label: s.label.trim() === '' ? s.key : s.label })),
    };
});

// The fields a validation row may compare against (M115). Same source as the catalogue above and the same
// two exclusions — an empty key cannot be referenced and the row's own field can only ever be a self-cycle
// — but it carries the VALUE SHAPE rather than the catalogue's `numeric` flag, because the operators a
// conditional rule may use are decided by the compared field's shape. Those two are NOT the same set:
// `numeric` is four field types and `ValueShape::allowsOperator()` admits five, a divergence
// `FieldTypeMirrorDriftTest` pins deliberately and `R-c1f90d7a` owns.
const comparableFields = computed<ComparableField[]>(() =>
    props.store.fields.value
        .filter((f) => f.key !== '' && f.key !== (field.value?.key ?? null))
        .map((f) => ({
            key: f.key,
            label: f.label.trim() === '' ? f.key : f.label,
            value_shape: shapeByType.get(f.field_type) ?? '',
        })),
);

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
/**
 * An emptied list REMOVES the key rather than saving `[]`: a note with no `content` is today's one-line note, and
 * the absence is what every reader of the config already treats as "no blocks".
 */
function setContent(blocks: ContentBlock[]): void {
    const target = field.value;
    if (!target) return;
    if (blocks.length > 0) {
        setConfig('content', blocks);
        return;
    }
    const { content: _removed, ...rest } = target.config;
    target.config = rest;
    props.store.touch(target.uid, 'field');
}
function setValidations(value: BuilderValidation[]): void {
    setField('validations', value);
}

/*
|--------------------------------------------------------------------------
| Increment M116 — "Required when…" on the Basics tab.
|
| ⛔ CHOOSING `Conditional` USED TO DO NOTHING AT ALL. `SemanticValidator::requiredState()` honours the mode
| only through a `required_if` / `required_with` unit, and with none it falls out as OPTIONAL in silence —
| so the segmented control wrote a setting no engine acted on, and the editor it looked like it should open
| (Advanced → `ConditionEditor`) writes `relevant_expression`, which HIDES a field rather than requiring it.
|
| ⛔ AND THE ROW'S PRESCRIPTION — "reuse `ConditionRow.vue` directly" — IS WRONG, MEASURED. That component
| is bound to the `relevant_expression` AST: its props and emits are `Condition` values, its subject side
| offers count and literal operands a `required_if` cannot use, and its operator vocabulary diverges from
| the stored `ComparisonOperator` in both directions. `ValidationEditor` is the component that already
| renders this exact row, so the reveal mounts a second, restricted instance of it.
|
| ⛔ ONE ARRAY, TWO SURFACES, AND THE PARTITION IS WHAT KEEPS THEM HONEST. `ValidationEditor` emits a WHOLE
| fresh array, so handing this instance's output straight to `setValidations` would delete every rule the
| Validation tab owns. `setRequiredRules` substitutes POSITIONALLY instead: each row the Basics surface does
| not own keeps its index, so the Validation tab does not reshuffle under the author while they edit here.
| The two are never mounted at once (the tabs are `v-if`-switched) and both read the same reactive array, so
| a tab switch re-derives from truth rather than from a copy.
|--------------------------------------------------------------------------
*/

/** The rule kinds that can make a field required — transmitted, never a literal pair of names here. */
const requiredRuleTypes = computed<string[]>(() =>
    enums.validation_rule_types.filter((rule) => rule.governs_requiredness).map((rule) => rule.value),
);

/**
 * ⚠️ `expression === null` IS TESTED FIRST, MIRRORING `SemanticValidator::family()`. A raw-expression row
 * carries a null `rule_type` and is classified by its expression, never by the rule column.
 */
function isRequiredRule(row: BuilderValidation): boolean {
    return row.expression === null && row.rule_type !== null && requiredRuleTypes.value.includes(row.rule_type);
}

const requiredRules = computed<BuilderValidation[]>(() => (field.value?.validations ?? []).filter(isRequiredRule));

const isConditionalRequired = computed<boolean>(
    () => field.value?.is_required === REQUIRED_MODE_CONDITIONAL && valueShape.value !== SHAPE_NO_ANSWER,
);

function setRequiredRules(next: BuilderValidation[]): void {
    const current = field.value?.validations ?? [];
    const merged: BuilderValidation[] = [];
    let cursor = 0;

    for (const row of current) {
        if (isRequiredRule(row)) {
            // A slot this surface owns: take the next edited row, or drop the slot when one was removed.
            if (cursor < next.length) merged.push(next[cursor++]);
        } else {
            merged.push(row);
        }
    }
    // Anything left over is a rule the author just added here.
    while (cursor < next.length) merged.push(next[cursor++]);

    setValidations(merged.map((row, i) => ({ ...row, sequence: i })));
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
        <div v-if="saveError || saveIssues.length > 0" class="config__error" role="alert">
            <p v-if="saveError">{{ saveError }}</p>
            <ul v-if="saveIssues.length > 0"><li v-for="issue in saveIssues" :key="issue">{{ issue }}</li></ul>
        </div>

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
                        <FieldTypeControl :store="store" />
                        <MdsFormField label="Label" :help="field.field_type === 'note' ? NOTE_LABEL_HELP : undefined" :error="fieldSaveErrors.inline.label" v-slot="{ id, describedby, invalid }">
                            <MdsTextInput :id="id" :describedby="describedby" :invalid="invalid" :model-value="field.label" @update:model-value="setField('label', $event)" />
                        </MdsFormField>
                        <MdsFormField
                            v-if="isCalculated"
                            label="Calculation formula" :error="fieldSaveErrors.inline['config.calculated_formula']"
                            help="Evaluated on the server. Use ${field} references, arithmetic (+ - * /), comparisons, and if()/count()/int()/today()/now()."
                            v-slot="{ id, describedby, invalid }"
                        >
                            <MdsTextarea
                                :id="id"
                                :describedby="describedby" :invalid="invalid"
                                :model-value="calculatedFormula"
                                :rows="2"
                                placeholder="e.g. ${quantity} * ${unit_price}"
                                @update:model-value="setConfig('calculated_formula', $event || null)"
                            />
                        </MdsFormField>
                        <MdsFormField label="Help text" :error="fieldSaveErrors.inline.hint" v-slot="{ id, describedby, invalid }">
                            <MdsTextarea
                                :id="id" :describedby="describedby" :invalid="invalid"
                                :model-value="field.hint ?? ''"
                                :rows="2"
                                @update:model-value="setField('hint', $event || null)"
                            />
                        </MdsFormField>
                        <MdsFormField v-if="!isCalculated" label="Placeholder" :error="fieldSaveErrors.inline.placeholder" v-slot="{ id, describedby, invalid }">
                            <MdsTextInput
                                :id="id" :describedby="describedby" :invalid="invalid"
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

                            <fieldset v-if="isConditionalRequired" class="config__when">
                                <legend class="config__when-legend">Required when…</legend>
                                <ValidationEditor
                                    :validations="requiredRules" :field-validations="field.validations" combinator-family="required"
                                    :rule-types="enums.validation_rule_types"
                                    :operators="enums.comparison_operators"
                                    :value-shape="valueShape"
                                    :comparable-fields="comparableFields"
                                    :restrict-to-rule-types="requiredRuleTypes"
                                    add-label="Add condition"
                                    empty-text="No condition yet — this question stays optional until you add one."
                                    @update:validations="setRequiredRules"
                                />
                                <p class="config__when-note">These also appear on the Validation tab.</p>
                            </fieldset>
                        </div>
                    </template>

                    <template v-else-if="activeTab === 'options'">
                        <LinkedChoicesEditor v-if="enums.linked_choice_types?.includes(field.field_type)" :config="field.config" :sources="props.store.linkableSources" @update:config="field.config = $event; props.store.touch(field.uid, 'field')" /><ChoicesEditor v-if="field.config.options_source == null" :options="choices" @update:options="setConfig('options', $event)" />
                        <MdsFormField
                            v-if="layoutOptions.length > 0"
                            label="Choice layout"
                            help="How the choices are arranged on the form. Side by side and in columns suit short choices."
                            :error="fieldSaveErrors.inline.appearance"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <MdsSelect
                                :id="id" :describedby="describedby" :invalid="invalid"
                                :model-value="field.appearance ?? ''"
                                :options="layoutSelectOptions"
                                @update:model-value="setField('appearance', $event || null)"
                            />
                        </MdsFormField>
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

                    <template v-else-if="activeTab === 'content'">
                        <ContentBlocksEditor :blocks="contentBlocks" :form-id="store.formId" @update:blocks="setContent" />
                    </template>

                    <template v-else-if="activeTab === 'validation'">
                        <ValidationEditor
                            :validations="field.validations" combinator-family="constraint"
                            :rule-types="enums.validation_rule_types"
                            :operators="enums.comparison_operators"
                            :value-shape="valueShape"
                            :comparable-fields="comparableFields"
                            @update:validations="setValidations"
                        />
                    </template>

                    <template v-else-if="activeTab === 'advanced'">
                        <MdsFormField label="Field key" help="Referenced in expressions and exports. Lowercase, unique." :error="fieldSaveErrors.inline.key" v-slot="{ id, describedby, invalid }">
                            <MdsTextInput
                                :id="id"
                                :describedby="describedby" :invalid="invalid"
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
                        <div v-if="layoutOptions.length === 0 && keptAppearance !== null" class="config__kept" data-kept-appearance>
                            <p class="config__kept-text">
                                Appearance <code>{{ keptAppearance }}</code> is kept from an earlier setting or an import. It
                                goes back out in XLSForm exports and does not change how this form looks.
                            </p>
                            <MdsButton size="sm" variant="secondary" @click="setField('appearance', null)">Remove appearance</MdsButton>
                        </div>
                        <MdsFormField label="Default value" :error="fieldSaveErrors.inline.default_value" v-slot="{ id, describedby, invalid }">
                            <MdsTextInput
                                :id="id" :describedby="describedby" :invalid="invalid"
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
                        <MdsFormField v-if="field.is_queryable" label="Indexed data type" :error="fieldSaveErrors.inline.indexed_data_type" v-slot="{ id, describedby, invalid }">
                            <MdsSelect
                                :id="id" :describedby="describedby" :invalid="invalid"
                                :model-value="field.indexed_data_type ?? ''"
                                :options="enums.indexed_data_types"
                                placeholder="Choose a type"
                                @update:model-value="setField('indexed_data_type', $event || null)"
                            />
                        </MdsFormField>

                        <!-- Save this field to the reusable question library (Increment G9b) — one click; the item
                             is named from the label and appears in the left-pane Library. Gated on the plan
                             (M90) the same way Builder.vue gates the Fields⇄Library toggle: the route refuses
                             it either way, this only spares the click. -->
                        <div v-if="feature('field_library')" class="config__library">
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

/* D28 guards this spill at the host, and the affordance that works is the WRAP — not the
   `min-width: 0; max-width: 100%` D28 first illustrated, which is inert here. This host is a flex
   COLUMN, so the control is already exactly container-width; what overflows is the control's own
   flex LINE, whose `__seg` carries `min-width: auto` and never shrinks below its longest word plus
   its padding. `.config` is `overflow-y: auto`, so the spill became a real horizontal scrollbar that
   the document-level overflow assertion files as `absorbed` and never reports. */
.config__group .mds-segmented {
    flex-wrap: wrap;
}

.config__group-label {
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

/* M116 — the "Required when…" reveal. `min-width: 0` for the same reason the segmented control above
   wraps: this column is `overflow-y: auto`, so anything that refuses to shrink becomes a real horizontal
   scrollbar rather than a clipped edge, and a fieldset's default `min-width: min-content` refuses. */
.config__when {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: 0;
    border: 0;
    min-width: 0;
    margin-top: var(--mds-space-2);
}

/* Copied verbatim from `ConditionEditor.vue`'s `.cond__legend`, which the builder axe scan already covers
   on the Advanced tab — this one is not scanned (no spec drives the segmented control to Conditional
   mid-loop), so matching an already-proved set of tokens is what stands in for the scan. */
.config__when-legend {
    padding: 0;
    margin-bottom: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

/* ⚠️ `text-secondary`, NOT `text-muted`: the latter is not a token in this design system — it exists in two
   places in the tree and one of them is a comment saying so — and an undefined custom property silently
   inherits rather than erroring, so the note would have rendered at body colour and nothing would have said. */
.config__when-note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
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

/* M128 (`R-d001de0c`): the alert now holds the save sentence and, under it, every refused setting the panel
   cannot mark in place. Appended at the end because this stylesheet is cited by line from the backlog. */
.config__error p,
.config__error ul {
    margin: 0;
}

.config__error ul {
    padding-left: var(--mds-space-4);
}

/* M130 (`R-6c76bed2`): an appearance the type has no layout setting for, shown as kept rather than editable.
   `text-secondary` for the same reason `.config__when-note` gives; the column is `overflow-y: auto`, so the
   value wraps rather than pushing a horizontal scrollbar. */
.config__kept {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--mds-space-2);
    min-width: 0;
}

.config__kept-text {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}
</style>
