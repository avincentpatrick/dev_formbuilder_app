/**
 * Two projections of the frozen `schema_snapshot` the SPA fetches from F5:
 *  - `buildEngineSchema()` → the static `fields`/`sections`/`validations` the F6a `SemanticValidator` consumes
 *    (the engine's `SchemaField` is intentionally minimal — relevance/validation columns only; id == key since
 *    the snapshot is FK-by-key). Answers + locale are plugged in reactively at evaluate time.
 *  - `buildRenderModel()` → the UI-facing superset (labels/hints/options/translations/control kind) the
 *    components walk; the engine never sees any of this.
 *
 * Keeping them separate is deliberate: the engine schema is the sole thing the byte-identical PHP/TS authority
 * agrees on; the render model is presentation only. The SPA maps INTO the engine and only FILTERS what the
 * engine returns — it never re-implements any relevance/constraint logic.
 */

import type {
    CascadeConfig,
    ComparisonOperator,
    GridConfig,
    LogicOperator,
    RenderSources,
    SchemaField,
    SchemaSection,
    SemanticInput,
    ValidationRow,
    ValidationRuleType,
} from '../engine';
import type {
    AnswerMap,
    ControlKind,
    RawField,
    RawSection,
    RenderCascade,
    RenderCascadeLevel,
    RenderCascadeOption,
    RenderField,
    RenderGeo,
    RenderMatrix,
    RenderMedia,
    RenderModel,
    RenderOption,
    RenderSection,
    SchemaResponse,
} from './types';

export type EngineSchema = Pick<SemanticInput, 'fields' | 'sections' | 'validations'>;

// The types with a real renderer (mirror of EncodeFormPresenter::SUPPORTED). Everything else is surfaced as
// an "unsupported" notice rather than silently dropped. Increment G4a adds likert_scale + cascading_select.
const SUPPORTED = new Set<string>([
    'short_text', 'long_text', 'email', 'phone', 'url',
    'integer', 'decimal',
    'date', 'time', 'datetime',
    'single_select', 'multi_select', 'dropdown', 'yes_no',
    'likert_scale', 'cascading_select',
    // Increment G4b: the object-valued grids.
    'matrix', 'likert_matrix',
    // Increment G5b2: geospatial capture.
    'geopoint', 'geotrace', 'geoshape',
    // Increment G6: media capture (signature's canvas control is deferred, so it stays unsupported).
    'file_upload', 'image_capture', 'audio_capture', 'video_capture',
]);

/**
 * Field types that render NOTHING on a respondent's form (Increment H7).
 *
 * This set deliberately does NOT mirror `EncodeFormPresenter::OMITTED`, and the divergence is the point.
 * H7 makes the two channels asymmetric: a keyer SHOULD see a hidden field (they are staff, and they may be
 * the only source of an externally-sourced value on a paper form), while a respondent must never see one —
 * that is what the type means.
 *
 * Without this set all three of these fall through `SUPPORTED` below into `controlFor()`'s `'unsupported'`
 * branch and render the encode channel's "Not available for manual entry yet (Phase 2)" notice on a public
 * form. That was live for `hidden`, `calculated` and `page_break` alike before H7; nothing pinned it.
 *
 * Dropping them from the step model is only SAFE because neither engine can produce an error on a hidden or
 * calculated field (`semantic-validator.ts::collectFieldErrors`) — otherwise the error-summary banner could
 * offer a jump link to a row that does not exist. `page_break` carries no answer at all.
 */
const RENDERS_NOTHING = new Set<string>(['hidden', 'calculated', 'page_break']);

/** Whether a field type is rendered to a respondent at all (Increment H7). */
export function rendersNothing(fieldType: string): boolean {
    return RENDERS_NOTHING.has(fieldType);
}

// Field types that carry an author-defined option list (mirror of FieldType::hasOptions()).
const HAS_OPTIONS = new Set<string>(['single_select', 'multi_select', 'dropdown', 'likert_scale']);

const TEXT_TYPES = new Set<string>(['short_text', 'email', 'phone', 'url', 'date', 'time', 'datetime']);

/** Derive the control kind exactly as the reused F4b `FieldInput.vue` does. */
export function controlFor(fieldType: string, supported: boolean): ControlKind {
    if (fieldType === 'note') {
        return 'note';
    }
    if (!supported) {
        return 'unsupported';
    }
    if (TEXT_TYPES.has(fieldType)) {
        return 'text';
    }
    if (fieldType === 'long_text') {
        return 'textarea';
    }
    if (fieldType === 'integer' || fieldType === 'decimal') {
        return 'number';
    }
    if (fieldType === 'single_select' || fieldType === 'dropdown') {
        return 'select';
    }
    if (fieldType === 'multi_select') {
        return 'checkboxes';
    }
    if (fieldType === 'yes_no') {
        return 'yesno';
    }
    if (fieldType === 'likert_scale') {
        return 'scale';
    }
    if (fieldType === 'cascading_select') {
        return 'cascading';
    }
    if (fieldType === 'likert_matrix') {
        return 'likert_matrix';
    }
    if (fieldType === 'matrix') {
        return 'matrix';
    }
    if (fieldType === 'geopoint' || fieldType === 'geotrace' || fieldType === 'geoshape') {
        return 'geo';
    }
    if (fieldType === 'file_upload' || fieldType === 'image_capture' || fieldType === 'audio_capture' || fieldType === 'video_capture') {
        return 'media';
    }
    return 'unsupported';
}

/** Resolve a translated string for the current locale, falling back to the default-locale base (never blank). */
export function resolveText(base: string, translations: Record<string, string> | null, locale: string): string {
    const value = translations?.[locale];
    return value !== undefined && value !== '' ? value : base;
}

export function resolveOptional(
    base: string | null,
    translations: Record<string, string> | null,
    locale: string,
): string | null {
    if (base === null) {
        const value = translations?.[locale];
        return value !== undefined && value !== '' ? value : null;
    }
    return resolveText(base, translations, locale);
}

/**
 * Resolve a cascading hierarchy's level + option labels into the current locale, producing the flat shape the
 * shared `FieldInput.vue` cascading control consumes (Increment G4a). Used by both the flat + repeat SPA
 * adapters so translation resolution lives in one place.
 */
export function resolveCascade(
    cascade: RenderCascade | null,
    locale: string,
): { levels: { key: string; label: string }[]; options: { value: string; label: string; level: string; parent: string | null }[] } | null {
    if (cascade === null) {
        return null;
    }

    return {
        levels: cascade.levels.map((level) => ({ key: level.key, label: resolveText(level.label, level.labelTranslations, locale) })),
        options: cascade.options.map((option) => ({
            value: option.value,
            label: resolveText(option.label, option.labelTranslations, locale),
            level: option.level,
            parent: option.parent,
        })),
    };
}

function buildOptions(field: RawField): RenderOption[] {
    if (!HAS_OPTIONS.has(field.field_type)) {
        return [];
    }
    const raw = field.config?.options;
    if (!Array.isArray(raw)) {
        return [];
    }
    const out: RenderOption[] = [];
    for (const option of raw) {
        if (option === null || typeof option !== 'object' || option.value === undefined || option.value === null) {
            continue;
        }
        const value = String(option.value);
        out.push({
            value,
            label: option.label !== undefined && option.label !== null ? String(option.label) : value,
            labelTranslations: option.label_translations ?? null,
        });
    }
    return out;
}

/** The `config.options[].value` set (as strings) the engine membership check reads. Mirror of PHP's optionValueSet. */
function engineOptionValues(field: RawField): string[] {
    const raw = field.config?.options;
    if (!Array.isArray(raw)) {
        return [];
    }
    const out: string[] = [];
    for (const option of raw) {
        if (option === null || typeof option !== 'object') {
            continue;
        }
        const value = (option as { value?: unknown }).value;
        if (value === undefined || value === null) {
            continue;
        }
        out.push(String(value));
    }

    return out;
}

/** Project a cascading field's `config.levels`/`config.options` into the engine's {@link CascadeConfig}. */
function engineCascadeConfig(field: RawField): CascadeConfig {
    const levelsRaw = field.config?.levels;
    const optionsRaw = field.config?.options;

    const levels = Array.isArray(levelsRaw)
        ? levelsRaw.map((level) =>
              level !== null && typeof level === 'object' ? String((level as { key?: unknown }).key ?? '') : String(level),
          )
        : [];

    const options = Array.isArray(optionsRaw)
        ? optionsRaw.flatMap((option) => {
              if (option === null || typeof option !== 'object') {
                  return [];
              }
              const value = (option as { value?: unknown }).value;
              const level = (option as { level?: unknown }).level;
              if (value === undefined || value === null || level === undefined || level === null) {
                  return [];
              }
              const parent = (option as { parent?: unknown }).parent;

              return [
                  {
                      value: String(value),
                      level: String(level),
                      parent: parent === undefined || parent === null ? null : String(parent),
                  },
              ];
          })
        : [];

    return { levels, options };
}

/** The UI-facing cascading hierarchy (labels + translations kept for the render layer to resolve). */
function buildCascade(field: RawField): RenderCascade | null {
    if (field.field_type !== 'cascading_select') {
        return null;
    }

    const levelsRaw = field.config?.levels;
    const optionsRaw = field.config?.options;

    const levels: RenderCascadeLevel[] = Array.isArray(levelsRaw)
        ? levelsRaw.flatMap((level) => {
              if (level === null || typeof level !== 'object') {
                  return [];
              }
              const key = (level as { key?: unknown }).key;
              if (key === undefined || key === null) {
                  return [];
              }
              const label = (level as { label?: unknown }).label;
              const labelTranslations = (level as { label_translations?: Record<string, string> | null }).label_translations ?? null;

              return [{ key: String(key), label: label !== undefined && label !== null ? String(label) : String(key), labelTranslations }];
          })
        : [];

    const options: RenderCascadeOption[] = Array.isArray(optionsRaw)
        ? optionsRaw.flatMap((option) => {
              if (option === null || typeof option !== 'object') {
                  return [];
              }
              const value = (option as { value?: unknown }).value;
              const level = (option as { level?: unknown }).level;
              if (value === undefined || value === null || level === undefined || level === null) {
                  return [];
              }
              const label = (option as { label?: unknown }).label;
              const labelTranslations = (option as { label_translations?: Record<string, string> | null }).label_translations ?? null;
              const parent = (option as { parent?: unknown }).parent;

              return [
                  {
                      value: String(value),
                      label: label !== undefined && label !== null ? String(label) : String(value),
                      labelTranslations,
                      level: String(level),
                      parent: parent === undefined || parent === null ? null : String(parent),
                  },
              ];
          })
        : [];

    return { levels, options };
}

/**
 * Resolve a composite grid's row/column/cell labels into the current locale, producing the flat shape the
 * shared `FieldInput.vue` grid controls consume (Increment G4b). Used by both the flat + repeat SPA adapters
 * so translation resolution lives in one place. `cells` is empty for `likert_matrix`.
 */
export function resolveMatrix(
    matrix: RenderMatrix | null,
    locale: string,
): { rows: { value: string; label: string }[]; columns: { value: string; label: string }[]; cells: { value: string; label: string }[] } | null {
    if (matrix === null) {
        return null;
    }

    const resolve = (options: RenderOption[]): { value: string; label: string }[] =>
        options.map((option) => ({ value: option.value, label: resolveText(option.label, option.labelTranslations, locale) }));

    return { rows: resolve(matrix.rows), columns: resolve(matrix.columns), cells: resolve(matrix.cells) };
}

/** A `config.<key>` option-value list (as strings) the engine grid membership check reads. */
function configValueList(field: RawField, key: string): string[] {
    const raw = field.config?.[key];
    if (!Array.isArray(raw)) {
        return [];
    }
    const out: string[] = [];
    for (const option of raw) {
        if (option === null || typeof option !== 'object') {
            continue;
        }
        const value = (option as { value?: unknown }).value;
        if (value === undefined || value === null) {
            continue;
        }
        out.push(String(value));
    }

    return out;
}

/** Project a composite grid field's `config.rows`/`columns`/`cells` into the engine's {@link GridConfig}. */
function engineGridConfig(field: RawField): GridConfig {
    return {
        rows: configValueList(field, 'rows'),
        columns: configValueList(field, 'columns'),
        cells: configValueList(field, 'cells'),
    };
}

/** A `config.<key>` option list projected to render {value,label} pairs (labels/translations kept). */
function configOptionPairs(field: RawField, key: string): RenderOption[] {
    const raw = field.config?.[key];
    if (!Array.isArray(raw)) {
        return [];
    }
    const out: RenderOption[] = [];
    for (const option of raw) {
        if (option === null || typeof option !== 'object') {
            continue;
        }
        const value = (option as { value?: unknown }).value;
        if (value === undefined || value === null) {
            continue;
        }
        const label = (option as { label?: unknown }).label;
        out.push({
            value: String(value),
            label: label !== undefined && label !== null ? String(label) : String(value),
            labelTranslations: (option as { label_translations?: Record<string, string> | null }).label_translations ?? null,
        });
    }

    return out;
}

/** The UI-facing composite grid config (labels + translations kept for the render layer to resolve). */
function buildMatrix(field: RawField): RenderMatrix | null {
    if (field.field_type !== 'matrix' && field.field_type !== 'likert_matrix') {
        return null;
    }

    return {
        rows: configOptionPairs(field, 'rows'),
        columns: configOptionPairs(field, 'columns'),
        cells: field.field_type === 'matrix' ? configOptionPairs(field, 'cells') : [],
    };
}

/** Read a `config.<key>` value coerced to a finite number, or null. */
function configNumber(field: RawField, key: string): number | null {
    const raw = field.config?.[key];
    if (raw === null || raw === undefined || raw === '') {
        return null;
    }
    const n = Number(raw);
    return Number.isFinite(n) ? n : null;
}

/** The UI-facing geo capture config (Increment G5b2), normalised snake_case config → camelCase RenderGeo. */
function buildGeo(field: RawField): RenderGeo | null {
    if (field.field_type !== 'geopoint' && field.field_type !== 'geotrace' && field.field_type !== 'geoshape') {
        return null;
    }

    const center = field.config?.default_center;
    let defaultCenter: { lat: number; lon: number } | null = null;
    if (center !== null && typeof center === 'object' && !Array.isArray(center)) {
        const lat = Number((center as { lat?: unknown }).lat);
        const lon = Number((center as { lon?: unknown }).lon);
        if (Number.isFinite(lat) && Number.isFinite(lon)) {
            defaultCenter = { lat, lon };
        }
    }

    return {
        captureAltitude: field.config?.capture_altitude === true,
        accuracyThreshold: configNumber(field, 'accuracy_threshold'),
        defaultCenter,
        defaultZoom: configNumber(field, 'default_zoom'),
    };
}

/** The four media types wired to a renderer (Increment G6) — signature's canvas control is deferred. */
function isMediaFieldType(fieldType: string): boolean {
    return fieldType === 'file_upload' || fieldType === 'image_capture' || fieldType === 'audio_capture' || fieldType === 'video_capture';
}

/** The UI-facing media capture config (Increment G6), normalised snake_case config → camelCase RenderMedia. */
function buildMedia(field: RawField): RenderMedia | null {
    if (!isMediaFieldType(field.field_type)) {
        return null;
    }

    const acceptedRaw = field.config?.accepted_types;
    const acceptedTypes = Array.isArray(acceptedRaw) ? acceptedRaw.filter((t): t is string => typeof t === 'string') : [];
    const source = field.config?.capture_source;

    return {
        acceptedTypes,
        maxFileSizeBytes: configNumber(field, 'max_file_size_bytes'),
        maxCount: configNumber(field, 'max_count'),
        minCount: configNumber(field, 'min_count'),
        captureSource: typeof source === 'string' ? source : null,
    };
}

/** The min/max count the engine's media pass reads (Increment G6) → engine SchemaField.media. */
function engineMediaConfig(field: RawField): { maxCount: number | null; minCount: number | null } {
    return { maxCount: configNumber(field, 'max_count'), minCount: configNumber(field, 'min_count') };
}

function toRenderField(field: RawField): RenderField {
    const supported = SUPPORTED.has(field.field_type);
    const hasConditionalRequirement =
        field.is_required === 'conditional' ||
        field.validations.some((v) => v.rule_type === 'required_if' || v.rule_type === 'required_with');

    return {
        key: field.key,
        sectionKey: field.section_key,
        fieldType: field.field_type,
        control: controlFor(field.field_type, supported),
        supported,
        isRequired: field.is_required,
        hasConditionalRequirement,
        label: field.label,
        labelTranslations: field.label_translations,
        hint: field.hint,
        hintTranslations: field.hint_translations,
        placeholder: field.placeholder,
        options: buildOptions(field),
        cascade: buildCascade(field),
        matrix: buildMatrix(field),
        geo: buildGeo(field),
        media: buildMedia(field),
        sequence: field.sequence,
        sectionSequence: field.section_sequence,
    };
}

function toRenderSection(section: RawSection): RenderSection {
    return {
        key: section.key,
        label: section.label,
        labelTranslations: section.label_translations,
        description: section.description,
        descriptionTranslations: section.description_translations,
        sequence: section.sequence,
        isRepeatable: section.is_repeatable,
        minInstances: section.min_instances ?? null,
        maxInstances: section.max_instances ?? null,
        relevantExpression: section.relevant_expression,
    };
}

const bySequence = <T extends { sequence: number }>(a: T, b: T): number => a.sequence - b.sequence;

export function buildRenderModel(schema: SchemaResponse): RenderModel {
    return {
        form: schema.form,
        sections: schema.version.schema.sections.map(toRenderSection).sort(bySequence),
        fields: schema.version.schema.fields.map(toRenderField).sort(bySequence),
    };
}

/**
 * The piping SOURCE map (Increment H6b, Doc #26 §3.2) — the TypeScript twin of
 * `App\Services\Templates\TemplateSources::fromSnapshot()`: field key ⇒ its raw `field_type` and its RAW
 * `config`, which is what `displayValue()` reads to resolve a choice code to its author-defined label.
 *
 * Built from `version.schema.fields` rather than from the render model ON PURPOSE, and this is the trap
 * Doc #26 §3.2 names by hand: `toRenderField()` has already projected `config` into five presentation
 * shapes and dropped the original, and `buildOptions()` only runs for `HAS_OPTIONS` types — so a
 * `cascading_select`'s options are not on `RenderField` at all, and a renderer fed from there would emit
 * "ncr; manila" where PHP emits "Metro Manila; Manila". Re-deriving `config` would also be a SECOND
 * normalisation of the one input the two engines must agree on byte for byte.
 *
 * TOTAL over the snapshot: `hidden`, `calculated`, `note` and `page_break` are all included. Two of those
 * matter — `hidden` and `calculated` are PIPEABLE (§3.1, deliberately differing from OCR eligibility), so
 * filtering by the `SUPPORTED` set above (a different question: "types with a renderer") would silently
 * break piping's headline cases, a running total and a URL-prefilled name.
 *
 * One knowing divergence from the PHP twin: `fromSnapshot()` SKIPS a field whose `field_type` is unknown
 * to the deployment (`FieldType::tryFrom()` returning null), so its hole renders empty. There is no
 * `FieldType` enum here, so the entry is kept and `displayValue()`'s total fallback decides. Reachable
 * only when a cached SPA bundle meets a snapshot from a newer deployment; recorded, not papered over.
 */
export function buildTemplateSources(schema: SchemaResponse): RenderSources {
    const sources: RenderSources = {};

    for (const field of schema.version.schema.fields) {
        sources[field.key] = { type: field.field_type, config: field.config ?? {} };
    }

    return sources;
}

export function buildEngineSchema(schema: SchemaResponse): EngineSchema {
    const fields: SchemaField[] = schema.version.schema.fields.map((f) => ({
        id: f.key,
        key: f.key,
        sequence: f.sequence,
        field_type: f.field_type,
        is_required: f.is_required,
        form_section_id: f.section_key,
        relevant_expression: f.relevant_expression,
        // Grammar v2.0: a calculated field's formula, so the engine's compute pass can produce its value.
        calculate: typeof f.config?.calculated_formula === 'string' ? f.config.calculated_formula : null,
        // Choice-membership + cascading integrity (Increment G4a) — the engine reads only these projections
        // of `config`, never the raw editor payload, so it stays byte-identical with the PHP authority.
        options: HAS_OPTIONS.has(f.field_type) ? engineOptionValues(f) : null,
        cascade: f.field_type === 'cascading_select' ? engineCascadeConfig(f) : null,
        // Composite grid membership sets (Increment G4b) — row/column/cell VALUE keys only.
        grid: f.field_type === 'matrix' || f.field_type === 'likert_matrix' ? engineGridConfig(f) : null,
        // Media count bounds (Increment G6) — min/max attachment count for the engine's processMedia pass.
        media: isMediaFieldType(f.field_type) ? engineMediaConfig(f) : null,
    }));

    const sections: SchemaSection[] = schema.version.schema.sections.map((s) => ({
        id: s.key,
        key: s.key,
        sequence: s.sequence,
        relevant_expression: s.relevant_expression,
        // Repeat-group columns (Increment G2) — without these the F6a engine's G1 repeat pass is inert in
        // the SPA (a repeatable section would fall through to the flat top-level path).
        is_repeatable: s.is_repeatable,
        min_instances: s.min_instances ?? null,
        max_instances: s.max_instances ?? null,
    }));

    const validations: ValidationRow[] = schema.version.schema.fields.flatMap((f) =>
        f.validations.map((v, index) => ({
            id: `${f.key}:${index}`,
            form_field_id: f.key,
            sequence: v.sequence,
            rule_type: (v.rule_type as ValidationRuleType | null) ?? null,
            operator: (v.operator as ComparisonOperator | null) ?? null,
            related_form_field_id: v.related_field_key,
            rule_value: v.rule_value === null ? null : String(v.rule_value),
            expression: v.expression,
            logic_group: v.logic_group_ordinal === null ? null : String(v.logic_group_ordinal),
            logic_operator: (v.logic_operator as LogicOperator | null) ?? null,
            error_message: v.error_message,
            error_message_translations: v.error_message_translations,
        })),
    );

    return { fields, sections, validations };
}

/** Combine the static engine schema with the live answers + locale into a `SemanticInput`. */
export function toSemanticInput(engineSchema: EngineSchema, answers: AnswerMap, locale: string): SemanticInput {
    return { ...engineSchema, answers, locale };
}
