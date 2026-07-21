<script setup lang="ts">
/**
 * One manual-encoding input (Increment F4b). Maps a published field descriptor onto the matching shared
 * design-system control and binds its value + per-field 422 error. "Render-supported, mark-the-rest": the
 * ~14 Phase-1 scalar types render a real control; `note` renders as static prose; anything else (advanced
 * types, or a field inside a repeatable section) shows a read-only "not available for manual entry" notice
 * rather than being silently dropped.
 *
 * Single controls (text/number/select/date) use MdsFormField (label ↔ input association). Group controls
 * (a multi-select checkbox group, the yes/no segmented control) can't be driven by one label-for, so they
 * render a real <fieldset>/<legend> with their own aria-live error region — same visual anatomy, correct
 * grouping semantics.
 */
import {
    MdsCheckbox,
    MdsFormField,
    MdsNumberInput,
    MdsSegmentedControl,
    MdsSelect,
    MdsTextarea,
    MdsTextInput,
} from '@meridian/design-system';
import { computed, defineAsyncComponent } from 'vue';
import MatrixGrid from './MatrixGrid.vue';

// Geospatial capture (Increment G5b2) is lazy-loaded so its Leaflet dependency + CSS only ship in the chunk a
// geo-bearing form actually mounts — geo-free forms pay zero bundle cost.
const GeoInput = defineAsyncComponent(() => import('./GeoInput.vue'));

// Media capture (Increment G6) is lazy-loaded so its upload/preview logic only ships in a media-bearing form.
const MediaInput = defineAsyncComponent(() => import('./MediaInput.vue'));

export interface CascadeLevel {
    key: string;
    label: string;
}

export interface CascadeOption {
    value: string;
    label: string;
    level: string;
    parent: string | null;
}

export interface MatrixOption {
    value: string;
    label: string;
}

export interface MatrixConfig {
    rows: MatrixOption[];
    columns: MatrixOption[];
    cells: MatrixOption[];
}

// A GeoJSON geometry envelope (Increment G5b2): coordinates in [lon, lat(, alt)] order (the frozen G5b1
// contract). `coordinates` nesting varies by geometry: Point number[], LineString number[][], Polygon
// number[][][]. Concrete (not `unknown`) so Inertia's useForm accepts it as form data.
export interface GeoEnvelope {
    type: string;
    coordinates: number[] | number[][] | number[][][];
    accuracy?: number;
}

// Author config for a geo field (Increment G5b2); labels-free, so it needs no translation resolution. The
// backend emits it (EncodeFormPresenter::geo / schema-mapping buildGeo); the GeoInput control reads it.
export interface GeoFieldConfig {
    captureAltitude: boolean;
    accuracyThreshold: number | null;
    defaultCenter: { lat: number; lon: number } | null;
    defaultZoom: number | null;
}

// One uploaded-file reference an answer to a media field carries (Increment G6). `id` is authoritative (an
// `attachments` row); the rest is display metadata the control shows without a server round-trip. Matches the
// TS engine `MediaAnswer` element + the PHP AttachmentRef the upload endpoint returns.
export interface MediaAttachmentRef {
    id: string;
    mime?: string;
    size?: number;
    name?: string;
    width?: number;
    height?: number;
    duration?: number;
}

// Author config for a media field (Increment G6); the backend emits it (EncodeFormPresenter::media /
// schema-mapping buildMedia). `acceptedTypes`/`maxFileSizeBytes`/`captureSource` drive the file input;
// `maxCount`/`minCount` bound the list (also enforced server-side by processMedia).
export interface MediaFieldConfig {
    acceptedTypes: string[];
    maxFileSizeBytes: number | null;
    maxCount: number | null;
    minCount: number | null;
    captureSource: string | null;
}

// Where a media control POSTs a staged upload (Increment G6): the channel's own endpoint (form-scoped for
// manual encode, share-token-scoped for the guest SPA). Null for non-media fields.
export interface MediaUploadConfig {
    url: string;
    // Increment G8b — offline fallback (guest SPA only): when the live upload can't run (offline / network
    // failure), stash the picked file locally and return a placeholder ref that replays on reconnect. Absent
    // for the always-online manual-encode channel, which keeps the pure upload-on-selection behaviour.
    stashOffline?: (file: File, fieldKey: string) => Promise<MediaAttachmentRef>;
}

export interface EncodeField {
    key: string;
    field_type: string;
    label: string;
    hint: string | null;
    placeholder: string | null;
    required: boolean;
    options: { value: string; label: string }[];
    // Cascading-select hierarchy (Increment G4a); labels already resolved to the active locale by the caller.
    cascade?: { levels: CascadeLevel[]; options: CascadeOption[] } | null;
    // Composite grid config (Increment G4b: matrix / likert_matrix); labels already resolved by the caller.
    matrix?: MatrixConfig | null;
    // Geo capture config (Increment G5b2: geopoint / geotrace / geoshape); null for every other type.
    geo?: GeoFieldConfig | null;
    // Media capture config + upload endpoint (Increment G6: file / image / audio / video); null otherwise.
    media?: MediaFieldConfig | null;
    upload?: MediaUploadConfig | null;
    supported: boolean;
}

// The value shapes an answer slot can hold across the encode controls (list for multi-select, an object for
// the G4b grids, number for numeric fields, "" otherwise). Kept concrete (not `unknown`) so Inertia's useForm
// accepts the form data. `Record<string,string>` = a likert_matrix `{row:score}`; the nested record = a
// matrix `{row:{col:cell}}`.
export type AnswerValue =
    | string
    | number
    | boolean
    | string[]
    | Record<string, string>
    | Record<string, Record<string, string>>
    // A geospatial answer envelope (Increment G5b2).
    | GeoEnvelope
    // A media answer: a list of attachment references (Increment G6).
    | MediaAttachmentRef[]
    | null;

// The visual requirement marker (UX §4.4). When omitted it derives from `field.required`, preserving the
// original manual-encode behaviour (F4b) exactly; the public runtime (F6b) passes it explicitly so an
// `optional` field shows a muted "(optional)" suffix and a `conditional` field shows the required marker
// only once its condition has triggered ('none' until then).
export type RequiredMarker = 'required' | 'optional' | 'none';

const props = defineProps<{
    field: EncodeField;
    modelValue: AnswerValue;
    error?: string;
    requiredMarker?: RequiredMarker;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: AnswerValue] }>();

const marker = computed<RequiredMarker>(() => props.requiredMarker ?? (props.field.required ? 'required' : 'none'));
const showRequiredMarker = computed<boolean>(() => marker.value === 'required');
const showOptionalMarker = computed<boolean>(() => marker.value === 'optional');
// MdsFormField renders its own "(required)" affordance but has no "(optional)" one, so for single controls we
// fold the optional suffix into the label text (kept accessible — it is part of the <label>).
const singleLabel = computed<string>(() =>
    showOptionalMarker.value ? `${props.field.label} (optional)` : props.field.label,
);

const yesNoOptions = [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
];

const control = computed<
    | 'text'
    | 'textarea'
    | 'number'
    | 'select'
    | 'checkboxes'
    | 'yesno'
    | 'scale'
    | 'cascading'
    | 'matrix'
    | 'likert-matrix'
    | 'geo'
    | 'media'
    | 'note'
    | 'unsupported'
>(() => {
    const t = props.field.field_type;
    if (t === 'note') return 'note';
    if (!props.field.supported) return 'unsupported';
    if (['short_text', 'email', 'phone', 'url', 'date', 'time', 'datetime'].includes(t)) return 'text';
    if (t === 'long_text') return 'textarea';
    if (t === 'integer' || t === 'decimal') return 'number';
    if (t === 'single_select' || t === 'dropdown') return 'select';
    if (t === 'multi_select') return 'checkboxes';
    if (t === 'yes_no') return 'yesno';
    if (t === 'likert_scale') return 'scale';
    if (t === 'cascading_select') return 'cascading';
    if (t === 'likert_matrix') return 'likert-matrix';
    if (t === 'matrix') return 'matrix';
    if (t === 'geopoint' || t === 'geotrace' || t === 'geoshape') return 'geo';
    if (t === 'file_upload' || t === 'image_capture' || t === 'audio_capture' || t === 'video_capture') return 'media';
    return 'unsupported';
});

const textType = computed<'text' | 'email' | 'tel' | 'url' | 'date' | 'time' | 'datetime-local'>(() => {
    switch (props.field.field_type) {
        case 'email':
            return 'email';
        case 'phone':
            return 'tel';
        case 'url':
            return 'url';
        case 'date':
            return 'date';
        case 'time':
            return 'time';
        case 'datetime':
            return 'datetime-local';
        default:
            return 'text';
    }
});

// The bare-string binding for text/select/yesno controls (modelValue is typed `unknown` for the union).
const stringValue = computed<string>(() => (typeof props.modelValue === 'string' ? props.modelValue : ''));
const numberValue = computed<number | null>(() => (typeof props.modelValue === 'number' ? props.modelValue : null));
const listValue = computed<string[]>(() => (Array.isArray(props.modelValue) ? (props.modelValue as string[]) : []));

function toggleOption(value: string, checked: boolean): void {
    const current = listValue.value;
    emit('update:modelValue', checked ? [...current, value] : current.filter((v) => v !== value));
}

// ── Cascading select (Increment G4a) ────────────────────────────────────────────────────────────
// The value is an ordered string[] — one chosen value per level. Each level's select is filtered by the
// parent level's current choice; choosing a value drops every deeper level (its parent changed).
const cascadeLevels = computed<CascadeLevel[]>(() => props.field.cascade?.levels ?? []);
const cascadeOptions = computed<CascadeOption[]>(() => props.field.cascade?.options ?? []);
const cascadeValue = computed<string[]>(() => (Array.isArray(props.modelValue) ? (props.modelValue as string[]) : []));

function cascadeOptionsAt(index: number): { value: string; label: string }[] {
    const level = cascadeLevels.value[index];
    if (!level) return [];
    const parent = index === 0 ? null : cascadeValue.value[index - 1] ?? '';
    if (index > 0 && (parent === '' || parent === undefined)) return []; // no parent chosen yet
    return cascadeOptions.value
        .filter((o) => o.level === level.key && (index === 0 ? o.parent === null : o.parent === parent))
        .map((o) => ({ value: o.value, label: o.label }));
}

function cascadeDisabled(index: number): boolean {
    if (index === 0) return false;
    const parent = cascadeValue.value[index - 1];
    return parent === '' || parent === undefined;
}

function setCascadeLevel(index: number, value: string): void {
    const next = cascadeValue.value.slice(0, index); // keep shallower levels, drop this + deeper
    if (value !== '') next.push(value);
    emit('update:modelValue', next);
}
</script>

<template>
    <!-- Single-control fields: label ↔ input via MdsFormField -->
    <MdsFormField
        v-if="['text', 'textarea', 'number', 'select'].includes(control)"
        :label="singleLabel"
        :required="showRequiredMarker"
        :help="field.hint ?? undefined"
        :error="error"
        v-slot="{ id, describedby, invalid }"
    >
        <MdsTextInput
            v-if="control === 'text'"
            :id="id"
            :type="textType"
            :model-value="stringValue"
            :placeholder="field.placeholder ?? undefined"
            :describedby="describedby"
            :invalid="invalid"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <MdsTextarea
            v-else-if="control === 'textarea'"
            :id="id"
            :model-value="stringValue"
            :placeholder="field.placeholder ?? undefined"
            :describedby="describedby"
            :invalid="invalid"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <MdsNumberInput
            v-else-if="control === 'number'"
            :id="id"
            :model-value="numberValue"
            :step="field.field_type === 'integer' ? 1 : undefined"
            :placeholder="field.placeholder ?? undefined"
            :describedby="describedby"
            :invalid="invalid"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <MdsSelect
            v-else-if="control === 'select'"
            :id="id"
            :model-value="stringValue"
            :options="field.options"
            placeholder="Select an option"
            :describedby="describedby"
            :invalid="invalid"
            @update:model-value="emit('update:modelValue', $event)"
        />
    </MdsFormField>

    <!-- Group controls: real fieldset/legend + their own aria-live error region -->
    <fieldset
        v-else-if="control === 'checkboxes' || control === 'yesno' || control === 'scale' || control === 'cascading'"
        class="encode-field"
    >
        <legend class="encode-field__legend">
            {{ field.label
            }}<span v-if="showRequiredMarker" class="encode-field__required"> (required)</span
            ><span v-else-if="showOptionalMarker" class="encode-field__required"> (optional)</span>
        </legend>
        <p v-if="field.hint" class="encode-field__help">{{ field.hint }}</p>

        <div v-if="control === 'checkboxes'" class="encode-field__checks">
            <MdsCheckbox
                v-for="opt in field.options"
                :key="opt.value"
                :label="opt.label"
                :model-value="listValue.includes(opt.value)"
                :invalid="Boolean(error)"
                @update:model-value="toggleOption(opt.value, $event)"
            />
        </div>
        <!-- Likert scale: a single-choice radio group (native radios share a name, so arrow keys move within
             the scale); wraps at narrow viewports so it never forces horizontal page overflow. -->
        <div v-else-if="control === 'scale'" class="encode-scale">
            <label
                v-for="opt in field.options"
                :key="opt.value"
                class="encode-scale__option"
                :class="{ 'encode-scale__option--active': stringValue === opt.value }"
            >
                <input
                    type="radio"
                    class="encode-scale__radio"
                    :name="`scale-${field.key}`"
                    :value="opt.value"
                    :checked="stringValue === opt.value"
                    @change="emit('update:modelValue', opt.value)"
                />
                <span class="encode-scale__label">{{ opt.label }}</span>
            </label>
        </div>
        <!-- Cascading select: one dependent select per level, each filtered by the parent level's choice. -->
        <div v-else-if="control === 'cascading'" class="encode-cascade">
            <MdsFormField
                v-for="(level, i) in cascadeLevels"
                :key="level.key"
                :label="level.label"
                v-slot="{ id, describedby }"
            >
                <MdsSelect
                    :id="id"
                    :model-value="cascadeValue[i] ?? ''"
                    :options="cascadeOptionsAt(i)"
                    :disabled="cascadeDisabled(i)"
                    placeholder="Select an option"
                    :describedby="describedby"
                    @update:model-value="setCascadeLevel(i, $event)"
                />
            </MdsFormField>
        </div>
        <MdsSegmentedControl
            v-else
            :model-value="stringValue"
            :options="yesNoOptions"
            :ariaLabel="field.label"
            @update:model-value="emit('update:modelValue', $event)"
        />

        <div class="encode-field__error" aria-live="polite">
            <template v-if="error">
                <svg class="encode-field__error-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path
                        fill="currentColor"
                        d="M8 1a7 7 0 100 14A7 7 0 008 1zm-.9 3.6h1.8l-.2 5h-1.4l-.2-5zM8 12.4a1 1 0 110-2 1 1 0 010 2z"
                    />
                </svg>
                <span>{{ error }}</span>
            </template>
        </div>
    </fieldset>

    <!-- Composite grids (Increment G4b): matrix (per-cell select) + likert_matrix (radiogroup per row) -->
    <MatrixGrid
        v-else-if="control === 'matrix' || control === 'likert-matrix'"
        :field="field"
        :kind="control"
        :model-value="props.modelValue"
        :error="error"
        :required-marker="marker"
        @update:model-value="emit('update:modelValue', $event)"
    />

    <!-- Geospatial capture (Increment G5b2): geopoint / geotrace / geoshape (lazy Leaflet + a11y baseline) -->
    <GeoInput
        v-else-if="control === 'geo'"
        :field="field"
        :model-value="props.modelValue"
        :error="error"
        :required-marker="marker"
        @update:model-value="emit('update:modelValue', $event)"
    />

    <!-- Media capture (Increment G6): file / image / audio / video (upload-on-selection + preview) -->
    <MediaInput
        v-else-if="control === 'media'"
        :field="field"
        :model-value="props.modelValue"
        :error="error"
        :required-marker="marker"
        @update:model-value="emit('update:modelValue', $event)"
    />

    <!-- Display-only note -->
    <p v-else-if="control === 'note'" class="encode-note">{{ field.label }}</p>

    <!-- Unsupported (advanced / repeat) — surfaced, not silently dropped -->
    <div v-else class="encode-unsupported">
        <span class="encode-unsupported__label">{{ field.label }}</span>
        <p class="encode-unsupported__note">Not available for manual entry yet (Phase 2).</p>
    </div>
</template>

<style scoped>
.encode-field {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.encode-field__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.encode-field__required {
    font-weight: var(--mds-font-weight-regular);
    color: var(--mds-color-text-secondary);
}

.encode-field__help {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.encode-field__checks {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

/* Likert scale — a wrapping row of radio chips (never overflows the page at narrow viewports). */
.encode-scale {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    min-width: 0;
}

.encode-scale__option {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    padding: var(--mds-space-1) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-body);
    cursor: pointer;
}

.encode-scale__option--active {
    border-color: var(--mds-color-border-strong);
    background-color: var(--mds-color-bg-sunken);
    font-weight: var(--mds-font-weight-medium);
}

.encode-scale__radio {
    margin: 0;
    accent-color: currentColor;
}

/* Cascading select — one stacked select per level. */
.encode-cascade {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.encode-field__error {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-1);
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}
.encode-field__error:empty {
    display: none;
}

.encode-field__error-icon {
    flex-shrink: 0;
    width: 1em;
    height: 1em;
    margin-top: 0.15em;
}

.encode-note {
    margin: 0;
    padding: var(--mds-space-3) var(--mds-space-4);
    border-left: 3px solid var(--mds-color-border-strong);
    background-color: var(--mds-color-bg-sunken);
    border-radius: var(--mds-radius-sm);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.encode-unsupported {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.encode-unsupported__label {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
}

.encode-unsupported__note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
    font-style: italic;
}
</style>
